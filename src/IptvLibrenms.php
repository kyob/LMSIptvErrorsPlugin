<?php
/*
 * Copyright (C) 2026 Łukasz Kopiszka
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License Version 2
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 */

/**
 * Klient REST API LibreNMS (tylko odczyt): MAC dekodera -> port (FDB) -> przełącznik -> lokalizacja.
 * Włączany flagą iptv-errors.librenms_enabled — bez niej klasa nie wykonuje żadnego zapytania.
 * Token tylko w nagłówku X-Auth-Token (nie w URL, żeby nie trafił do logów).
 *
 * API v0: GET {url}/ports?mac={mac} -> porty z FDB; GET {url}/devices/{id} -> hostname + location.
 * Wyniki cache'owane per MAC w pliku JSON (TTL librenms_cache_ttl), backoff po błędzie jak w IptvMetrics.
 */
class IptvLibrenms
{
    const FORCE_MIN_AGE = 60; // sekundy: refresh nie pyta API częściej niż raz na minutę
    const FAILURE_BACKOFF = 90; // sekundy bez ponownych zapytań po błędzie źródła
    const MAX_RESPONSE_BYTES = 1048576; // 1 MB na jedno zapytanie
    const CONCURRENCY = 10; // ile równoległych requestów do API LibreNMS w batchu (curl_multi)
    // Nazwy portów agregujących/uplink — nie wskazują fizycznej lokalizacji klienta.
    // Uwaga: eth1/0/N i Ethernet1/0/N to porty DOSTĘPOWE (D-Link DGS-1510, Huawei) — nie są uplinkiem!
    const UPLINK_PATTERN = '/^(port-channel|po\d+|vlan\d+|loopback|lo\d*|mgmt|management|10ge|xe-|uplink)/i';

    private $enabled;
    private $url;
    private $token;
    private $cache_ttl;
    private $dir;
    private static $jsonCache = array();

    public function __construct()
    {
        $this->enabled = (bool) ConfigHelper::getConfig('iptv-errors.librenms_enabled', false);
        $this->url = rtrim(trim((string) ConfigHelper::getConfig('iptv-errors.librenms_url', 'https://librenms.alfa-system.net/api/v0')), '/');
        $this->token = trim((string) ConfigHelper::getConfig('iptv-errors.librenms_api_token', ''));
        $this->cache_ttl = (int) ConfigHelper::getConfig('iptv-errors.librenms_cache_ttl', 600);
        $this->dir = STORAGE_DIR . DIRECTORY_SEPARATOR . 'iptv-errors';
    }

    /**
     * Czy integracja jest włączona (flaga + token + url). Gdy false, getLocationsForMacs() zwraca pustą mapę
     * bez żadnego zapytania sieciowego.
     */
    public function enabled()
    {
        return $this->enabled && $this->token !== '' && $this->url !== '';
    }

    /**
     * Mapa mac (znormalizowany, małe litery) => lokalizacja lub null (brak w FDB / błąd).
     * Wynik: ['switch' => hostname, 'port' => ifName, 'location' => string, 'source' => 'librenms'].
     *
     * @param array $macs lista MAC-ów (dowolny format — normalizacja w środku)
     * @return array
     */
    public function getLocationsForMacs(array $macs, $force = false)
    {
        $out = array();
        if (!$this->enabled()) {
            foreach ($macs as $mac) {
                $out[IptvMetrics::normalizeMac($mac)] = null;
            }
            return $out;
        }
        $cache = $this->readJson('librenms.json');
        $cache = is_array($cache) && isset($cache['map']) && is_array($cache['map']) ? $cache : array('fetched' => 0, 'map' => array());
        $now = time();
        $force = $force && (!$cache['fetched'] || ($now - (int) $cache['fetched']) >= self::FORCE_MIN_AGE);
        $failed = $force ? null : $this->recentFailure();

        // PERF: wszystkie nieświeże MAC-i jednym batch'em równoległym (curl_multi), nie po kolei.
        // Sekwencyjnie ~100 MAC-ów × 2 requesty blokowało stronę na minuty.
        $missing = array();
        foreach ($macs as $mac) {
            $m = IptvMetrics::normalizeMac($mac);
            if ($m === '') {
                continue;
            }
            $fresh = isset($cache['map'][$m]) && $cache['fetched'] && ($now - (int) $cache['fetched']) < $this->cache_ttl;
            if ($force || !$fresh) {
                if ($failed) {
                    // Backoff aktywny: zostawiamy ostatni znany wynik (albo null), nie pytamy API.
                    $out[$m] = $cache['map'][$m] ?? null;
                } else {
                    $missing[$m] = true;
                }
            } else {
                $out[$m] = $cache['map'][$m];
            }
        }
        if ($missing) {
            try {
                $found = $this->lookupMacsBatch(array_keys($missing));
            } catch (Throwable $e) {
                error_log('[LMSIptvErrorsPlugin] librenms: ' . $e->getMessage());
                $this->markFailure($e->getMessage());
                $found = array();
            }
            foreach ($missing as $m => $_) {
                $out[$m] = $found[$m] ?? ($cache['map'][$m] ?? null);
                $cache['map'][$m] = $out[$m];
            }
            $cache['fetched'] = $now;
            $this->writeJson('librenms.json', $cache);
            $this->markFailure(null);
        }
        return $out;
    }

