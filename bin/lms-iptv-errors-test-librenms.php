#!/usr/bin/env php
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
 * Test CLI integracji LibreNMS (bez przeglądarki): bierze MAC-i dekoderów z błędami
 * (storage/iptv-errors/events.json) i pokazuje, jaki przełącznik + lokalizację
 * zwraca dla nich API LibreNMS. Weryfikuje też flagę librenms_enabled i cache.
 *
 * FEAT(port-level): asercje dla metod port-level (getPortStatsForDevices, getPortFlaps,
 * getPortSyslog, getFdbDetailForMacs). W trybie rzeczywistym hosty bierze z cache
 * librenms.json (switche z FDB, max 3). W trybie --mock nie potrzeba LMS/DB —
 * HTTP podstawiany fixture'ami, test przechodzi lokalnie.
 *
 * Usage: php lms-iptv-errors-test-librenms.php -C /etc/lms/lms.ini [--mac=00:1a:79:...] [--all] [-q]
 *        php lms-iptv-errors-test-librenms.php --mock
 *   --mac=...  testuje tylko wskazany MAC (można powtórzyć)
 *   --all      testuje wszystkie MAC-e z events.json (domyślnie: pierwsze 25)
 *   --mock     test port-level na fixture'ach HTTP (bez configu, DB i sieci)
 *
 * Exit: 0 = OK (co najmniej 1 MAC z lokalizacją, 0 FAIL asercji),
 *       1 = błąd środowiska (brak configu/DB albo LibreNMS wyłączone),
 *       2 = wszystkie MAC-e null i 0 FAIL, 3 = któraś asercja FAIL.
 */

ini_set('error_reporting', E_ALL & ~E_NOTICE);

$CLI_OPTS = getopt('C:qh', array('config-file:', 'mac:', 'all', 'quiet', 'help', 'mock'));
$CLI_QUIET = isset($CLI_OPTS['q']) || isset($CLI_OPTS['quiet']);

if (isset($CLI_OPTS['h']) || isset($CLI_OPTS['help'])) {
    fwrite(STDOUT, 'Usage: php ' . basename(__FILE__) . " [-C /etc/lms/lms.ini] [--mac=00:1a:79:...] [--all] [-q] [--mock]\n");
    exit(0);
}

$fails = 0;
$check = function ($name, $cond) use (&$fails) {
    if ($cond) {
        fwrite(STDOUT, "PASS {$name}\n");
    } else {
        $fails++;
        fwrite(STDOUT, "FAIL {$name}\n");
    }
};

/**
 * FEAT(port-level) --mock: klient LibreNMS z fixture'ami HTTP (bez sieci).
 * Zwraca odpowiedzi jak prawdziwe API v0 dla dwóch hostów (sw1.test, sw2.test).
 */
