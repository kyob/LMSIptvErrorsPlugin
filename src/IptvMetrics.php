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
 * Klient API metryk IPTV (OpenMetrics) + cache + historia w pliku JSON.
 * Klucz API tylko w nagłówku X-API-Key (nie w adresie URL, żeby nie trafił do logów).
 */
class IptvMetrics
{
    const DEFAULT_URL = 'https://device-errors-api.sgt.net.pl/api/metrics';
    const FORCE_MIN_AGE = 15; // sekundy: ?refresh nie pyta API częściej (metryki, zdarzenia)
    const FORCE_MIN_AGE_STB = 60; // jw. dla rejestru dekoderów z SMS
    const FAILURE_BACKOFF = 90; // sekundy bez ponownych zapytań po błędzie źródła
    const HISTORY_KEEP = 288; // awaryjny limit liczby migawek (normalnie mieści się w HISTORY_TTL)
    const HISTORY_TTL = 86400; // retencja historii po czasie: migawki starsze niż 24 h są odrzucane
    const MAX_RESPONSE_BYTES = 1048576; // 1 MB: limit rozmiaru odpowiedzi API
    const DEVICES_TOP_LIMIT = 100; // ile MAC-ów trafia do pola devices_top jednej migawki
    const DEVICE_ANOMALY_MAX = 1000; // errors powyżej tej wartości to odstająca, nie błąd (jak w widoku dekoderów)

    private $url;
    private $key;
    private $cache_ttl;
    private $history_interval;
    private $dir;
    private static $jsonCache = array(); // cache plików JSON w ramach jednego requestu

    public function __construct()
    {
        $this->url = ConfigHelper::getConfig('iptv-errors.url', self::DEFAULT_URL);
        $this->key = trim((string) ConfigHelper::getConfig('iptv-errors.api_key', ''));
        $this->cache_ttl = (int) ConfigHelper::getConfig('iptv-errors.cache_ttl', 30);
        $this->history_interval = (int) ConfigHelper::getConfig('iptv-errors.history_interval', 300);
        $this->dir = STORAGE_DIR . DIRECTORY_SEPARATOR . 'iptv-errors';
    }

    public function hasKey()
    {
        return $this->key !== '';
    }

    /**
     * @return array ['data' => parsed|null, 'fetched' => ts|null, 'stale' => bool, 'error' => string|null]
     */
    public function get($force = false)
    {
        $cache = $this->readJson('cache.json');
        $now = time();
        $force = $force && (!$cache || ($now - (int) $cache['fetched']) >= self::FORCE_MIN_AGE);
        if (!$force && $cache && ($now - (int) $cache['fetched']) < $this->cache_ttl) {
            return array('data' => $cache['data'], 'fetched' => (int) $cache['fetched'], 'stale' => false, 'error' => null);
        }
        if (!$force && ($failed = $this->recentFailure('metrics'))) {
            return array('data' => $cache['data'] ?? null, 'fetched' => isset($cache['fetched']) ? (int) $cache['fetched'] : null, 'stale' => (bool) $cache, 'error' => $failed);
        }
        try {
            $text = $this->fetch();
            $data = self::parse($text);
            $cache = array('fetched' => $now, 'data' => $data);
            $this->writeJson('cache.json', $cache);
            $this->recordHistory($data, $now);
            $this->markFailure('metrics', null);
            return array('data' => $data, 'fetched' => $now, 'stale' => false, 'error' => null);
        } catch (Throwable $e) {
            error_log('[LMSIptvErrorsPlugin] ' . $e->getMessage());
            $this->markFailure('metrics', $e->getMessage());
            if ($cache) {
                return array('data' => $cache['data'], 'fetched' => (int) $cache['fetched'], 'stale' => true, 'error' => $e->getMessage());
            }
            return array('data' => null, 'fetched' => null, 'stale' => false, 'error' => $e->getMessage());
        }
    }

