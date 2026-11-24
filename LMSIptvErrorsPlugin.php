<?php

/**
 * LMSIptvErrorsPlugin
 * Monitoring błędów IPTV (dekodery, podsieci, grupy multicast) z API portal.sgt.net.pl
 * (device-errors-api.sgt.net.pl, format OpenMetrics).
 *
 * @author Łukasz Kopiszka <lukasz@alfa-system.pl>
 */
class LMSIptvErrorsPlugin extends LMSPlugin
{
    const PLUGIN_NAME = 'LMS IPTV Errors plugin';
    const PLUGIN_DESCRIPTION = 'Błędy IPTV z monitoringu SGT: dekodery, podsieci, grupy multicast, trendy i diagnostyka.';
    const PLUGIN_AUTHOR = 'Łukasz Kopiszka &lt;lukasz@alfa-system.pl&gt;';
    const PLUGIN_DIRECTORY_NAME = 'LMSIptvErrorsPlugin';
    const PLUGIN_SOFTWARE_VERSION = '1.0.0';

    public function registerHandlers()
    {
        $this->handlers = array(
            'menu_initialized' => array(
                'class' => 'IptvErrorsHandler',
                'method' => 'menuIptvErrors',
            ),
            'smarty_initialized' => array(
                'class' => 'IptvErrorsHandler',
                'method' => 'smartyIptvErrors',
            ),
            'modules_dir_initialized' => array(
                'class' => 'IptvErrorsHandler',
                'method' => 'modulesDirIptvErrors',
            ),
            'access_table_initialized' => array(
                'class' => 'IptvErrorsHandler',
                'method' => 'accessTableInit',
            ),
            'customerinfo_before_display' => array(
                'class' => 'LMSIptvErrorsPlugin',
                'method' => 'customerInfoBeforeDisplay',
            ),
        );
    }

    /**
     * Hook customerinfo_before_display: box „Błędy IPTV klienta" w karcie klienta.
     * Dane tylko z cache pluginu (storage/iptv-errors/*.json) — zwykły odczyt plików, bez wywołań API.
     * Handler wskazuje na klasę wtyczki, bo tylko ona jest gwarantowanie w classmapie composera.
     */
    public function customerInfoBeforeDisplay(array $hook_data)
    {
        try {
            $box = $this->customerIptvErrorsBox(intval($hook_data['customerinfo']['id'] ?? 0));
            $smarty = $hook_data['smarty'] ?? null;
            if ($box !== null && is_object($smarty) && method_exists($smarty, 'registerFilter')) {
                $smarty->registerFilter('output', function ($content, $template) use ($box) {
                    return self::injectCustomerBox($content, $box);
                });
            }
        } catch (Throwable $e) {
            error_log('[LMSIptvErrorsPlugin] customerinfo_before_display: ' . $e->getMessage());
        }
        return $hook_data;
    }

    /**
     * Wstrzykuje box przed zamknięciem kontenera #customerinfo-sortable (przed skryptem restoreSortable).
     */
    private static function injectCustomerBox($content, $box)
    {
        if (!is_string($content) || $box === '' || strpos($content, 'customerinfo-sortable') === false) {
            return $content;
        }
        $anchor = strpos($content, "restoreSortable('customerinfo-sortable'");
        if ($anchor === false) {
            return $content;
        }
        $close = strrpos(substr($content, 0, $anchor), '</div>');
        if ($close === false) {
            return $content;
        }
        return substr($content, 0, $close) . $box . substr($content, $close);
    }