function mock_librenms_run($check)
{
    if (!defined('STORAGE_DIR')) {
        define('STORAGE_DIR', sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'iptv-errors-mock-' . getmypid());
    }
    if (!class_exists('ConfigHelper')) {
        // Stub configu jak z uiconfig: LibreNMS włączone, mockowy URL i token.
        class ConfigHelper
        {
            public static function getConfig($key, $default = null)
            {
                $map = array(
                    'iptv-errors.librenms_enabled' => true,
                    'iptv-errors.librenms_url' => 'https://librenms.mock/api/v0',
                    'iptv-errors.librenms_api_token' => 'mock-token',
                    'iptv-errors.librenms_cache_ttl' => 600,
                );
                return array_key_exists($key, $map) ? $map[$key] : $default;
            }
        }
    }
    require_once dirname(__FILE__) . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'IptvMetrics.php';
    require_once dirname(__FILE__) . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'IptvLibrenms.php';

    class MockIptvLibrenms extends IptvLibrenms
    {
        public static $calls = 0;

        protected function fetchJson($url)
        {
            return $this->mock($url);
        }

        protected function fetchJsonMulti(array $urls, $throwOnError = true)
        {
            $out = array();
            foreach ($urls as $k => $u) {
                $out[$k] = $this->mock($u);
            }
            return $out;
        }

        private function mock($url)
        {
            self::$calls++;
            $now = time();
            // GET /devices/{host}/ports?columns=...
            if (preg_match('#/devices/([^/?]+)/ports#', $url, $m)) {
                $host = strtolower(urldecode($m[1]));
                if ($host === 'sw1.test') {
                    return array('status' => 'ok', 'count' => 2, 'ports' => array(
                        array('port_id' => 101, 'device_id' => 56, 'ifName' => 'eth1/0/24', 'ifOperStatus' => 'up',
                            'ifAdminStatus' => 'up', 'ifSpeed' => 1000000000, 'ifDuplex' => 'full', 'ifAlias' => 'Klient X',
                            'ifInErrors' => 1219188, 'ifOutErrors' => 5, 'ifLastChange' => '2 days 3 hours', 'poll_time' => date('Y-m-d H:i:s', $now)),
                        array('port_id' => 102, 'device_id' => 56, 'ifName' => 'eth1/0/25', 'ifOperStatus' => 'up',
                            'ifAdminStatus' => 'up', 'ifSpeed' => 100000000, 'ifDuplex' => 'full', 'ifAlias' => '',
                            'ifInErrors' => 0, 'ifOutErrors' => 0, 'ifLastChange' => '30 days', 'poll_time' => date('Y-m-d H:i:s', $now)),
                    ));
                }
                if ($host === 'sw2.test') {
                    return array('status' => 'ok', 'count' => 1, 'ports' => array(
                        array('port_id' => 201, 'device_id' => 57, 'ifName' => 'eth1/0/1', 'ifOperStatus' => 'down',
                            'ifAdminStatus' => 'up', 'ifSpeed' => 0, 'ifDuplex' => '', 'ifAlias' => '',
                            'ifInErrors' => 0, 'ifOutErrors' => 0, 'ifLastChange' => '3 hours', 'poll_time' => date('Y-m-d H:i:s', $now)),
                    ));
                }
                return array('error' => 'HTTP 404', 'code' => 404);
            }
            // GET /devices/{host} (device_id dla syslog)
            if (preg_match('#/devices/([^/?]+)$#', $url, $m)) {
                $host = strtolower(urldecode($m[1]));
                $ids = array('sw1.test' => 56, 'sw2.test' => 57);
                if (isset($ids[$host])) {
                    return array('status' => 'ok', 'devices' => array(array('device_id' => $ids[$host], 'hostname' => $host)));
                }
                return array('error' => 'HTTP 404', 'code' => 404);
            }
            // GET /logs/eventlog/{host}?...
            if (preg_match('#/logs/eventlog/([^/?]+)#', $url, $m)) {
                $host = strtolower(urldecode($m[1]));
                if ($host !== 'sw1.test') {
                    return array('status' => 'ok', 'count' => 0, 'logs' => array());
                }
                return array('status' => 'ok', 'count' => 3, 'logs' => array(
                    array('type' => 'interface', 'reference' => 101, 'datetime' => date('Y-m-d H:i:s', $now - 1800), 'message' => 'interface ifOperStatus: up -> down', 'hostname' => $host, 'device_id' => 56),
                    array('type' => 'interface', 'reference' => 101, 'datetime' => date('Y-m-d H:i:s', $now - 900), 'message' => 'interface ifOperStatus: down -> up', 'hostname' => $host, 'device_id' => 56),
                    array('type' => 'interface', 'reference' => 101, 'datetime' => date('Y-m-d H:i:s', $now - 3 * 86400), 'message' => 'interface ifOperStatus: up -> down', 'hostname' => $host, 'device_id' => 56),
                    array('type' => 'device', 'reference' => 0, 'datetime' => date('Y-m-d H:i:s', $now - 60), 'message' => 'Device rebooted', 'hostname' => $host, 'device_id' => 56),
                ));
            }
            // GET /logs/syslog?device_id=...
            if (strpos($url, '/logs/syslog') !== false) {
                return array('status' => 'ok', 'count' => 3, 'logs' => array(
                    array('hostname' => 'sw1.test', 'device_id' => 56, 'timestamp' => date('Y-m-d H:i:s', $now - 600), 'msg' => 'Port 1:24 link up, 1000Mbps FULL duplex', 'level' => 'info'),
                    array('hostname' => 'sw1.test', 'device_id' => 56, 'timestamp' => date('Y-m-d H:i:s', $now - 1800), 'msg' => 'Port 1:24 link down', 'level' => 'warning'),
                    array('hostname' => 'sw1.test', 'device_id' => 56, 'timestamp' => date('Y-m-d H:i:s', $now - 3600), 'msg' => 'some other message', 'level' => 'info'),
                ));
            }
            // GET /resources/fdb/{mac}/detail
            if (preg_match('#/resources/fdb/([^/]+)/detail#', $url, $m)) {
                $mac = strtolower(urldecode($m[1]));
                if ($mac === '00:1a:79:5b:d1:1d') {
                    return array('status' => 'ok', 'count' => 1, 'ports_fdb' => array(
                        array('hostname' => 'sw1.test', 'sysName' => 'sw1.test', 'ifName' => 'eth1/0/24', 'ifAlias' => 'Klient X', 'updated_at' => date('Y-m-d H:i:s', $now - 60)),
                    ));
                }
                if ($mac === 'aa:bb:cc:dd:ee:ff') {
                    // MAC na dwóch switchach jednocześnie — flapping MAC / stale FDB
                    return array('status' => 'ok', 'count' => 2, 'ports_fdb' => array(
                        array('hostname' => 'sw1.test', 'sysName' => 'sw1.test', 'ifName' => 'eth1/0/10', 'ifAlias' => '', 'updated_at' => date('Y-m-d H:i:s', $now - 60)),
                        array('hostname' => 'sw2.test', 'sysName' => 'sw2.test', 'ifName' => 'eth1/0/2', 'ifAlias' => '', 'updated_at' => date('Y-m-d H:i:s', $now - 30)),
                    ));
                }
                return array('error' => 'HTTP 404', 'code' => 404);
            }
            return array('error' => 'HTTP 404', 'code' => 404);
        }
    }

    $lnms = new MockIptvLibrenms();
    $check('mock: enabled()', $lnms->enabled());
    $hosts = array('SW1.test', 'sw2.test', 'nie-ma-takiego.test');

    // 1) statystyki portów per switch
    $stats = $lnms->getPortStatsForDevices($hosts, true);
    $check('portstats: 3 hosty w wyniku', count($stats) === 3);
    $check('portstats: sw1 ma 2 porty', isset($stats['sw1.test']['ports']) && count($stats['sw1.test']['ports']) === 2);
    $p101 = null;
    foreach (($stats['sw1.test']['ports'] ?? array()) as $p) {
        if ($p['ifName'] === 'eth1/0/24') {
            $p101 = $p;
        }
    }
    $check('portstats: eth1/0/24 ifInErrors=1219188', $p101 && $p101['in_errors'] === 1219188);
    $check('portstats: device_id z portów', (int) ($stats['sw1.test']['device_id'] ?? 0) === 56);
    $check('portstats: nieistniejący host = null', array_key_exists('nie-ma-takiego.test', $stats) && $stats['nie-ma-takiego.test'] === null);

    // 2) flapy z eventlog — agregacja per port_id w oknie 24 h i 7 d
    $fl24 = $lnms->getPortFlaps(array('sw1.test'), 24, true);
    $fl7 = $lnms->getPortFlaps(array('sw1.test'), 168, true);
    $check('flaps 24h: port 101 ma 2 zdarzenia', (int) ($fl24['sw1.test']['by_port'][101]['count'] ?? -1) === 2);
    $check('flaps 7d: port 101 ma 3 zdarzenia', (int) ($fl7['sw1.test']['by_port'][101]['count'] ?? -1) === 3);
    $check('flaps: eventlog bez type=interface odrzucony', (int) ($fl24['sw1.test']['total'] ?? -1) === 2);

    // 3) syslog link up/down — parsowanie komunikatu
    $sl = $lnms->getPortSyslog(array('sw1.test'), 24, true);
    $evs = $sl['sw1.test']['events'] ?? array();
    $check('syslog: 2 link events (bez "some other message")', count($evs) === 2);
    $check('syslog: najnowszy to up 1:24 1000Mbps FULL', isset($evs[0])
        && $evs[0]['port'] === '1:24' && $evs[0]['state'] === 'up'
        && $evs[0]['speed'] === 1000 && $evs[0]['duplex'] === 'FULL');
    $check('syslog: device_id pobrane z portstats', (int) ($sl['sw1.test']['device_id'] ?? 0) === 56);

    // 4) FDB per MAC — wykrycie MAC na >1 switchu
    $fdb = $lnms->getFdbDetailForMacs(array('00:1A:79:5B:D1:1D', 'aa:bb:cc:dd:ee:ff', '00:00:00:00:00:01'), true);
    $check('fdb: mac1 pojedynczy port, multi=false', isset($fdb['00:1a:79:5b:d1:1d'])
        && $fdb['00:1a:79:5b:d1:1d']['multi'] === false && count($fdb['00:1a:79:5b:d1:1d']['ports']) === 1);
    $check('fdb: mac2 na 2 switchach, multi=true', isset($fdb['aa:bb:cc:dd:ee:ff'])
        && $fdb['aa:bb:cc:dd:ee:ff']['multi'] === true && count($fdb['aa:bb:cc:dd:ee:ff']['ports']) === 2);
    $check('fdb: nieobecny MAC = null (404)', array_key_exists('00:00:00:00:00:01', $fdb) && $fdb['00:00:00:00:00:01'] === null);

    // 5) cache JSON w storage — pliki powstały, a drugi przebieg bez force nie woła HTTP
    $dir = STORAGE_DIR . DIRECTORY_SEPARATOR . 'iptv-errors';
    foreach (array('librenms-portstats.json', 'librenms-flaps.json', 'librenms-syslog.json', 'librenms-fdb.json') as $f) {
        $check('cache: plik ' . $f, is_readable($dir . DIRECTORY_SEPARATOR . $f));
    }
    $calls_before = MockIptvLibrenms::$calls;
    $lnms->getPortStatsForDevices(array('sw1.test'));
    $lnms->getPortFlaps(array('sw1.test'), 24);
    $lnms->getPortSyslog(array('sw1.test'), 24);
    $lnms->getFdbDetailForMacs(array('00:1a:79:5b:d1:1d'));
    $check('cache: bez force = 0 nowych wywołań HTTP', MockIptvLibrenms::$calls === $calls_before);
}