    /**
     * Batch równoległy (curl_multi): MAC -> port FDB -> device -> lokalizacja.
     * Dwa etapy: najpierw /ports?mac= dla wszystkich MAC-ów naraz, potem /devices/{id}
     * dla unikalnych device_id naraz. Limit współbieżności CONCURRENCY.
     *
     * @return array mac => ['switch','port','location','source'] | null
     */
    protected function lookupMacsBatch(array $macs)
    {
        // API v0 (docs.librenms.org/API/Ports): GET /ports/mac/{search} — szuka MAC w FDB, zwraca porty
        // posortowane po liczbie MAC na porcie. Przyjmuje format z dwukropkami (00:1a:79:...).
        // Uwaga: /ports?mac= NIE istnieje jako filtr — stąd wcześniejsze puste wyniki.
        $fdbUrls = array();
        foreach ($macs as $m) {
            $fdbUrls[$m] = $this->url . '/ports/mac/' . urlencode($m);
        }
        $fdbRes = $this->fetchJsonMulti($fdbUrls);
        // Zbierz wybrane wpisy per MAC + unikalne device_id do ewentualnego doprecyzowania.
        $chosenByMac = array();
        $deviceIds = array();
        foreach ($macs as $m) {
            $resp = $fdbRes[$m];
            $entries = $resp['data'] ?? ($resp['ports'] ?? ($resp['fdb'] ?? array()));
            if (!is_array($entries)) {
                $entries = array();
            }
            if (!is_array($entries) || !$entries) {
                $chosenByMac[$m] = null;
                continue;
            }
            $chosen = null;
            foreach ($entries as $p) {
                // Pola mogą siedzieć płasko albo w zagnieżdżonych 'port'/'device' — bierzemy oba warianty.
                $if = (string) ($p['ifName'] ?? $p['ifDescr'] ?? ($p['port']['ifName'] ?? ''));
                if ($if !== '' && !preg_match(self::UPLINK_PATTERN, $if)) {
                    $chosen = $p;
                    break;
                }
            }
            if ($chosen === null) {
                $chosen = $entries[0];
            }
            $chosenByMac[$m] = $chosen;
            $did = (int) ($chosen['device_id'] ?? ($chosen['device']['device_id'] ?? 0));
            if ($did > 0) {
                $deviceIds[$did] = true;
            }
        }
        $devRes = array();
        if ($deviceIds) {
            $devUrls = array();
            foreach (array_keys($deviceIds) as $did) {
                $devUrls[$did] = $this->url . '/devices/' . $did;
            }
            $devRes = $this->fetchJsonMulti($devUrls);
        }
        $out = array();
        foreach ($macs as $m) {
            $chosen = $chosenByMac[$m];
            if ($chosen === null) {
                $out[$m] = null;
                continue;
            }
            $did = (int) ($chosen['device_id'] ?? ($chosen['device']['device_id'] ?? 0));
            $switch = (string) ($chosen['device']['hostname'] ?? '');
            $location = (string) ($chosen['device']['location'] ?? '');
            $port = (string) ($chosen['ifName'] ?? $chosen['ifDescr'] ?? ($chosen['port']['ifName'] ?? ''));
            if ($did > 0 && isset($devRes[$did])) {
                // Doprecyzowanie z /devices/{id}: location to nazwa lokalizacji (string), sysLocation fallback.
                $dev = $devRes[$did]['data'] ?? $devRes[$did];
                if (is_array($dev)) {
                    if ($switch === '') {
                        $switch = (string) ($dev['hostname'] ?? '');
                    }
                    $location = (string) ($dev['location'] ?? '');
                    if ($location === '') {
                        $location = (string) ($dev['sysLocation'] ?? '');
                    }
                }
            }
            $out[$m] = array(
                'switch' => $switch,
                'port' => $port,
                'location' => $location,
                'source' => 'librenms',
            );
        }
        return $out;
    }