    /**
     * Buduje HTML boxa „Błędy IPTV klienta" wyłącznie z cache pluginu (tylko odczyt).
     * Zwraca null, gdy box nie ma być pokazany (brak klienta albo brak uprawnienia do ?m=iptverrors).
     *
     * @param int $customerid
     * @return string|null
     */
    private function customerIptvErrorsBox($customerid)
    {
        if ($customerid <= 0 || !defined('STORAGE_DIR')) {
            return null;
        }

        // Uprawnienie jak dla modułu ?m=iptverrors (maska ^iptverrors$ z accessTableInit).
        global $LMS;
        $uid = Auth::GetCurrentUser();
        if (!$uid || !isset($LMS) || !is_object($LMS) || !method_exists($LMS, 'GetUserRights')) {
            return null;
        }
        $rights = $LMS->GetUserRights($uid);
        if (!is_array($rights) || !AccessRights::getInstance()->checkRights('iptverrors', $rights)) {
            return null;
        }

        $dir = STORAGE_DIR . DIRECTORY_SEPARATOR . 'iptv-errors';
        $stb = self::readCacheJson($dir . DIRECTORY_SEPARATOR . 'stb.json');
        $events = self::readCacheJson($dir . DIRECTORY_SEPARATOR . 'events.json');
        $stb_map = (is_array($stb) && isset($stb['map']) && is_array($stb['map'])) ? $stb['map'] : array();
        $fetched = 0;
        if (is_array($events) && !empty($events['fetched'])) {
            $fetched = (int) $events['fetched'];
        } elseif (is_array($stb) && !empty($stb['fetched'])) {
            $fetched = (int) $stb['fetched'];
        }

        // MAC-i dekoderów klienta: węzły LMS należące do klienta oraz rejestr SMS (stb.json) po tv_cust_number.
        $db = LMSDB::getInstance();
        $macs = array();
        foreach ($db->GetAll('SELECT lower(x.mac) AS mac FROM macs x JOIN nodes n ON n.id = x.nodeid WHERE n.ownerid = ?', array($customerid)) ?: array() as $r) {
            if (preg_match('/^[0-9a-f]{2}(:[0-9a-f]{2}){5}$/', (string) $r['mac'])) {
                $macs[(string) $r['mac']] = true;
            }
        }
        $tv_cust_number = $db->GetOne('SELECT tv_cust_number FROM customers WHERE id = ?', array($customerid));
        if ($tv_cust_number !== null && $tv_cust_number !== '') {
            foreach ($stb_map as $mac => $r) {
                if ((string) ($r['cust_number'] ?? '') === (string) $tv_cust_number) {
                    $macs[(string) $mac] = true;
                }
            }
        }

        // Zdarzenia per dekoder (events.json) przefiltrowane po MAC-ach klienta; agregacja jak w widoku devices.
        $anomaly_min = 1000;
        $devices = array();
        if (is_array($events) && isset($events['events']) && is_array($events['events'])) {
            foreach ($events['events'] as $e) {
                $mac = strtolower((string) ($e['mac'] ?? ''));
                if ($mac === '' || !isset($macs[$mac])) {
                    continue;
                }
                if (!isset($devices[$mac])) {
                    $devices[$mac] = array(
                        'mac' => $mac,
                        'ip' => (string) ($e['ip'] ?? ''),
                        'model' => (string) ($e['model'] ?? ''),
                        'errors' => 0,
                        'anomaly' => 0,
                        'events' => 0,
                        'groups' => array(),
                        'ts' => 0,
                        'broken' => !empty($stb_map[$mac]['broken']),
                    );
                }
                $d = &$devices[$mac];
                $d['events']++;
                $errs = (int) ($e['errors'] ?? 0);
                if ($errs > $anomaly_min) {
                    $d['anomaly'] = max($d['anomaly'], $errs);
                } else {
                    $d['errors'] = max($d['errors'], $errs);
                }
                $d['groups'][(string) ($e['group'] ?? '')] = true;
                if ((int) ($e['ts'] ?? 0) >= $d['ts']) {
                    $d['ts'] = (int) ($e['ts'] ?? 0);
                    $d['ip'] = ($e['ip'] ?? '') !== '' ? (string) $e['ip'] : $d['ip'];
                }
                unset($d);
            }
        }
        usort($devices, function ($a, $b) {
            return [$b['errors'], $b['events']] <=> [$a['errors'], $a['events']] ?: strcmp($a['mac'], $b['mac']);
        });

        $esc = function ($s) {
            return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        };
        $total_errors = array_sum(array_column($devices, 'errors'));
        $q = $tv_cust_number !== null && $tv_cust_number !== '' ? (string) $tv_cust_number
            : ($devices ? strtoupper($devices[0]['mac']) : '');

        $rows = '';
        if ($events === null && $stb === null) {
            $rows = self::boxRow($esc(trans('Brak danych w cache pluginu (storage/iptv-errors) — pojawią się po pierwszym pobraniu z API SGT.')), 12);
        } elseif (empty($macs)) {
            $rows = self::boxRow($esc(trans('Brak dekoderów IPTV przypisanych do klienta (MAC węzła w LMS albo numer SMS w polu „Numer klienta TV").')), 12);
        } elseif (empty($devices)) {
            $rows = self::boxRow($esc(trans('Przypisane dekodery nie zgłosiły ostatnio błędów (events.json).')), 12);
        } else {
            $rows = '<div class="lms-ui-tab-table-row header">'
                . '<div class="lms-ui-tab-table-wrapper col-3"><div class="lms-ui-tab-table-column">' . $esc(trans('MAC:')) . '</div></div>'
                . '<div class="lms-ui-tab-table-wrapper col-2"><div class="lms-ui-tab-table-column">' . $esc(trans('IP:')) . '</div></div>'
                . '<div class="lms-ui-tab-table-wrapper col-3"><div class="lms-ui-tab-table-column">' . $esc(trans('Model:')) . '</div></div>'
                . '<div class="lms-ui-tab-table-wrapper col-3"><div class="lms-ui-tab-table-column">' . $esc(trans('Grupy multicast:')) . '</div></div>'
                . '<div class="lms-ui-tab-table-wrapper col-1"><div class="lms-ui-tab-table-column">' . $esc(trans('Błędy (max):')) . '</div></div>'
                . '</div>';
            foreach ($devices as $d) {
                $groups = array_keys($d['groups']);
                usort($groups, 'strnatcmp');
                $errs = $d['anomaly'] > 0
                    ? $d['anomaly'] . ' <span style="color:#999">(' . $esc(trans('odstająca')) . ')</span>'
                    : (string) $d['errors'];
                $rows .= '<div class="lms-ui-tab-table-row">'
                    . '<div class="lms-ui-tab-table-wrapper col-3"><div class="lms-ui-tab-table-column">' . $esc(strtoupper($d['mac'])) . '</div></div>'
                    . '<div class="lms-ui-tab-table-wrapper col-2"><div class="lms-ui-tab-table-column">' . $esc($d['ip']) . '</div></div>'
                    . '<div class="lms-ui-tab-table-wrapper col-3"><div class="lms-ui-tab-table-column">' . $esc($d['model'])
                    . ($d['broken'] ? ' <span style="color:#b00020">' . $esc(trans('uszkodzony')) . '</span>' : '') . '</div></div>'
                    . '<div class="lms-ui-tab-table-wrapper col-3"><div class="lms-ui-tab-table-column">' . $esc(implode(', ', $groups)) . '</div></div>'
                    . '<div class="lms-ui-tab-table-wrapper col-1"><div class="lms-ui-tab-table-column">'
                    . ($d['errors'] > 0 || $d['anomaly'] > 0 ? '<span style="color:#b00020;font-weight:bold">' . $errs . '</span>' : $errs) . '</div></div>'
                    . '</div>';
            }
        }

        $summary = $devices
            ? $esc(trans('Suma błędów (max):')) . ' <strong>' . $total_errors . '</strong> · ' . count($devices) . ' ' . $esc(trans('dekoderów'))
            : $esc(trans('Brak błędów IPTV dla tego klienta w ostatnich zdarzeniach.'));
        $cache_note = $fetched > 0
            ? $esc(trans('Dane z cache pluginu (tylko odczyt): events.json/stb.json, pobrane')) . ' ' . date('Y-m-d H:i:s', $fetched)
            : $esc(trans('Dane z cache pluginu (tylko odczyt): events.json/stb.json — brak zapisanej migawki.'));

        return '<div id="iptverrorscustomerbox" class="lms-ui-tab-container lms-ui-sortable">'
            . '<div class="lms-ui-tab-header lmsbox-titlebar" data-lmsbox-content="iptverrorscustomerpanel">'
            . '<div class="lms-ui-tab-header-cell"><i class="fas fa-tv lms-ui-sortable-handle"></i> <strong>' . $esc(trans('Błędy IPTV klienta')) . '</strong></div>'
            . '<div class="lms-ui-tab-header-cell">'
            . ($q !== '' ? '<a href="?m=iptverrors&amp;view=devices&amp;q=' . urlencode($q) . '">' . $esc(trans('Szczegóły')) . ' &rarr;</a>' : '')
            . '</div>'
            . '</div>'
            . '<div class="lms-ui-tab-contents" id="iptverrorscustomerpanel" style="display:block">'
            . '<div class="lms-ui-tab-table">' . $rows . '</div>'
            . '<div style="padding:4px 8px;color:#666;font-size:12px">' . $summary . '<br>' . $cache_note . '</div>'
            . '</div>'
            . '</div>';
    }

    /**
     * Wiersz boxa na całą szerokość (komunikat).
     */
    private static function boxRow($content, $cols)
    {
        return '<div class="lms-ui-tab-table-row"><div class="lms-ui-tab-table-wrapper col-' . (int) $cols
            . '"><div class="lms-ui-tab-table-column">' . $content . '</div></div></div>';
    }

    /**
     * Odczyt pliku cache JSON (tylko odczyt, bez blokad i bez wywołań API).
     */
    private static function readCacheJson($file)
    {
        if (!is_readable($file)) {
            return null;
        }
        $json = json_decode((string) file_get_contents($file), true);
        return is_array($json) ? $json : null;
    }
}