if (isset($CLI_OPTS['mock'])) {
    mock_librenms_run($check);
    fwrite(STDOUT, sprintf("\n--mock: %s\n", $fails ? "$fails asercja FAIL" : 'wszystkie asercje PASS'));
    exit($fails ? 1 : 0);
}

$CONFIG_FILE = $CLI_OPTS['config-file'] ?? $CLI_OPTS['C'] ?? '/etc/lms/lms.ini';
if (!is_readable($CONFIG_FILE)) {
    fwrite(STDERR, 'Unable to read configuration file: ' . $CONFIG_FILE . PHP_EOL);
    exit(1);
}

define('CONFIG_FILE', $CONFIG_FILE);
$CONFIG = (array) parse_ini_file(CONFIG_FILE, true);
if (!isset($CONFIG['directories']) || !is_array($CONFIG['directories'])) {
    $CONFIG['directories'] = array();
}
$CONFIG['directories']['sys_dir'] = (!isset($CONFIG['directories']['sys_dir']) ? getcwd() : $CONFIG['directories']['sys_dir']);
$CONFIG['directories']['lib_dir'] = (!isset($CONFIG['directories']['lib_dir']) ? $CONFIG['directories']['sys_dir'] . DIRECTORY_SEPARATOR . 'lib' : $CONFIG['directories']['lib_dir']);
$CONFIG['directories']['plugin_dir'] = (!isset($CONFIG['directories']['plugin_dir']) ? $CONFIG['directories']['sys_dir'] . DIRECTORY_SEPARATOR . 'plugins' : $CONFIG['directories']['plugin_dir']);
$CONFIG['directories']['storage_dir'] = (!isset($CONFIG['directories']['storage_dir']) ? $CONFIG['directories']['sys_dir'] . DIRECTORY_SEPARATOR . 'storage' : $CONFIG['directories']['storage_dir']);

