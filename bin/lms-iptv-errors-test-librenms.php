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
 * Usage: php lms-iptv-errors-test-librenms.php -C /etc/lms/lms.ini [--mac=00:1a:79:...] [--all] [-q]
 *   --mac=...  testuje tylko wskazany MAC (można powtórzyć)
 *   --all      testuje wszystkie MAC-e z events.json (domyślnie: pierwsze 25)
 */

ini_set('error_reporting', E_ALL & ~E_NOTICE);

$CLI_OPTS = getopt('C:qh', array('config-file:', 'mac:', 'all', 'quiet', 'help'));
$CLI_QUIET = isset($CLI_OPTS['q']) || isset($CLI_OPTS['quiet']);

if (isset($CLI_OPTS['h']) || isset($CLI_OPTS['help'])) {
    fwrite(STDOUT, 'Usage: php ' . basename(__FILE__) . " [-C /etc/lms/lms.ini] [--mac=00:1a:79:...] [--all] [-q]\n");
    exit(0);
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
exit($found > 0 ? 0 : 2);
