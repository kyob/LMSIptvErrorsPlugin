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
 * Pobiera metryki IPTV z API SGT i dopisuje migawkę do historii (storage/iptv-errors/history.json).
 * Dzięki temu kolumna Δ i wykresy 24 h działają bez otwierania strony w LMS.
 *
 * Usage: php lms-iptv-errors-collect.php -C /etc/lms/lms.ini [--quiet]
 */

ini_set('error_reporting', E_ALL & ~E_NOTICE);

$CLI_OPTS = getopt('C:qh', array('config-file:', 'quiet', 'help'));
$CLI_QUIET = isset($CLI_OPTS['q']) || isset($CLI_OPTS['quiet']);

if (isset($CLI_OPTS['h']) || isset($CLI_OPTS['help'])) {
    fwrite(STDOUT, 'Usage: php ' . basename(__FILE__) . " [-C /etc/lms/lms.ini] [-q]\n");
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

$client = new IptvMetrics();
if (!$client->hasKey()) {
    fwrite(STDERR, 'Brak iptv-errors.api_key w konfiguracji (Konfiguracja -> Interfejs).' . PHP_EOL);
    exit(1);
}

try {
    $data = $client->collect();
} catch (Throwable $e) {
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

if (!$CLI_QUIET) {
    fwrite(STDOUT, sprintf(
        "[%s] dekodery z błędami: %d, podsieci: %d, grupy multicast: %d\n",
        date('Y-m-d H:i:s'), $data['total_errors'], count($data['subnets']), count($data['streams'])
    ));
}