    /**
     * Ostatnie zdarzenia błędów per dekoder (GET /api/errors, ok. 200 rekordów: MAC, IP, model, grupa, liczba błędów).
     *
     * @return array ['events' => list, 'fetched' => ts|null, 'error' => string|null]
     */
    public function getEvents($force = false)
    {
        $cache = $this->readJson('events.json');
        $now = time();
        $force = $force && (!$cache || ($now - (int) $cache['fetched']) >= self::FORCE_MIN_AGE);
        if (!$force && $cache && ($now - (int) $cache['fetched']) < $this->cache_ttl) {
            return array('events' => $cache['events'], 'fetched' => (int) $cache['fetched'], 'error' => null);
        }
        if (!$force && ($failed = $this->recentFailure('events'))) {
            return array('events' => $cache['events'] ?? array(), 'fetched' => $cache['fetched'] ?? null, 'error' => $failed);
        }
        try {
            $url = ConfigHelper::getConfig('iptv-errors.errors_url', '');
            if ($url === '' || $url === null) {
                // Fallback z url bez końcówki /metrics zwróciłby ten sam adres (OpenMetrics)
                // i próbował parsować go jako JSON — lepiej rzucić czytelny wyjątek konfiguracji.
                $url = preg_replace('#/metrics/?$#', '/errors', $this->url);
                if ($url === $this->url) {
                    throw new RuntimeException('Błędna konfiguracja iptv-errors.url (brak końcówki /metrics) — ustaw jawnie iptv-errors.errors_url');
                }
            }
            $json = json_decode($this->fetch($url), true);
            if (!is_array($json) || !isset($json['data']) || !is_array($json['data'])) {
                throw new RuntimeException('Nieoczekiwana odpowiedź /api/errors');
            }
            $events = array();
            foreach ($json['data'] as $e) {
                $mac = self::normalizeMac($e['sgt']['device']['mac'] ?? '');
                if ($mac === '') {
                    continue;
                }
                $events[] = array(
                    'mac' => $mac,
                    'ip' => (string) ($e['source']['ip'] ?? ''),
                    'model' => (string) ($e['sgt']['device']['model'] ?? ''),
                    'errors' => (int) ($e['sgt']['device']['errors'] ?? 0),
                    'group' => (string) ($e['sgt']['stream']['group'] ?? ''),
                    'ts' => strtotime((string) ($e['@timestamp'] ?? '')) ?: 0,
                );
            }
            $this->writeJson('events.json', array('fetched' => $now, 'events' => $events));
            $this->markFailure('events', null);
            return array('events' => $events, 'fetched' => $now, 'error' => null);
        } catch (Throwable $e) {
            error_log('[LMSIptvErrorsPlugin] events: ' . $e->getMessage());
            $this->markFailure('events', $e->getMessage());
            return array('events' => $cache['events'] ?? array(), 'fetched' => $cache['fetched'] ?? null, 'error' => $e->getMessage());
        }
    }

    /**
     * Dekodery przypisane do klientów w systemie SMS SGT (sms.sgtsa.pl), przez klasę `send` z LMSJamboxPlugin
     * (XML-RPC stbGetRegistered, tryb 'customer'). Zwraca mapę MAC => rekord. Tylko odczyt.
     *
     * @return array ['map' => array, 'fetched' => ts|null, 'error' => string|null, 'available' => bool]
     */
    public function getStbRegistry($force = false)
    {
        $cache = $this->readJson('stb.json');
        $now = time();
        $ttl = (int) ConfigHelper::getConfig('iptv-errors.stb_cache_ttl', 600);
        $force = $force && (!$cache || ($now - (int) $cache['fetched']) >= self::FORCE_MIN_AGE_STB);
        if (!$force && $cache && ($now - (int) $cache['fetched']) < $ttl) {
            return array('map' => $cache['map'], 'fetched' => (int) $cache['fetched'], 'error' => null, 'available' => true);
        }
        if (!$force && ($failed = $this->recentFailure('stb'))) {
            return array('map' => $cache['map'] ?? array(), 'fetched' => $cache['fetched'] ?? null, 'error' => $failed, 'available' => true);
        }
        if (!class_exists('send') && class_exists('LMSJamboxPlugin')) {
            $file = PLUGINS_DIR . DIRECTORY_SEPARATOR . 'LMSJamboxPlugin' . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'LMSTV.class.php';
            if (is_readable($file)) {
                require_once $file;
            }
        }
        if (!class_exists('send')) {
            return array('map' => $cache['map'] ?? array(), 'fetched' => $cache['fetched'] ?? null, 'error' => null, 'available' => false);
        }
        try {
            $sms = send::getInstance();
            $sms->setTimeout(30);
            $res = $sms->get('stbGetRegistered', array('customer'));
            $map = array();
            foreach ((array) $res as $r) {
                $mac = self::normalizeMac($r['stb_mac'] ?? '');
                if ($mac === '') {
                    continue;
                }
                $map[$mac] = array(
                    'cust_number' => (string) ($r['cust_number'] ?? ''),
                    'stb_ip' => (string) ($r['stb_ip'] ?? ''),
                    'serial' => (string) ($r['stb_serial'] ?? ''),
                    'model' => (string) ($r['stb_model'] ?? ''),
                    'broken' => !empty($r['stb_broken']) && $r['stb_broken'] !== '0',
                    'account_id' => (string) ($r['account_id'] ?? ''),
                );
            }
            $this->writeJson('stb.json', array('fetched' => $now, 'map' => $map));
            $this->markFailure('stb', null);
            return array('map' => $map, 'fetched' => $now, 'error' => null, 'available' => true);
        } catch (Throwable $e) {
            error_log('[LMSIptvErrorsPlugin] stb registry: ' . $e->getMessage());
            $this->markFailure('stb', $e->getMessage());
            return array('map' => $cache['map'] ?? array(), 'fetched' => $cache['fetched'] ?? null, 'error' => $e->getMessage(), 'available' => true);
        }
    }