    /**
     * Równoległe GET-y JSON (curl_multi), limit współbieżności CONCURRENCY.
     * Klucz = wartość z mapy wejściowej (mac albo device_id).
     *
     * @param array $urls klucz => URL
     * @return array klucz => zdekodowany JSON (przy błędzie pojedynczego: klucz => ['error' => msg])
     */
    protected function fetchJsonMulti(array $urls)
    {
        if (!function_exists('curl_init') || !function_exists('curl_multi_init')) {
            // Fallback bez multi: sekwencyjnie (wolniej, ale działa).
            $out = array();
            foreach ($urls as $k => $u) {
                try {
                    $out[$k] = $this->fetchJson($u);
                } catch (Throwable $e) {
                    $out[$k] = array('error' => $e->getMessage());
                }
            }
            return $out;
        }
        $mh = curl_multi_init();
        $handles = array();
        $queue = $urls;
        $running = array();
        $out = array();
        $spawn = function () use (&$queue, &$handles, &$running, $mh) {
            while (count($running) < self::CONCURRENCY && $queue) {
                $k = key($queue);
                $u = current($queue);
                unset($queue[$k]);
                $ch = curl_init($u);
                curl_setopt_array($ch, array(
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 15,
                    CURLOPT_CONNECTTIMEOUT => 5,
                    CURLOPT_MAXFILESIZE => self::MAX_RESPONSE_BYTES,
                    CURLOPT_HTTPHEADER => array('X-Auth-Token: ' . $this->token, 'Accept: application/json'),
                ));
                curl_multi_add_handle($mh, $ch);
                $handles[(int) $ch] = array('ch' => $ch, 'key' => $k);
                $running[(int) $ch] = true;
            }
        };
        $spawn();
        do {
            curl_multi_exec($mh, $active);
            if ($active) {
                curl_multi_select($mh, 0.2);
            }
            // Zbierz zakończone handlery.
            while (($info = curl_multi_info_read($mh)) !== false) {
                $ch = $info['handle'];
                $id = (int) $ch;
                if (!isset($handles[$id])) {
                    continue;
                }
                $k = $handles[$id]['key'];
                $body = curl_multi_getcontent($ch);
                // curl_multi_info_read NIE zwraca http_code — kod bierzemy z uchwytu po zakończeniu.
                $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                $err = curl_error($ch);
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);
                unset($handles[$id], $running[$id]);
                if ($body === false || $body === '' || $code !== 200) {
                    $out[$k] = array('error' => 'HTTP ' . $code . ($err ? ': ' . $err : ''));
                } else {
                    $json = json_decode($body, true);
                    $out[$k] = is_array($json) ? $json : array('error' => 'bad json');
                }
                $spawn();
            }
        } while ($active || $running);
        curl_multi_close($mh);
        // Jeśli którykolwiek request się wyłożył — rzucamy, żeby markFailure złapał backoff.
        foreach ($out as $k => $v) {
            if (isset($v['error'])) {
                throw new RuntimeException('LibreNMS API: ' . $v['error'] . ' (' . $k . ')');
            }
        }
        return $out;
    }

    /**
     * Lista lokalizacji LibreNMS z współrzędnymi GPS (do mapy): mapa nazwa lokalizacji => ['lat'=>float,'lng'=>float].
     * Endpoint /api/v0/locations istnieje tylko w nowszych wersjach LibreNMS — gdy 404, zwracamy null
     * (wtedy widok mapy LibreNMS pokazuje samą tabelę z adresami tekstowymi). Cache jak librenms.json.
     *
     * @return array|null
     */
    public function getLocationsList($force = false)
    {
        if (!$this->enabled()) {
            return null;
        }
        $cache = $this->readJson('librenms-locations.json');
        $now = time();
        if (is_array($cache) && isset($cache['fetched'], $cache['map'])
            && ($force || ($now - (int) $cache['fetched']) < $this->cache_ttl)) {
            return $cache['map'];
        }
        try {
            $res = $this->fetchJson($this->url . '/locations');
            $map = array();
            foreach ((array) ($res['data'] ?? array()) as $loc) {
                $name = (string) ($loc['name'] ?? '');
                if ($name === '' || !isset($loc['lat'], $loc['lng']) || !is_numeric($loc['lat']) || !is_numeric($loc['lng'])) {
                    continue;
                }
                $map[$name] = array('lat' => (float) $loc['lat'], 'lng' => (float) $loc['lng']);
            }
            $this->writeJson('librenms-locations.json', array('fetched' => $now, 'map' => $map));
            return $map;
        } catch (Throwable $e) {
            // 404 (brak endpointu w tej wersji) też ląduje w backoff — nie katujemy API przy każdym renderze.
            error_log('[LMSIptvErrorsPlugin] librenms locations: ' . $e->getMessage());
            $this->markFailure($e->getMessage());
            return is_array($cache) && isset($cache['map']) ? $cache['map'] : null;
        }
    }

    private function fetchJson($url)
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('Brak rozszerzenia PHP curl');
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_MAXFILESIZE => self::MAX_RESPONSE_BYTES,
            CURLOPT_HTTPHEADER => array('X-Auth-Token: ' . $this->token, 'Accept: application/json'),
        ));
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            throw new RuntimeException('Błąd połączenia z LibreNMS API: ' . $err);
        }
        if ($code !== 200) {
            throw new RuntimeException('LibreNMS API zwróciło HTTP ' . $code);
        }
        $json = json_decode($body, true);
        if (!is_array($json)) {
            throw new RuntimeException('Nieoczekiwana odpowiedź LibreNMS API');
        }
        return $json;
    }

    private function recentFailure()
    {
        $f = $this->readJson('fail-librenms.json');
        if ($f && !empty($f['t']) && (time() - (int) $f['t']) < self::FAILURE_BACKOFF) {
            return (string) ($f['msg'] ?? 'LibreNMS niedostępny');
        }
        return null;
    }

    private function markFailure($message)
    {
        try {
            if ($message === null) {
                @unlink($this->dir . DIRECTORY_SEPARATOR . 'fail-librenms.json');
                unset(self::$jsonCache['fail-librenms.json']);
            } else {
                $this->writeJson('fail-librenms.json', array('t' => time(), 'msg' => $message));
            }
        } catch (Throwable $e) {
            error_log('[LMSIptvErrorsPlugin] ' . $e->getMessage());
        }
    }

    private function readJson($name)
    {
        if (array_key_exists($name, self::$jsonCache)) {
            return self::$jsonCache[$name];
        }
        $file = $this->dir . DIRECTORY_SEPARATOR . $name;
        $data = null;
        if (is_readable($file)) {
            $json = json_decode((string) file_get_contents($file), true);
            $data = is_array($json) ? $json : null;
        }
        self::$jsonCache[$name] = $data;
        return $data;
    }

    private function writeJson($name, array $data)
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0750, true) && !is_dir($this->dir)) {
            throw new RuntimeException('Nie można utworzyć katalogu ' . $this->dir);
        }
        $file = $this->dir . DIRECTORY_SEPARATOR . $name;
        $tmp = $file . '.' . getmypid() . '.tmp';
        if (file_put_contents($tmp, json_encode($data)) === false || !chmod($tmp, 0600) || !rename($tmp, $file)) {
            @unlink($tmp);
            throw new RuntimeException('Nie można zapisać ' . $file);
        }
        unset(self::$jsonCache[$name]);
    }
}