define('SYS_DIR', $CONFIG['directories']['sys_dir']);
define('LIB_DIR', $CONFIG['directories']['lib_dir']);
define('PLUGIN_DIR', $CONFIG['directories']['plugin_dir']);
define('PLUGINS_DIR', $CONFIG['directories']['plugin_dir']);
define('STORAGE_DIR', $CONFIG['directories']['storage_dir']);

$composer_autoload_path = SYS_DIR . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
if (!file_exists($composer_autoload_path)) {
    fwrite(STDERR, "Composer autoload not found: {$composer_autoload_path}" . PHP_EOL);
    exit(1);
}
require_once $composer_autoload_path;

try {
    $DB = LMSDB::getInstance();
} catch (Exception $ex) {
    fwrite(STDERR, 'Database error: ' . $ex->getMessage() . PHP_EOL);
    exit(1);
}

require_once LIB_DIR . DIRECTORY_SEPARATOR . 'common.php';
require_once LIB_DIR . DIRECTORY_SEPARATOR . 'language.php';
include_once LIB_DIR . DIRECTORY_SEPARATOR . 'definitions.php';
require_once dirname(__FILE__) . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'IptvMetrics.php';
require_once dirname(__FILE__) . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'IptvLibrenms.php';

$lnms = new IptvLibrenms();
if (!$lnms->enabled()) {
    fwrite(STDERR, "LibreNMS wyłączone: ustaw iptv-errors.librenms_enabled=1, librenms_url i librenms_api_token (Konfiguracja -> Interfejs).\n");
    exit(1);
}

