<?php

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
    const HISTORY_KEEP = 288; // 24 h przy snapshocie co 5 min

    private $url;
    private $key;
    private $cache_ttl;
    private $history_interval;
    private $dir;

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
            $url = ConfigHelper::getConfig('iptv-errors.errors_url', preg_replace('#/metrics/?$#', '/errors', $this->url));
            $json = json_decode($this->fetch($url), true);
            if (!is_array($json) || !isset($json['data']) || !is_array($json['data'])) {
                throw new RuntimeException('Nieoczekiwana odpowiedź /api/errors');
            }
            $events = array();
            foreach ($json['data'] as $e) {
                $mac = strtolower((string) ($e['sgt']['device']['mac'] ?? ''));
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
                $mac = strtolower(str_replace('-', ':', (string) ($r['stb_mac'] ?? '')));
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
            } else {
                $this->writeJson('fail-' . $source . '.json', array('t' => time(), 'msg' => $message));
            }
        } catch (Throwable $e) {
            error_log('[LMSIptvErrorsPlugin] ' . $e->getMessage());
        }
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
            CURLOPT_HTTPHEADER => array('X-API-Key: ' . $this->key, 'Accept: text/plain'),
        ));
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
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

    public function history()
    {
        return $this->readJson('history.json') ?: array();
    }

    /**
     * Wywołanie z timera: zawsze pobiera dane i dopisuje migawkę (bez sprawdzania cache).
     */
    public function collect()
    {
        $text = $this->fetch();
        $data = self::parse($text);
        $now = time();
        $this->writeJson('cache.json', array('fetched' => $now, 'data' => $data));
        $this->recordHistory($data, $now, true);
        return $data;
    }

    private function recordHistory(array $data, $now, $always = false)
    {
        if (!is_dir($this->dir)) {
            return;
        }
        $lock = fopen($this->dir . DIRECTORY_SEPARATOR . 'history.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            return;
        }
        $history = $this->readJson('history.json') ?: array();
        $last = $history ? end($history) : null;
        if ($always || !$last || ($now - (int) $last['t']) >= $this->history_interval) {
            $history[] = array('t' => $now, 'v' => self::flatten($data));
            $history = array_slice($history, -self::HISTORY_KEEP);
            $this->writeJson('history.json', $history);
        }
        flock($lock, LOCK_UN);
        fclose($lock);
    }

    private function readJson($name)
    {
        $file = $this->dir . DIRECTORY_SEPARATOR . $name;
        if (!is_readable($file)) {
            return null;
        }
        $json = json_decode((string) file_get_contents($file), true);
        return is_array($json) ? $json : null;
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
    }
}
