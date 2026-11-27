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
    // Nazwy portów agregujących/uplink — nie wskazują fizycznej lokalizacji klienta.
    const UPLINK_PATTERN = '/^(ethernet\s*1\/0\/1$|eth\s*1\/0\/\d+$|10ge|xe-|ge-|port-channel|po\d|vlan\d|loopback|mgmt|management)/i';

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
                    continue;
                }
                try {
                    $out[$m] = $this->lookupMac($m);
                } catch (Throwable $e) {
                    error_log('[LMSIptvErrorsPlugin] librenms: ' . $e->getMessage());
                    $this->markFailure($e->getMessage());
                    $out[$m] = $cache['map'][$m] ?? null;
                    continue;
                }
                $cache['map'][$m] = $out[$m];
                $cache['fetched'] = $now;
                $this->writeJson('librenms.json', $cache);
            } else {
                $out[$m] = $cache['map'][$m];
            }
        }
        $this->markFailure(null);
        return $out;
    }

    /**
     * Pojedynczy MAC: port z FDB (preferowany port dostępowy, nie uplink) -> device -> lokalizacja.
     *
     * @return array|null ['switch','port','location','source'] albo null gdy MAC nieznany w FDB
     */
    private function lookupMac($mac)
    {
        $ports = $this->fetchJson($this->url . '/ports?mac=' . urlencode($mac));
        $ports = $ports['data'] ?? array();
        if (!is_array($ports) || !$ports) {
            return null;
        }
        // Wybierz port dostępowy: pierwszy, którego ifName nie wygląda na uplink/agregację.
        $chosen = null;
        foreach ($ports as $p) {
            $if = (string) ($p['ifName'] ?? $p['ifDescr'] ?? '');
            if ($if !== '' && !preg_match(self::UPLINK_PATTERN, $if)) {
                $chosen = $p;
                break;
            }
        }
        if ($chosen === null) {
            $chosen = $ports[0];
        }
        $deviceId = (int) ($chosen['device_id'] ?? 0);
        $switch = '';
        $location = '';
        if ($deviceId > 0) {
            $dev = $this->fetchJson($this->url . '/devices/' . $deviceId);
            $dev = $dev['data'] ?? $dev;
            if (is_array($dev)) {
                // API v0 device: location to już nazwa lokalizacji (string); sysLocation jako fallback SNMP.
                $switch = (string) ($dev['hostname'] ?? '');
                $location = (string) ($dev['location'] ?? '');
                if ($location === '') {
                    $location = (string) ($dev['sysLocation'] ?? '');
                }
            }
        }
        return array(
            'switch' => $switch,
            'port' => (string) ($chosen['ifName'] ?? $chosen['ifDescr'] ?? ''),
            'location' => $location,
            'source' => 'librenms',
        );
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