// MAC-i do testu: --mac=... albo z events.json (pierwsze 25, lub wszystkie z --all).
$macs = array();
if (!empty($CLI_OPTS['mac'])) {
    $macs = is_array($CLI_OPTS['mac']) ? $CLI_OPTS['mac'] : array($CLI_OPTS['mac']);
} else {
    $eventsFile = STORAGE_DIR . DIRECTORY_SEPARATOR . 'iptv-errors' . DIRECTORY_SEPARATOR . 'events.json';
    if (!is_readable($eventsFile)) {
        fwrite(STDERR, 'Brak ' . $eventsFile . ' — najpierw odśwież dane (kolektor/strona), albo podaj --mac=...' . PHP_EOL);
        exit(1);
    }
    $ev = json_decode((string) file_get_contents($eventsFile), true);
    $macs = array_values(array_unique(array_column(is_array($ev) ? ($ev['events'] ?? array()) : array(), 'mac')));
    if (empty($CLI_OPTS['all'])) {
        $macs = array_slice($macs, 0, 25);
    }
}

$t0 = microtime(true);
$res = $lnms->getLocationsForMacs($macs, true);
$elapsed = microtime(true) - $t0;

$found = 0;
foreach ($macs as $m) {
    $key = IptvMetrics::normalizeMac($m);
    $r = $res[$key] ?? null;
    if ($r && ($r['location'] ?? '') !== '') {
        $found++;
    }
    if (!$CLI_QUIET) {
        fwrite(STDOUT, sprintf(
            "%s => %s\n",
            strtoupper($key),
            $r ? sprintf('%s | %s | %s', $r['switch'], $r['port'], $r['location']) : 'null (brak w FDB)'
        ));
    }
}
fwrite(STDOUT, sprintf("\nLibreNMS: %d/%d MAC-ów ma lokalizację (%.1f s, batch curl_multi).\n", $found, count($macs), $elapsed));