    /**
     * Po błędzie źródła nie pytamy go ponownie przez FAILURE_BACKOFF sekund,
     * żeby strona nie czekała na timeout przy każdym otwarciu.
     *
     * @return string|null komunikat ostatniego błędu albo null, gdy można pytać
     */
    private function recentFailure($source)
    {
        $f = $this->readJson('fail-' . $source . '.json');
        if ($f && !empty($f['t']) && (time() - (int) $f['t']) < self::FAILURE_BACKOFF) {
            return (string) ($f['msg'] ?? 'Źródło niedostępne') . ' (ponowna próba za ' . (self::FAILURE_BACKOFF - (time() - (int) $f['t'])) . ' s)';
        }
        return null;
    }

    private function markFailure($source, $message)
    {
        try {
            if ($message === null) {
                @unlink($this->dir . DIRECTORY_SEPARATOR . 'fail-' . $source . '.json');
                unset(self::$jsonCache['fail-' . $source . '.json']);
            } else {
                $this->writeJson('fail-' . $source . '.json', array('t' => time(), 'msg' => $message));
            }
        } catch (Throwable $e) {
            error_log('[LMSIptvErrorsPlugin] ' . $e->getMessage());
        }
    }

    /**
     * Jedna normalizacja adresu MAC w całej wtyczce: małe litery, separator ':'.
     */
    public static function normalizeMac($mac)
    {
        return strtolower(str_replace('-', ':', trim((string) $mac)));
    }

