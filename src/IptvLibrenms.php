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
    const EVENTLOG_LIMIT = 5000; // max wpisów eventlog na jedno pobranie (list_logs domyślnie zwraca tylko 50)
    const FLAP_WINDOW = 300; // sekundy: zmiana stanu portu w obie strony w tym oknie = flap
    const MAX_EVENT_PORTS = 300; // limit unikalnych port_id rozwiązywanych przez /ports/{id} w jednym przebiegu
    // FEAT(port-level): kolumny pobierane z /devices/{hostname}/ports — tylko te, które API v0 akceptuje
    // (ifHC*, ifDiscards, last_change itp. zwracają błąd "Invalid columns").
    const PORT_COLUMNS = 'ifName,ifOperStatus,ifAdminStatus,ifSpeed,ifDuplex,ifType,ifMtu,ifAlias,ifInErrors,ifOutErrors,ifInUcastPkts,ifOutUcastPkts,ifInOctets,ifOutOctets,ifLastChange,poll_time';
    const SYSLOG_COUNT = 2000; // max wpisów syslog na jedno urządzenie (retencja ~7 dni, pobieramy okno godzin)
    const FLAP_MAX_HOURS = 168; // 7 dni — max okno agregacji flapów (24 h i 7 d w widoku)
    const FLAP_EVENTS_KEEP = 10; // ile ostatnich zdarzeń trzymamy per port w agregacji flapów
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
            // HTTP 404 "mac not found" = dekoder nieaktywny w FDB — to null, nie błąd.
            if (isset($resp['error'])) {
                $chosenByMac[$m] = null;
                continue;
            }
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
                // /devices/{id} zwraca {"status":"ok","devices":[{...}]} — pierwszy element listy.
                $dev = $devRes[$did]['devices'][0] ?? ($devRes[$did]['data'] ?? $devRes[$did]);
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
     * @param bool $throwOnError false = błąd pojedynczego URL-a zostaje w wyniku jako ['error'=>...],
     *        bez rzucania wyjątku (używane w batchach per switch — jeden padnięty switch nie blokuje reszty)
     * @return array klucz => zdekodowany JSON (przy błędzie pojedynczego: klucz => ['error' => msg])
     */
    protected function fetchJsonMulti(array $urls, $throwOnError = true)
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
                    // Kod HTTP zachowujemy osobno — 404 "mac not found" to nie błąd, tylko brak w FDB.
                    $out[$k] = array('error' => 'HTTP ' . $code . ($err ? ': ' . $err : ''), 'code' => $code);
                } else {
                    $json = json_decode($body, true);
                    $out[$k] = is_array($json) ? $json : array('error' => 'bad json');
                }
                $spawn();
            }
        } while ($active || $running);
        curl_multi_close($mh);
        // Rzucamy tylko przy błędach "twardych" (nie 404) — 404 zostaje w wyniku i czytelnik traktuje jako null.
        if ($throwOnError) {
            foreach ($out as $k => $v) {
                if (isset($v['error']) && (int) ($v['code'] ?? 0) !== 404) {
                    throw new RuntimeException('LibreNMS API: ' . $v['error'] . ' (' . $k . ')');
                }
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

    /**
     * Zdarzenia zmiany stanu portów (up/down/flap) per switch z ostatnich N godzin — do korelacji
     * czasowej błędów IPTV ze zdarzeniami w sieci. Źródło: eventlog LibreNMS (type='interface',
     * reference=port_id), port_id -> ifName rozwiązywane batchowo przez /ports/{id}.
     * Wynik (posortowany malejąco po ts): [{ts, device, hostname, port, ifName, type, msg}],
     * gdzie type to 'up' | 'down' | 'flap' (down+up na tym samym porcie w oknie FLAP_WINDOW).
     * Cache w librenms-events.json (TTL librenms_cache_ttl), backoff jak fail-librenms.
     *
     * @param int $hours ile godzin wstecz (1..720)
     * @param bool $force wymuś odświeżenie cache (nie częściej niż FORCE_MIN_AGE)
     * @return array
     */
    public function getRecentPortEvents($hours = 24, $force = false)
    {
        if (!$this->enabled()) {
            return array();
        }
        $hours = max(1, min(720, (int) $hours));
        $cache = $this->readJson('librenms-events.json');
        $cache = is_array($cache) && isset($cache['events']) && is_array($cache['events'])
            ? $cache
            : array('fetched' => 0, 'hours' => 0, 'events' => array());
        $now = time();
        $force = $force && (!$cache['fetched'] || ($now - (int) $cache['fetched']) >= self::FORCE_MIN_AGE);
        // Cache liczy się tylko gdy pokrywa żądany zakres godzin.
        $fresh = !$force && $cache['fetched'] && ($now - (int) $cache['fetched']) < $this->cache_ttl
            && (int) ($cache['hours'] ?? 0) >= $hours;
        if ($fresh) {
            return $this->eventsWithinHours($cache['events'], $hours, $now);
        }
        $failed = $force ? null : $this->recentFailure();
        if ($failed) {
            // Backoff aktywny: zwracamy ostatni znany cache, nie pytamy API.
            return $this->eventsWithinHours($cache['events'], $hours, $now);
        }
        try {
            $events = $this->fetchPortEvents($hours);
            $this->writeJson('librenms-events.json', array('fetched' => $now, 'hours' => $hours, 'events' => $events));
            $this->markFailure(null);
            return $this->eventsWithinHours($events, $hours, $now);
        } catch (Throwable $e) {
            error_log('[LMSIptvErrorsPlugin] librenms events: ' . $e->getMessage());
            $this->markFailure($e->getMessage());
            return $this->eventsWithinHours($cache['events'], $hours, $now);
        }
    }

    /**
     * Pobranie i złożenie zdarzeń portowych: GET /logs/eventlog?from=...&limit=... (w tej wersji
     * LibreNMS eventlog siedzi pod /logs/eventlog, NIE /eventlog — list_logs z api_functions.inc.php),
     * potem batch /ports/{id} dla unikalnych reference (port_id) żeby dostać ifName.
     *
     * @return array surowa lista zdarzeń (ts, device, hostname, port, ifName, type, msg)
     */
    protected function fetchPortEvents($hours)
    {
        $from = date('Y-m-d H:i:s', time() - $hours * 3600);
        $res = $this->fetchJson($this->url . '/logs/eventlog?' . http_build_query(array(
            'from' => $from,
            'limit' => self::EVENTLOG_LIMIT,
            'sortorder' => 'desc',
        )));
        $rows = $res['logs'] ?? ($res['data'] ?? array());
        if (!is_array($rows)) {
            $rows = array();
        }
        $events = array();
        $portIds = array();
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $type = (string) ($row['type'] ?? '');
            $msg = (string) ($row['message'] ?? '');
            $ref = (int) ($row['reference'] ?? 0);
            // Zmiana stanu portu: type='interface' z reference=port_id; komunikat niesie "up"/"down".
            $isPortEvent = ($type === 'interface' && $ref > 0)
                || ($ref > 0 && preg_match('/\b(interface|port)\b/i', $msg));
            if (!$isPortEvent || $msg === '') {
                continue;
            }
            if (preg_match('/\bdown\b/i', $msg)) {
                $state = 'down';
            } elseif (preg_match('/\bup\b/i', $msg)) {
                $state = 'up';
            } else {
                continue; // wpis interfejsu bez zmiany stanu (np. opis) — nieistotny dla korelacji
            }
            $ts = strtotime((string) ($row['datetime'] ?? '')) ?: null;
            if (!$ts) {
                continue;
            }
            $events[] = array(
                'ts' => $ts,
                'device' => (int) ($row['device_id'] ?? 0),
                'hostname' => (string) ($row['hostname'] ?? ($row['sysName'] ?? '')),
                'port' => $ref,
                'ifName' => '',
                'type' => $state,
                'msg' => $msg,
            );
            $portIds[$ref] = true;
        }
        // Mapowanie port_id -> ifName batchowo (curl_multi); przy twardym błędzie zostajemy przy pustych ifName.
        $names = array();
        $ids = array_keys($portIds);
        if ($ids) {
            $ids = array_slice($ids, 0, self::MAX_EVENT_PORTS);
            $urls = array();
            foreach ($ids as $pid) {
                $urls[$pid] = $this->url . '/ports/' . $pid;
            }
            try {
                $portRes = $this->fetchJsonMulti($urls);
                foreach ($portRes as $pid => $resp) {
                    if (isset($resp['error'])) {
                        continue;
                    }
                    // get_port_info: {"status":"ok","port":[{...}]} — pierwszy element listy.
                    $p = $resp['port'][0] ?? ($resp['data'][0] ?? ($resp['ports'][0] ?? null));
                    if (is_array($p) && (string) ($p['ifName'] ?? '') !== '') {
                        $names[(int) $pid] = (string) $p['ifName'];
                    }
                }
            } catch (Throwable $e) {
                error_log('[LMSIptvErrorsPlugin] librenms events: porty niedostępne: ' . $e->getMessage());
            }
        }
        foreach ($events as $i => $ev) {
            if (isset($names[$ev['port']])) {
                $events[$i]['ifName'] = $names[$ev['port']];
            } elseif ($events[$i]['ifName'] === '') {
                // Fallback: ifName często siedzi w samym komunikacie ("Interface Gi1/0/1 ... up").
                if (preg_match('/\b([A-Za-z]+[\d\/\.\-:]+)\b.*\b(up|down)\b/i', $ev['msg'], $m)) {
                    $events[$i]['ifName'] = $m[1];
                } else {
                    $events[$i]['ifName'] = '#' . $ev['port'];
                }
            }
        }
        return $this->markFlaps($events);
    }

    /**
     * Oznacza flapy: jeśli na tym samym porcie (device+port) wystąpiła zmiana stanu w przeciwną
     * stronę w oknie FLAP_WINDOW sekund, oba wpisy dostają type='flap'.
     *
     * @return array
     */
    private function markFlaps(array $events)
    {
        $byKey = array();
        foreach ($events as $i => $ev) {
            $byKey[$ev['device'] . ':' . $ev['port']][] = $i;
        }
        foreach ($byKey as $idxs) {
            $n = count($idxs);
            for ($a = 0; $a < $n; $a++) {
                for ($b = $a + 1; $b < $n; $b++) {
                    $ia = $idxs[$a];
                    $ib = $idxs[$b];
                    if (abs($events[$ia]['ts'] - $events[$ib]['ts']) > self::FLAP_WINDOW) {
                        break; // dalej tylko większe odległości
                    }
                    if ($events[$ia]['type'] !== $events[$ib]['type']) {
                        $events[$ia]['type'] = 'flap';
                        $events[$ib]['type'] = 'flap';
                    }
                }
            }
        }
        return $events;
    }

    /**
     * Wycina zdarzenia starsze niż N godzin i sortuje malejąco po ts (najnowsze pierwsze).
     *
     * @return array
     */
    private function eventsWithinHours(array $events, $hours, $now)
    {
        $cutoff = $now - $hours * 3600;
        $out = array();
        foreach ($events as $ev) {
            if ((int) ($ev['ts'] ?? 0) >= $cutoff) {
                $out[] = $ev;
            }
        }
        usort($out, function ($a, $b) {
            return (int) $b['ts'] - (int) $a['ts'];
        });
        return $out;
    }

    /**
     * FEAT(port-level): statystyki portów per switch — GET /devices/{hostname}/ports?columns=... (batch
     * curl_multi). UWAGA: /ports?device_id= NIE filtruje (zwraca wszystkie porty) — stąd ścieżka per hostname.
     * Wynik: mapa hostname (małe litery) => ['device_id'=>int, 'ports'=>[znormalizowany port, ...]] albo null
     * (błąd/brak urządzenia). Port: port_id, ifName, oper, admin, speed (bps), duplex, alias, in_errors,
     * out_errors, last_change, poll_time. Cache librenms-portstats.json (TTL librenms_cache_ttl),
     * backoff fail-librenms.json jak w getLocationsForMacs.
     *
     * @param array $hostnames nazwy switchy (hostname z LibreNMS)
     * @param bool $force wymuś odświeżenie cache (nie częściej niż FORCE_MIN_AGE)
     * @return array
     */
    public function getPortStatsForDevices(array $hostnames, $force = false)
    {
        $hosts = array();
        foreach ($hostnames as $h) {
            $h = mb_strtolower(trim((string) $h));
            if ($h !== '') {
                $hosts[$h] = true;
            }
        }
        if (!$this->enabled() || !$hosts) {
            return array();
        }
        $cache = $this->readJson('librenms-portstats.json');
        $cache = is_array($cache) && isset($cache['hosts']) && is_array($cache['hosts'])
            ? $cache
            : array('fetched' => 0, 'hosts' => array());
        $now = time();
        $force = $force && (!$cache['fetched'] || ($now - (int) $cache['fetched']) >= self::FORCE_MIN_AGE);
        $fresh_all = !$force && $cache['fetched'] && ($now - (int) $cache['fetched']) < $this->cache_ttl;
        $failed = $force ? null : $this->recentFailure();

        $out = array();
        $missing = array();
        foreach (array_keys($hosts) as $h) {
            if ($fresh_all && isset($cache['hosts'][$h])) {
                $out[$h] = $cache['hosts'][$h];
            } elseif ($failed) {
                // Backoff aktywny: ostatni znany wynik (albo null), bez pytania API.
                $out[$h] = $cache['hosts'][$h] ?? null;
            } else {
                $missing[$h] = true;
            }
        }
        if ($missing) {
            $urls = array();
            foreach (array_keys($missing) as $h) {
                $urls[$h] = $this->url . '/devices/' . rawurlencode($h) . '/ports?columns=' . self::PORT_COLUMNS;
            }
            // throwOnError=false: jeden niedostępny switch nie zdejmuje wyników dla pozostałych.
            $res = $this->fetchJsonMulti($urls, false);
            $hard_errors = 0;
            foreach ($missing as $h => $_) {
                $resp = $res[$h] ?? array('error' => 'brak odpowiedzi');
                if (isset($resp['error'])) {
                    // 404 = brak takiego urządzenia — null, nie błąd. Inne kody logujemy.
                    if ((int) ($resp['code'] ?? 0) !== 404) {
                        $hard_errors++;
                        error_log('[LMSIptvErrorsPlugin] librenms portstats: ' . $h . ': ' . $resp['error']);
                    }
                    $out[$h] = $cache['hosts'][$h] ?? null;
                    $cache['hosts'][$h] = $out[$h];
                    continue;
                }
                $ports_raw = $resp['ports'] ?? ($resp['data'] ?? array());
                if (!is_array($ports_raw)) {
                    $ports_raw = array();
                }
                $device_id = 0;
                $ports = array();
                foreach ($ports_raw as $p) {
                    if (!is_array($p)) {
                        continue;
                    }
                    if ($device_id === 0) {
                        $device_id = (int) ($p['device_id'] ?? 0);
                    }
                    $ports[] = array(
                        'port_id' => (int) ($p['port_id'] ?? 0),
                        'ifName' => (string) ($p['ifName'] ?? ''),
                        'oper' => (string) ($p['ifOperStatus'] ?? ''),
                        'admin' => (string) ($p['ifAdminStatus'] ?? ''),
                        'speed' => (int) ($p['ifSpeed'] ?? 0),
                        'duplex' => (string) ($p['ifDuplex'] ?? ''),
                        'alias' => (string) ($p['ifAlias'] ?? ''),
                        'in_errors' => (int) ($p['ifInErrors'] ?? 0),
                        'out_errors' => (int) ($p['ifOutErrors'] ?? 0),
                        'last_change' => (string) ($p['ifLastChange'] ?? ''),
                        'poll_time' => (string) ($p['poll_time'] ?? ''),
                    );
                }
                $out[$h] = array('device_id' => $device_id, 'ports' => $ports);
                $cache['hosts'][$h] = $out[$h];
            }
            $cache['fetched'] = $now;
            try {
                $this->writeJson('librenms-portstats.json', $cache);
            } catch (Throwable $e) {
                error_log('[LMSIptvErrorsPlugin] ' . $e->getMessage());
            }
            // Backoff tylko gdy padło wszystko (żaden switch nie odpowiedział poprawnie).
            if ($hard_errors === count($missing)) {
                $this->markFailure('LibreNMS portstats niedostępny dla wszystkich hostów');
            } else {
                $this->markFailure(null);
            }
        }
        return $out;
    }

    /**
     * FEAT(port-level): flapy portów per switch — eventlog type='interface' (reference = port_id),
     * GET /logs/eventlog/{hostname} per switch (batch). Agregacja per reference: liczba zdarzeń
     * w oknie $hours + ostatnie zdarzenia. Cache librenms-flaps.json trzyma surowe zdarzenia
     * z najszerszego dotąd okna ('hours' w cache) — świeży gdy pokrywa żądane $hours.
     * Wynik: mapa hostname => ['total'=>int, 'by_port'=>[port_id=>['count'=>int,'last_ts'=>int,'events'=>[...]]]]
     *
     * @param array $hostnames
     * @param int $hours okno wstecz (1..168)
     * @param bool $force
     * @return array
     */
    public function getPortFlaps(array $hostnames, $hours = 24, $force = false)
    {
        $hours = max(1, min(self::FLAP_MAX_HOURS, (int) $hours));
        $hosts = array();
        foreach ($hostnames as $h) {
            $h = mb_strtolower(trim((string) $h));
            if ($h !== '') {
                $hosts[$h] = true;
            }
        }
        if (!$this->enabled() || !$hosts) {
            return array();
        }
        $cache = $this->readJson('librenms-flaps.json');
        $cache = is_array($cache) && isset($cache['hosts']) && is_array($cache['hosts'])
            ? $cache
            : array('fetched' => 0, 'hours' => 0, 'hosts' => array());
        $now = time();
        $force = $force && (!$cache['fetched'] || ($now - (int) $cache['fetched']) >= self::FORCE_MIN_AGE);
        // Cache liczy się tylko gdy pokrywa żądany zakres godzin (jak getRecentPortEvents).
        $fresh_all = !$force && $cache['fetched'] && ($now - (int) $cache['fetched']) < $this->cache_ttl
            && (int) ($cache['hours'] ?? 0) >= $hours;
        $failed = $force ? null : $this->recentFailure();

        $out = array();
        $missing = array();
        foreach (array_keys($hosts) as $h) {
            if ($fresh_all && isset($cache['hosts'][$h])) {
                $out[$h] = $this->aggregateFlaps($cache['hosts'][$h]['events'] ?? array(), $hours, $now);
            } elseif ($failed) {
                $out[$h] = $this->aggregateFlaps($cache['hosts'][$h]['events'] ?? array(), $hours, $now);
            } else {
                $missing[$h] = true;
            }
        }
        if ($missing) {
            $from = date('Y-m-d H:i:s', $now - max($hours, (int) ($cache['hours'] ?? 0) ?: $hours) * 3600);
            $urls = array();
            foreach (array_keys($missing) as $h) {
                $urls[$h] = $this->url . '/logs/eventlog/' . rawurlencode($h) . '?' . http_build_query(array(
                    'from' => $from,
                    'limit' => self::EVENTLOG_LIMIT,
                    'type' => 'interface',
                    'sortorder' => 'desc',
                ));
            }
            $res = $this->fetchJsonMulti($urls, false);
            foreach ($missing as $h => $_) {
                $resp = $res[$h] ?? array('error' => 'brak odpowiedzi');
                if (isset($resp['error'])) {
                    if ((int) ($resp['code'] ?? 0) !== 404) {
                        error_log('[LMSIptvErrorsPlugin] librenms flaps: ' . $h . ': ' . $resp['error']);
                    }
                    $cache['hosts'][$h] = $cache['hosts'][$h] ?? array('events' => array());
                } else {
                    $rows = $resp['logs'] ?? ($resp['data'] ?? array());
                    if (!is_array($rows)) {
                        $rows = array();
                    }
                    $events = array();
                    foreach ($rows as $row) {
                        if (!is_array($row)) {
                            continue;
                        }
                        $ref = (int) ($row['reference'] ?? 0);
                        $msg = (string) ($row['message'] ?? '');
                        if ($ref <= 0 || $msg === '') {
                            continue;
                        }
                        if (preg_match('/\bdown\b/i', $msg)) {
                            $state = 'down';
                        } elseif (preg_match('/\bup\b/i', $msg)) {
                            $state = 'up';
                        } else {
                            continue;
                        }
                        $ts = strtotime((string) ($row['datetime'] ?? '')) ?: 0;
                        if (!$ts) {
                            continue;
                        }
                        $events[] = array('ts' => $ts, 'port' => $ref, 'state' => $state, 'msg' => $msg);
                    }
                    $cache['hosts'][$h] = array('events' => $events);
                }
                $out[$h] = $this->aggregateFlaps($cache['hosts'][$h]['events'] ?? array(), $hours, $now);
            }
            $cache['fetched'] = $now;
            $cache['hours'] = max($hours, (int) ($cache['hours'] ?? 0));
            try {
                $this->writeJson('librenms-flaps.json', $cache);
                $this->markFailure(null);
            } catch (Throwable $e) {
                error_log('[LMSIptvErrorsPlugin] ' . $e->getMessage());
            }
        }
        return $out;
    }

    /**
     * Agregacja surowych zdarzeń eventlog per port_id w oknie $hours: count + ostatnie zdarzenia.
     *
     * @return array ['total'=>int, 'by_port'=>[port_id=>['count','last_ts','events']]]
     */
    private function aggregateFlaps(array $events, $hours, $now)
    {
        $cutoff = $now - $hours * 3600;
        $by_port = array();
        $total = 0;
        foreach ($events as $ev) {
            if ((int) ($ev['ts'] ?? 0) < $cutoff) {
                continue;
            }
            $total++;
            $pid = (int) ($ev['port'] ?? 0);
            if (!isset($by_port[$pid])) {
                $by_port[$pid] = array('count' => 0, 'last_ts' => 0, 'events' => array());
            }
            $by_port[$pid]['count']++;
            if ((int) $ev['ts'] > $by_port[$pid]['last_ts']) {
                $by_port[$pid]['last_ts'] = (int) $ev['ts'];
            }
            if (count($by_port[$pid]['events']) < self::FLAP_EVENTS_KEEP) {
                $by_port[$pid]['events'][] = $ev;
            }
        }
        // events w agregacji sortujemy malejąco po ts (najnowsze pierwsze)
        foreach ($by_port as $pid => $agg) {
            usort($by_port[$pid]['events'], function ($a, $b) {
                return (int) $b['ts'] - (int) $a['ts'];
            });
        }
        return array('total' => $total, 'by_port' => $by_port);
    }

    /**
     * FEAT(port-level): ostatnie wpisy syslog link up/down per switch.
     * Filtr per urządzenie: parametr device_id (ścieżka /logs/syslog/{hostname} zwraca pusto —
     * list_syslog filtruje po device_id, nie po hostname w ścieżce). device_id bierzemy z cache
     * portstats albo z batchu GET /devices/{hostname}.
     * Komunikat parsujemy: "Port 1:22 link up, 1000Mbps FULL duplex" -> port/state/speed/duplex.
     * Wynik: mapa hostname => ['device_id'=>int, 'events'=>[{ts,port,state,speed,duplex,msg}...]]
     * (events w oknie $hours, malejąco po ts). Cache librenms-syslog.json.
     *
     * @param array $hostnames
     * @param int $hours okno wstecz (1..168)
     * @param bool $force
     * @return array
     */
    public function getPortSyslog(array $hostnames, $hours = 24, $force = false)
    {
        $hours = max(1, min(self::FLAP_MAX_HOURS, (int) $hours));
        $hosts = array();
        foreach ($hostnames as $h) {
            $h = mb_strtolower(trim((string) $h));
            if ($h !== '') {
                $hosts[$h] = true;
            }
        }
        if (!$this->enabled() || !$hosts) {
            return array();
        }
        $cache = $this->readJson('librenms-syslog.json');
        $cache = is_array($cache) && isset($cache['hosts']) && is_array($cache['hosts'])
            ? $cache
            : array('fetched' => 0, 'hours' => 0, 'hosts' => array());
        $now = time();
        $force = $force && (!$cache['fetched'] || ($now - (int) $cache['fetched']) >= self::FORCE_MIN_AGE);
        $fresh_all = !$force && $cache['fetched'] && ($now - (int) $cache['fetched']) < $this->cache_ttl
            && (int) ($cache['hours'] ?? 0) >= $hours;
        $failed = $force ? null : $this->recentFailure();

        $out = array();
        $missing = array();
        foreach (array_keys($hosts) as $h) {
            if ($fresh_all && isset($cache['hosts'][$h])) {
                $out[$h] = $this->syslogWithinHours($cache['hosts'][$h], $hours, $now);
            } elseif ($failed) {
                $out[$h] = $this->syslogWithinHours($cache['hosts'][$h] ?? array('device_id' => 0, 'events' => array()), $hours, $now);
            } else {
                $missing[$h] = true;
            }
        }
        if ($missing) {
            // device_id per host: najpierw z cache portstats (tam już jest), brakujące dopiero przez /devices/{h}.
            $stats_cache = $this->readJson('librenms-portstats.json');
            $stats_hosts = is_array($stats_cache) && isset($stats_cache['hosts']) && is_array($stats_cache['hosts'])
                ? $stats_cache['hosts'] : array();
            $device_ids = array();
            $need_dev = array();
            foreach (array_keys($missing) as $h) {
                $did = (int) ($stats_hosts[$h]['device_id'] ?? 0);
                if ($did > 0) {
                    $device_ids[$h] = $did;
                } else {
                    $need_dev[$h] = true;
                }
            }
            if ($need_dev) {
                $devUrls = array();
                foreach (array_keys($need_dev) as $h) {
                    $devUrls[$h] = $this->url . '/devices/' . rawurlencode($h);
                }
                $devRes = $this->fetchJsonMulti($devUrls, false);
                foreach ($need_dev as $h => $_) {
                    $resp = $devRes[$h] ?? array('error' => 'brak odpowiedzi');
                    if (!isset($resp['error'])) {
                        $dev = $resp['devices'][0] ?? ($resp['data'][0] ?? null);
                        if (is_array($dev)) {
                            $device_ids[$h] = (int) ($dev['device_id'] ?? 0);
                        }
                    }
                    if (!isset($device_ids[$h])) {
                        $device_ids[$h] = 0;
                    }
                }
            }
            $from = date('Y-m-d H:i:s', $now - $hours * 3600);
            $urls = array();
            foreach (array_keys($missing) as $h) {
                $did = (int) ($device_ids[$h] ?? 0);
                if ($did <= 0) {
                    continue; // bez device_id nie ma filtra per urządzenie — pomijamy, żeby nie lać globalnym syslogiem
                }
                $urls[$h] = $this->url . '/logs/syslog?' . http_build_query(array(
                    'device_id' => $did,
                    'from' => $from,
                    'count' => self::SYSLOG_COUNT,
                    'sortorder' => 'desc',
                ));
            }
            $res = $urls ? $this->fetchJsonMulti($urls, false) : array();
            foreach ($missing as $h => $_) {
                $did = (int) ($device_ids[$h] ?? 0);
                if ($did <= 0 || !isset($res[$h]) || isset($res[$h]['error'])) {
                    if (isset($res[$h]['error']) && (int) ($res[$h]['code'] ?? 0) !== 404) {
                        error_log('[LMSIptvErrorsPlugin] librenms syslog: ' . $h . ': ' . $res[$h]['error']);
                    }
                    $cache['hosts'][$h] = $cache['hosts'][$h] ?? array('device_id' => $did, 'events' => array());
                } else {
                    $rows = $res[$h]['logs'] ?? ($res[$h]['data'] ?? array());
                    if (!is_array($rows)) {
                        $rows = array();
                    }
                    $events = array();
                    foreach ($rows as $row) {
                        if (!is_array($row)) {
                            continue;
                        }
                        $msg = (string) ($row['msg'] ?? ($row['message'] ?? ''));
                        $parsed = $this->parseSyslogLink($msg);
                        if ($parsed === null) {
                            continue; // nie link up/down — nieistotne dla diagnostyki portów
                        }
                        $ts = strtotime((string) ($row['timestamp'] ?? '')) ?: 0;
                        if (!$ts) {
                            continue;
                        }
                        $events[] = array(
                            'ts' => $ts,
                            'port' => $parsed['port'],
                            'state' => $parsed['state'],
                            'speed' => $parsed['speed'],
                            'duplex' => $parsed['duplex'],
                            'msg' => $msg,
                        );
                    }
                    $cache['hosts'][$h] = array('device_id' => $did, 'events' => $events);
                }
                $out[$h] = $this->syslogWithinHours($cache['hosts'][$h], $hours, $now);
            }
            $cache['fetched'] = $now;
            $cache['hours'] = max($hours, (int) ($cache['hours'] ?? 0));
            try {
                $this->writeJson('librenms-syslog.json', $cache);
                $this->markFailure(null);
            } catch (Throwable $e) {
                error_log('[LMSIptvErrorsPlugin] ' . $e->getMessage());
            }
        }
        return $out;
    }

    /**
     * Parsuje komunikat syslog "Port 1:22 link up, 1000Mbps FULL duplex" do struktury
     * ['port','state','speed','duplex'] albo null gdy to nie jest link up/down.
     *
     * @return array|null
     */
    private function parseSyslogLink($msg)
    {
        if (!preg_match('/port\s+([0-9]+(?:[\/:.][0-9]+)*)\s*:?\s*link\s+(up|down)(?:,\s*(\d+)\s*mbps)?(?:\s+(\S+)\s+duplex)?/i', $msg, $m)) {
            return null;
        }
        return array(
            'port' => $m[1],
            'state' => strtolower($m[2]),
            'speed' => isset($m[3]) && $m[3] !== '' ? (int) $m[3] : 0, // Mbps
            'duplex' => isset($m[4]) ? strtoupper($m[4]) : '',
        );
    }

    /**
     * Wycina zdarzenia syslog starsze niż N godzin i sortuje malejąco po ts.
     *
     * @return array ['device_id'=>int, 'events'=>[...]]
     */
    private function syslogWithinHours(array $host, $hours, $now)
    {
        $cutoff = $now - $hours * 3600;
        $events = array();
        foreach ((array) ($host['events'] ?? array()) as $ev) {
            if ((int) ($ev['ts'] ?? 0) >= $cutoff) {
                $events[] = $ev;
            }
        }
        usort($events, function ($a, $b) {
            return (int) $b['ts'] - (int) $a['ts'];
        });
        return array('device_id' => (int) ($host['device_id'] ?? 0), 'events' => $events);
    }

    /**
     * FEAT(port-level): FDB per MAC — GET /resources/fdb/{mac}/detail (batch curl_multi).
     * Wykrywa MAC widziany na więcej niż jednym porcie/switchu (stale FDB / flapping MAC) — flaga 'multi'.
     * Wynik: mapa mac (znormalizowany) => ['ports'=>[{hostname,ifName,ifAlias,updated_at}...], 'multi'=>bool]
     * albo null (MAC nieobecny w FDB). Cache librenms-fdb.json (TTL librenms_cache_ttl).
     *
     * @param array $macs
     * @param bool $force
     * @return array
     */
    public function getFdbDetailForMacs(array $macs, $force = false)
    {
        $out = array();
        if (!$this->enabled()) {
            foreach ($macs as $mac) {
                $out[IptvMetrics::normalizeMac($mac)] = null;
            }
            return $out;
        }
        $list = array();
        foreach ($macs as $mac) {
            $m = IptvMetrics::normalizeMac($mac);
            if ($m !== '') {
                $list[$m] = true;
            }
        }
        if (!$list) {
            return array();
        }
        $cache = $this->readJson('librenms-fdb.json');
        $cache = is_array($cache) && isset($cache['map']) && is_array($cache['map'])
            ? $cache
            : array('fetched' => 0, 'map' => array());
        $now = time();
        $force = $force && (!$cache['fetched'] || ($now - (int) $cache['fetched']) >= self::FORCE_MIN_AGE);
        $failed = $force ? null : $this->recentFailure();

        $missing = array();
        foreach (array_keys($list) as $m) {
            $fresh = isset($cache['map'][$m]) && $cache['fetched'] && ($now - (int) $cache['fetched']) < $this->cache_ttl;
            if ($force || !$fresh) {
                if ($failed) {
                    $out[$m] = $cache['map'][$m] ?? null;
                } else {
                    $missing[$m] = true;
                }
            } else {
                $out[$m] = $cache['map'][$m];
            }
        }
        if ($missing) {
            $urls = array();
            foreach (array_keys($missing) as $m) {
                $urls[$m] = $this->url . '/resources/fdb/' . urlencode($m) . '/detail';
            }
            $res = $this->fetchJsonMulti($urls, false);
            foreach ($missing as $m => $_) {
                $resp = $res[$m] ?? array('error' => 'brak odpowiedzi');
                if (isset($resp['error'])) {
                    if ((int) ($resp['code'] ?? 0) !== 404) {
                        error_log('[LMSIptvErrorsPlugin] librenms fdb: ' . $m . ': ' . $resp['error']);
                    }
                    // 404 = MAC nieobecny w FDB — null, nie błąd.
                    $out[$m] = $cache['map'][$m] ?? null;
                } else {
                    $rows = $resp['ports_fdb'] ?? ($resp['data'] ?? array());
                    if (!is_array($rows)) {
                        $rows = array();
                    }
                    $ports = array();
                    $seen = array();
                    foreach ($rows as $r) {
                        if (!is_array($r)) {
                            continue;
                        }
                        $host = (string) ($r['hostname'] ?? ($r['sysName'] ?? ''));
                        $if = (string) ($r['ifName'] ?? '');
                        $ports[] = array(
                            'hostname' => $host,
                            'ifName' => $if,
                            'ifAlias' => (string) ($r['ifAlias'] ?? ''),
                            'updated_at' => (string) ($r['updated_at'] ?? ''),
                        );
                        if ($host !== '' || $if !== '') {
                            $seen[mb_strtolower($host . '|' . $if)] = true;
                        }
                    }
                    $out[$m] = array('ports' => $ports, 'multi' => count($seen) > 1);
                }
                $cache['map'][$m] = $out[$m];
            }
            $cache['fetched'] = $now;
            try {
                $this->writeJson('librenms-fdb.json', $cache);
                $this->markFailure(null);
            } catch (Throwable $e) {
                error_log('[LMSIptvErrorsPlugin] ' . $e->getMessage());
            }
        }
        return $out;
    }

    protected function fetchJson($url)
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