// FEAT(port-level): asercje dla nowych metod na żywym API — hosty bierzemy z cache librenms.json
// (switche, do których podłączone są dekodery z FDB), max 3, żeby test był szybki.
$hosts = array();
$lnmsCacheFile = STORAGE_DIR . DIRECTORY_SEPARATOR . 'iptv-errors' . DIRECTORY_SEPARATOR . 'librenms.json';
if (is_readable($lnmsCacheFile)) {
    $c = json_decode((string) file_get_contents($lnmsCacheFile), true);
    foreach ((array) ($c['map'] ?? array()) as $loc) {
        if (is_array($loc) && !empty($loc['switch'])) {
            $hosts[mb_strtolower((string) $loc['switch'])] = true;
        }
    }
}
$hosts = array_slice(array_keys($hosts), 0, 3);
if (!$hosts) {
    fwrite(STDOUT, "SKIP port-level: brak switchy w cache librenms.json (pierwszy przebieg z --mac wypełni cache).\n");
} else {
    fwrite(STDOUT, "\nPort-level na żywym API (hosty: " . implode(', ', $hosts) . "):\n");
    $t1 = microtime(true);
    $stats = $lnms->getPortStatsForDevices($hosts, true);
    $check('portstats: wynik dla każdego hosta', count($stats) === count($hosts));
    $any_port = false;
    $any_errors = false;
    foreach ($stats as $st) {
        foreach ((array) ($st['ports'] ?? array()) as $p) {
            if (($p['ifName'] ?? '') !== '') {
                $any_port = true;
            }
            if (($p['in_errors'] ?? 0) + ($p['out_errors'] ?? 0) > 0) {
                $any_errors = true;
            }
        }
    }
    $check('portstats: co najmniej jeden port z ifName', $any_port);
    $check('portstats: co najmniej jeden port z licznikami błędów > 0', $any_errors);

    $fl24 = $lnms->getPortFlaps($hosts, 24, true);
    $fl7 = $lnms->getPortFlaps($hosts, 168, true);
    $ok_struct = true;
    $fl7_ge_24 = true;
    foreach ($hosts as $h) {
        if (!isset($fl24[$h]['by_port']) || !is_array($fl24[$h]['by_port'])) {
            $ok_struct = false;
        }
        if ((int) ($fl7[$h]['total'] ?? -1) < (int) ($fl24[$h]['total'] ?? 0)) {
            $fl7_ge_24 = false;
        }
    }
    $check('flaps: struktura by_port per host', $ok_struct);
    $check('flaps: total 7d >= total 24h', $fl7_ge_24);

    $sl = $lnms->getPortSyslog($hosts, 24, true);
    $ok_syslog = true;
    $parsed = 0;
    foreach ($sl as $h => $s) {
        if (!isset($s['events']) || !is_array($s['events'])) {
            $ok_syslog = false;
        }
        foreach ((array) ($s['events'] ?? array()) as $ev) {
            if (($ev['port'] ?? '') !== '' && in_array($ev['state'] ?? '', array('up', 'down'), true)) {
                $parsed++;
            }
        }
    }
    $check('syslog: struktura events per host', $ok_syslog);
    fwrite(STDOUT, sprintf("syslog: sparsowane link up/down w 24 h: %d (filtr per device_id).\n", $parsed));

    $fdb = $lnms->getFdbDetailForMacs(array_slice($macs, 0, 10), true);
    $ok_fdb = true;
    $multi_n = 0;
    foreach ($fdb as $m => $e) {
        if ($e === null) {
            continue;
        }
        if (!isset($e['ports']) || !is_array($e['ports']) || !array_key_exists('multi', $e)) {
            $ok_fdb = false;
        }
        if (!empty($e['multi'])) {
            $multi_n++;
        }
    }
    $check('fdb: struktura ports+multi per MAC', $ok_fdb);
    fwrite(STDOUT, sprintf("fdb: MAC-e na >1 porcie/switchu (flapping): %d z %d.\n", $multi_n, count($fdb)));

    fwrite(STDOUT, sprintf("Port-level: %.1f s.\n", microtime(true) - $t1));
}

fwrite(STDOUT, sprintf("\nAsercje port-level: %s\n", $fails ? "$fails FAIL" : 'wszystkie PASS'));
exit($found > 0 ? ($fails ? 3 : 0) : ($fails ? 3 : 2));