    private function fetch($url = null)
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('Brak rozszerzenia PHP curl');
        }
        $ch = curl_init($url ?: $this->url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_MAXFILESIZE => self::MAX_RESPONSE_BYTES,
            CURLOPT_HTTPHEADER => array('X-API-Key: ' . $this->key, 'Accept: text/plain'),
        ));
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        if ($body === false) {
            throw new RuntimeException('Błąd połączenia z API: ' . $err);
        }
        if ($code !== 200) {
            throw new RuntimeException('API zwróciło HTTP ' . $code);
        }
        return $body;
    }

    /**
     * Parsuje tekst OpenMetrics do struktury widoku.
     */
    public static function parse($text)
    {
        $series = array();
        foreach (preg_split('/\R/', $text) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            if (!preg_match('/^([a-zA-Z_:][a-zA-Z0-9_:]*)(?:\{(.*)\})?\s+(\S+)(?:\s+\d+)?$/', $line, $m)) {
                continue;
            }
            $labels = array();
            if (!empty($m[2]) && preg_match_all('/([a-zA-Z_][a-zA-Z0-9_]*)="((?:[^"\\\\]|\\\\.)*)"/', $m[2], $lm, PREG_SET_ORDER)) {
                foreach ($lm as $l) {
                    $labels[$l[1]] = stripcslashes($l[2]);
                }
            }
            $series[$m[1]][] = array('labels' => $labels, 'value' => is_numeric($m[3]) ? (float) $m[3] : 0.0);
        }

        if (empty($series['device_errors'])) {
            throw new RuntimeException('Odpowiedź API nie zawiera serii device_errors');
        }

        $out = array('total_errors' => 0, 'total_emergency' => 0, 'models' => array(), 'subnets' => array(), 'streams' => array());

        foreach ($series['device_errors'] ?? array() as $s) {
            $model = $s['labels']['model'] ?? '';
            if ($model === 'total') {
                $out['total_errors'] = (int) $s['value'];
            } else {
                $out['models'][$model]['errors'] = (int) $s['value'];
            }
        }
        foreach ($series['device_emergency'] ?? array() as $s) {
            $model = $s['labels']['model'] ?? '';
            if ($model === 'total') {
                $out['total_emergency'] = (int) $s['value'];
            } else {
                $out['models'][$model]['emergency'] = (int) $s['value'];
            }
        }
        foreach ($series['subnet_errors'] ?? array() as $s) {
            $k = $s['labels']['subnet'] ?? '';
            $out['subnets'][$k]['name'] = $s['labels']['subnet_name'] ?? $k;
            $out['subnets'][$k]['errors'] = (int) $s['value'];
        }
        foreach ($series['subnet_emergency'] ?? array() as $s) {
            $k = $s['labels']['subnet'] ?? '';
            if (!isset($out['subnets'][$k]['name'])) {
                $out['subnets'][$k]['name'] = $s['labels']['subnet_name'] ?? $k;
            }
            $out['subnets'][$k]['emergency'] = (int) $s['value'];
        }
        foreach ($series['stream_errors'] ?? array() as $s) {
            $out['streams'][$s['labels']['group'] ?? ''] = (int) $s['value'];
        }
        foreach ($out['models'] as &$m) {
            $m += array('errors' => 0, 'emergency' => 0);
        }
        unset($m);
        foreach ($out['subnets'] as &$n) {
            $n += array('errors' => 0, 'emergency' => 0);
        }
        unset($n);

        // API zwraca czasem total=0 przy błędach w modelach lub podsieciach. Bierzemy największą z trzech wartości.
        $out['total_errors'] = max(
            $out['total_errors'],
            array_sum(array_column($out['models'], 'errors')),
            array_sum(array_column($out['subnets'], 'errors'))
        );

        return $out;
    }

    /**
     * Płaska mapa klucz => wartość do historii (tylko wartości różne od zera).
     */
    public static function flatten(array $d)
    {
        $f = array('total_errors' => $d['total_errors'], 'total_emergency' => $d['total_emergency']);
        foreach ($d['models'] as $k => $m) {
            $f['model:' . $k] = $m['errors'];
            $f['model_emg:' . $k] = $m['emergency'];
        }
        foreach ($d['subnets'] as $k => $n) {
            $f['subnet:' . $k] = $n['errors'];
            $f['subnet_emg:' . $k] = $n['emergency'];
        }
        foreach ($d['streams'] as $k => $v) {
            $f['stream:' . $k] = $v;
        }
        return array_filter($f);
    }

    /**
     * Zmiana wartości klucza względem migawki bazowej. Null, gdy klucz jest nowy
     * (brak go w bazie) — UI pokazuje „nowa” zamiast udawać skok od zera.
     */
    public static function delta($baseline, $key, $value)
    {
        if (!is_array($baseline) || !array_key_exists($key, $baseline['v'] ?? array())) {
            return null;
        }
        return (int) $value - (int) $baseline['v'][$key];
    }

    public function history()
    {
        return $this->readJson('history.json') ?: array();
    }

    /**
     * Szereg czasowy błędów jednego dekodera z historii migawek:
     * lista ['t' => timestamp, 'errors' => liczba]. Migawki bez pola 'devices_top'
     * (stary format) są pomijane — to brak danych, nie zero. Dekoder poza top 100
     * w danej migawce ma errors = 0.
     *
     * @param string $mac adres MAC (dowolny format, patrz normalizeMac)
     * @param int $hours ile godzin wstecz z historii
     * @return array lista ['t' => int, 'errors' => int]
     */
    public function getDeviceSeries($mac, $hours = 24)
    {
        return self::deviceSeriesFromHistory($this->history(), $mac, time(), $hours);
    }

    /**
     * To samo co getDeviceSeries(), ale na podanej tablicy historii
     * (np. świeżo odczytanej w module jednym readJson).
     *
     * @return array lista ['t' => int, 'errors' => int]
     */
    public static function deviceSeriesFromHistory(array $history, $mac, $now, $hours = 24)
    {
        $mac = self::normalizeMac($mac);
        $cutoff = $now - (int) $hours * 3600;
        $series = array();
        foreach ($history as $snap) {
            $t = (int) ($snap['t'] ?? 0);
            if ($t < $cutoff) {
                continue;
            }
            $top = $snap['devices_top'] ?? null; // wsteczna kompatybilność: stary format => null
            if (!is_array($top)) {
                continue;
            }
            $series[] = array('t' => $t, 'errors' => (int) ($top[$mac] ?? 0));
        }
        return $series;
    }

    /**
     * Top dekoderów wg błędów do migawki: {mac: errors}, najwyżej DEVICES_TOP_LIMIT wpisów.
     * Per MAC bierzemy max z jego zdarzeń (tak jak widok dekoderów); wartości odstające
     * (> DEVICE_ANOMALY_MAX) nie liczą się jako błędy. Null, gdy nie mamy żadnych zdarzeń —
     * migawka zostaje wtedy bez szeregu per-MAC (stary format / awaria endpointu).
     *
     * @return array|null
     */
    private function buildDevicesTop()
    {
        $perMac = array();
        foreach ((array) ($this->getEvents()['events'] ?? array()) as $e) {
            $mac = (string) ($e['mac'] ?? '');
            $errors = (int) ($e['errors'] ?? 0);
            if ($mac === '' || $errors <= 0 || $errors > self::DEVICE_ANOMALY_MAX) {
                continue;
            }
            $perMac[$mac] = max($perMac[$mac] ?? 0, $errors);
        }
        if (!$perMac) {
            return null;
        }
        arsort($perMac);
        return array_slice($perMac, 0, self::DEVICES_TOP_LIMIT, true);
    }

    /**
     * Wywołanie z timera: zawsze pobiera dane i dopisuje migawkę (bez sprawdzania cache).
     */
    public function collect()
    {
        try {
            $text = $this->fetch();
            $data = self::parse($text);
            $now = time();
            $this->writeJson('cache.json', array('fetched' => $now, 'data' => $data));
            $this->recordHistory($data, $now, true);
            $this->markFailure('metrics', null);
            return $data;
        } catch (Throwable $e) {
            error_log('[LMSIptvErrorsPlugin] collect: ' . $e->getMessage());
            // Bez tego backoff 90 s działałby tylko w procesie web — strona biłaby prosto w API.
            $this->markFailure('metrics', $e->getMessage());
            throw $e;
        }
    }

    private function recordHistory(array $data, $now, $always = false)
    {
        if (!is_dir($this->dir)) {
            return;
        }
        $lockFile = $this->dir . DIRECTORY_SEPARATOR . 'history.lock';
        $lock = fopen($lockFile, 'c');
        if ($lock === false) {
            return;
        }
        if (!flock($lock, LOCK_EX)) {
            fclose($lock);
            return;
        }
        // Jak reszta storage: tylko właściciel.
        @chmod($lockFile, 0600);
        $history = $this->readJson('history.json') ?: array();
        $last = $history ? end($history) : null;
        if ($always || !$last || ($now - (int) $last['t']) >= $this->history_interval) {
            // devices_top: szereg per-MAC (top 100) do timeline dekodera. Migawki sprzed
            // tego pola (stary format) zostają w historii bez zmian — czytamy je jako null.
            $history[] = array('t' => $now, 'v' => self::flatten($data), 'devices_top' => $this->buildDevicesTop());
            // Retencja po czasie: migawki starsze niż 24 h odpadają niezależnie od ich liczby.
            $cutoff = $now - self::HISTORY_TTL;
            $history = array_values(array_filter($history, function ($snap) use ($cutoff) {
                return (int) $snap['t'] >= $cutoff;
            }));
            $history = array_slice($history, -self::HISTORY_KEEP);
            $this->writeJson('history.json', $history);
        }
        flock($lock, LOCK_UN);
        fclose($lock);
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
        // Leniwe czytanie z cache per-request: plik (stb.json, history.json, ...) czytany
        // tylko gdy potrzebny i najwyżej raz na request.
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
        // Pliki zawierają dane klientów (numery SMS, serial, IP): tylko właściciel.
        if (file_put_contents($tmp, json_encode($data)) === false || !chmod($tmp, 0600) || !rename($tmp, $file)) {
            @unlink($tmp);
            throw new RuntimeException('Nie można zapisać ' . $file);
        }
        unset(self::$jsonCache[$name]);
    }
}
