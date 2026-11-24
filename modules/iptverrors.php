<?php

require_once PLUGINS_DIR . '/LMSIptvErrorsPlugin/src/IptvMetrics.php';
require_once PLUGINS_DIR . '/LMSIptvErrorsPlugin/src/IptvAlerts.php';

const IPTV_BASELINE_MIN_AGE = 1800; // porównanie z migawką starszą niż 30 min, najbliższą 1 h
const IPTV_BASELINE_TARGET = 3600;

// Widok: pusty = podsumowanie dla NOC (jeden ekran), reszta to szczegóły.
$mode = in_array($_GET['view'] ?? '', array('subnets', 'devices', 'streams', 'models', 'emg'), true) ? $_GET['view'] : '';
// Zapytania do LMS o klientów tylko tam, gdzie tabela z klientami jest pokazywana.
$heavy = in_array($mode, array('devices', 'emg'), true)
    || (($_GET['action'] ?? '') === 'csv' && ($_GET['t'] ?? '') === 'devices');

$client = new IptvMetrics();
$result = $client->hasKey() ? $client->get(isset($_GET['refresh'])) : null;
$data = $result['data'] ?? null;

$now = time();
$history = $client->hasKey() ? $client->history() : array();

// Migawka odniesienia (ok. 1 h temu) do kolumny zmian.
$baseline = null;
foreach ($history as $snap) {
    $age = $now - (int) $snap['t'];
    if ($age >= IPTV_BASELINE_MIN_AGE
        && ($baseline === null || abs($age - IPTV_BASELINE_TARGET) < abs(($now - (int) $baseline['t']) - IPTV_BASELINE_TARGET))) {
        $baseline = $snap;
    }
}
$baseline_min = $baseline ? (int) round(($now - (int) $baseline['t']) / 60) : null;

$delta = function ($key, $value) use ($baseline) {
    if ($baseline === null) {
        return null;
    }
    return (int) $value - (int) ($baseline['v'][$key] ?? 0);
};

// Mini wykres z historii 24 h + bieżąca wartość.
$spark = function ($key, $value) use ($history) {
    if (count($history) < 2) {
        return '';
    }
    $vals = array();
    foreach ($history as $snap) {
        $vals[] = (int) ($snap['v'][$key] ?? 0);
    }
    $vals[] = (int) $value;
    $max = max($vals);
    if ($max <= 0) {
        return '';
    }
    $w = 96;
    $h = 22;
    $n = count($vals);
    $pts = array();
    foreach ($vals as $i => $v) {
        $pts[] = round($i * $w / max(1, $n - 1), 1) . ',' . round($h - 1 - ($v / $max) * ($h - 3), 1);
    }
    return implode(' ', $pts);
};

$view = array(
    'mode' => $mode,
    'has_key' => $client->hasKey(),
    'error' => $result['error'] ?? null,
    'stale' => $result['stale'] ?? false,
    'fetched' => $result['fetched'] ?? null,
    'age' => isset($result['fetched']) ? $now - (int) $result['fetched'] : null,
    'has_data' => $data !== null,
    'baseline_min' => $baseline_min,
    'history_count' => count($history),
    'snapshot_age' => $history ? $now - (int) end($history)['t'] : null,
    'history_hours' => count($history) > 1 ? round(($now - (int) $history[0]['t']) / 3600, 1) : 0,
);

if ($data !== null) {
    // Wartość błędów dekodera powyżej progu to odstająca (np. 1919252014 w zdarzeniu SGT). Nie liczymy jej jako błędów.
    $anomaly_min = 1000;

    $stb = $client->getStbRegistry(isset($_GET['refresh']));
    $registered = array();
    foreach ($stb['map'] as $r) {
        $registered[$r['model']] = ($registered[$r['model']] ?? 0) + 1;
    }
    $registered_total = array_sum($registered);
    $overall_rate = $registered_total > 0 ? $data['total_errors'] * 100 / $registered_total : null;

    $models = array();
    foreach ($data['models'] as $name => $m) {
        $reg = $registered[$name] ?? 0;
        $models[] = array(
            'name' => $name, 'errors' => $m['errors'], 'emergency' => $m['emergency'],
            'registered' => $reg,
            'rate' => $reg > 0 ? round($m['errors'] * 100 / $reg, 1) : null,
            'delta' => $delta('model:' . $name, $m['errors']),
            'spark' => $spark('model:' . $name, $m['errors']),
            'share' => $data['total_errors'] > 0 ? round($m['errors'] * 100 / $data['total_errors']) : 0,
        );
    }
    usort($models, function ($a, $b) {
        return [$b['errors'], $b['emergency']] <=> [$a['errors'], $a['emergency']];
    });

    $subnets = array();
    foreach ($data['subnets'] as $cidr => $n) {
        $num = '';
        $label = $n['name'];
        if (preg_match('/^\s*(\d+)\s*-\s*(.*)$/u', $n['name'], $mm)) {
            $num = $mm[1];
            $label = $mm[2];
        }
        $subnets[] = array(
            'cidr' => $cidr, 'num' => $num, 'label' => $label,
            'errors' => $n['errors'], 'emergency' => $n['emergency'],
            'delta' => $delta('subnet:' . $cidr, $n['errors']),
            'spark' => $spark('subnet:' . $cidr, $n['errors']),
        );
    }
    usort($subnets, function ($a, $b) {
        return [$b['emergency'], $b['errors']] <=> [$a['emergency'], $a['errors']];
    });

    // API zwraca czasem total=0 przy awarii w modelu/podsieci, więc bierzemy największą z trzech wartości.
    $emergency_total = max(
        $data['total_emergency'],
        array_sum(array_column($models, 'emergency')),
        array_sum(array_column($subnets, 'emergency'))
    );

    $streams = array();
    foreach ($data['streams'] as $group => $errors) {
        $streams[] = array(
            'group' => $group, 'errors' => $errors,
            'delta' => $delta('stream:' . $group, $errors),
            'spark' => $spark('stream:' . $group, $errors),
        );
    }
    usort($streams, function ($a, $b) {
        return $b['errors'] <=> $a['errors'] ?: strnatcmp($a['group'], $b['group']);
    });

    // Wnioski: tylko fakty wynikające z danych.
    $insights = array();
    $subnet_sum = array_sum(array_column($subnets, 'errors'));
    $with_err = count(array_filter($subnets, function ($s) {
        return $s['errors'] > 0;
    }));
    if ($data['total_errors'] === 0 && $subnet_sum === 0) {
        $insights[] = array('ok', trans('Brak dekoderów z błędami.'));
    }
    if ($emergency_total > 0) {
        $emg_models = array();
        foreach ($models as $m) {
            if ($m['emergency'] > 0) {
                $emg_models[] = $m['name'] . ' (' . $m['emergency'] . ')';
            }
        }
        $emg_subnets = array();
        foreach ($subnets as $s) {
            if ($s['emergency'] > 0) {
                $emg_subnets[] = ($s['num'] !== '' ? $s['num'] . ' ' : '') . $s['label'] . ' (' . $s['emergency'] . ')';
            }
        }
        $insights[] = array('bad', sprintf(
            trans('Dekodery w stanie awaryjnym: %d. Modele: %s.'),
            $emergency_total,
            implode(', ', $emg_models)
        ) . ($emg_subnets ? ' ' . trans('Podsieci') . ': ' . implode(', ', $emg_subnets) . '.' : ''));
    }
    // Model może dominować tylko dlatego, że jest liczny. Porównujemy stawkę błędów (błędy / zarejestrowane dekodery) ze średnią.
    $dominant_by_size = $models && $data['total_errors'] > 0 && $models[0]['share'] >= 50
        && $models[0]['rate'] !== null && $overall_rate > 0 && $models[0]['rate'] <= $overall_rate * 1.2;
    if ($dominant_by_size) {
        $insights[] = array('info', sprintf(
            trans('Model %s ma %d%% błędów, ale to %d%% zarejestrowanych dekoderów. Stawka błędów (%s%%) jest zbliżona do średniej (%s%%). To nie wskazuje na wadę modelu.'),
            $models[0]['name'], $models[0]['share'], round($models[0]['registered'] * 100 / $registered_total),
            $models[0]['rate'], round($overall_rate, 1)
        ));
    } elseif ($models && $data['total_errors'] > 0 && $models[0]['share'] >= 50) {
        $insights[] = array('warn', sprintf(
            trans('Model %s ma %d%% wszystkich błędów dekoderów (%d z %d).'),
            $models[0]['name'], $models[0]['share'], $models[0]['errors'], $data['total_errors']
        ));
    }
    if ($subnet_sum > 0) {
        $top3 = array_slice(array_column($subnets, 'errors'), 0, 3);
        rsort($top3);
        $top = $subnets;
        usort($top, function ($a, $b) {
            return $b['errors'] <=> $a['errors'];
        });
        $top3 = array_slice($top, 0, 3);
        $names = array_map(function ($s) {
            return ($s['num'] !== '' ? $s['num'] . ' ' : '') . $s['label'] . ' (' . $s['errors'] . ')';
        }, $top3);
        $insights[] = array('info', sprintf(
            trans('Błędy w %d z %d podsieci. Trzy największe to %d%% błędów podsieci: %s.'),
            $with_err, count($subnets), round(array_sum(array_column($top3, 'errors')) * 100 / $subnet_sum), implode('; ', $names)
        ));
    }
    $stream_sum = array_sum(array_column($streams, 'errors'));
    if ($stream_sum > 0) {
        $with_stream = count(array_filter($streams, function ($s) {
            return $s['errors'] > 0;
        }));
        $insights[] = array('info', sprintf(
            trans('Błędy w %d z %d grup multicast. Najwięcej: %s (%d błędów, %d%%).'),
            $with_stream, count($streams), $streams[0]['group'], $streams[0]['errors'], round($streams[0]['errors'] * 100 / $stream_sum)
        ));
    }
    if ($baseline !== null) {
        $rising = array();
        foreach ($subnets as $s) {
            if ($s['delta'] !== null && $s['delta'] >= 5 && $s['delta'] * 100 >= 20 * max(1, $s['errors'] - $s['delta'])) {
                $rising[] = ($s['num'] !== '' ? $s['num'] . ' ' : '') . $s['label'] . ' (+' . $s['delta'] . ')';
            }
        }
        if ($rising) {
            $insights[] = array('warn', sprintf(trans('Wzrost błędów w podsieciach od ok. %d min: %s.'), $baseline_min, implode('; ', $rising)));
        }
    }

    // Co sprawdzić: podpowiedzi z progami, wyłącznie na podstawie rozkładu danych.
    $hints = array();
    $share_subnets = count($subnets) ? round($with_err * 100 / count($subnets)) : 0;
    $with_stream_n = count(array_filter($streams, function ($x) {
        return $x['errors'] > 0;
    }));
    $share_streams = count($streams) ? round($with_stream_n * 100 / count($streams)) : 0;
    if ($subnet_sum > 0 && $share_subnets >= 75 && $share_streams >= 75) {
        $hints[] = sprintf(
            trans('Błędy są w %d%% podsieci i %d%% grup multicast. Taki rozkład sugeruje przyczynę wspólną. Sprawdź źródło sygnału, rdzeń sieci multicast i dekodery, zanim zaczniesz od pojedynczej podsieci.'),
            $share_subnets, $share_streams
        );
    }
    if ($subnet_sum > 0) {
        $top_sub = $subnets;
        usort($top_sub, function ($a, $b) {
            return $b['errors'] <=> $a['errors'];
        });
        $sh = round($top_sub[0]['errors'] * 100 / $subnet_sum);
        if ($sh >= 20) {
            $hints[] = sprintf(
                trans('Podsieć %s (%s) ma %d%% błędów podsieci. Sprawdź OLT lub przełącznik tej podsieci i jego uplink.'),
                $top_sub[0]['label'], $top_sub[0]['cidr'], $sh
            );
        }
    }
    if ($stream_sum > 0 && round($streams[0]['errors'] * 100 / $stream_sum) >= 15) {
        $hints[] = sprintf(
            trans('Grupa %s ma %d%% błędów strumieni. Sprawdź źródło i enkoder tego kanału.'),
            $streams[0]['group'], round($streams[0]['errors'] * 100 / $stream_sum)
        );
    }
    if (!$dominant_by_size && $models && $data['total_errors'] > 0 && $models[0]['share'] >= 50) {
        $hints[] = sprintf(
            trans('Model %s dominuje (%d%%). Porównaj wersję firmware i ustawienia tych dekoderów z pozostałymi modelami.'),
            $models[0]['name'], $models[0]['share']
        );
    }
    if ($registered_total > 0 && $data['total_errors'] > $registered_total) {
        $hints[] = sprintf(
            trans('Suma błędów (%d) jest większa niż liczba zarejestrowanych dekoderów (%d). To prawdopodobnie błąd licznika SGT, nie prawdziwa awaria. Nie traktuj tej sumy jako liczby dekoderów.'),
            $data['total_errors'], $registered_total
        );
    }
    if ($emergency_total > 0) {
        $hints[] = trans('Dekoder w stanie awaryjnym: sprawdź go osobno w portalu SGT. Stan awaryjny to cięższy problem niż błędy.');
    }
    if (!$hints && $data['total_errors'] > 0) {
        $hints[] = trans('Brak wyraźnego wzorca w rozkładzie błędów. Obserwuj kolumnę Δ i wykresy 24 h.');
    }

    // Dekodery z /api/errors (ostatnie ~200 zdarzeń), pogrupowane po MAC.
    $ev = $client->getEvents(isset($_GET['refresh']));
    $by_mac = array();
    foreach ($ev['events'] as $e) {
        $m = &$by_mac[$e['mac']];
        if (!isset($m)) {
            $m = array('mac' => $e['mac'], 'ip' => $e['ip'], 'model' => $e['model'], 'errors' => 0, 'anomaly' => 0, 'events' => 0, 'groups' => array(), 'ts' => 0);
        }
        $m['events']++;
        if ($e['errors'] > $anomaly_min) {
            $m['anomaly'] = max($m['anomaly'], $e['errors']);
        } else {
            $m['errors'] = max($m['errors'], $e['errors']);
        }
        $m['groups'][$e['group']] = true;
        if ($e['ts'] >= $m['ts']) {
            $m['ts'] = $e['ts'];
            $m['ip'] = $e['ip'] ?: $m['ip'];
        }
        unset($m);
    }

    $in_cidr = function ($ip, $cidr) {
        list($net, $bits) = explode('/', $cidr) + array('', '32');
        $ipl = ip2long($ip);
        $netl = ip2long($net);
        if ($ipl === false || $netl === false || !ctype_digit((string) $bits) || (int) $bits > 32) {
            return false;
        }
        $mask = $bits == 0 ? 0 : (~((1 << (32 - (int) $bits)) - 1) & 0xFFFFFFFF);
        return ($ipl & $mask) === ($netl & $mask);
    };

    // Klient w LMS: najpierw po MAC, potem po IP (dekodery IPTV zwykle nie są w LMS, więc to dopasowanie bywa puste).
    $owner_by_mac = array();
    $owner_by_ip = array();
    $macs_ok = array_filter(array_keys($by_mac), function ($x) {
        return preg_match('/^[0-9a-f]{2}(:[0-9a-f]{2}){5}$/', $x);
    });
    if ($heavy && $macs_ok) {
        $in = "'" . implode("','", $macs_ok) . "'";
        foreach ($DB->GetAll("SELECT lower(x.mac) AS mac, n.ownerid, c.lastname, c.name
            FROM macs x JOIN nodes n ON n.id = x.nodeid JOIN customers c ON c.id = n.ownerid
            WHERE n.ownerid IS NOT NULL AND lower(x.mac) IN ($in)") ?: array() as $r) {
            $owner_by_mac[$r['mac']] = array('id' => $r['ownerid'], 'name' => trim($r['lastname'] . ' ' . $r['name']));
        }
    }
    $ips_long = array();
    foreach ($by_mac as $m) {
        $l = ip2long($m['ip']);
        if ($l !== false) {
            $ips_long[$l] = $m['ip'];
        }
    }
    if ($heavy && $ips_long) {
        $in = implode(',', array_map('intval', array_keys($ips_long)));
        foreach ($DB->GetAll("SELECT n.ipaddr, n.ownerid, c.lastname, c.name
            FROM nodes n JOIN customers c ON c.id = n.ownerid
            WHERE n.ownerid IS NOT NULL AND n.ipaddr IN ($in)") ?: array() as $r) {
            $owner_by_ip[(int) $r['ipaddr']] = array('id' => $r['ownerid'], 'name' => trim($r['lastname'] . ' ' . $r['name']));
        }
    }

    // Dekodery klientów z SMS SGT (przez LMSJamboxPlugin): MAC => numer klienta w SMS => klient LMS (customers.tv_cust_number).
    $cust_by_number = array();
    $numbers = array();
    foreach ($by_mac as $mac => $m) {
        $n = $stb['map'][$mac]['cust_number'] ?? '';
        if ($n !== '' && ctype_digit($n)) {
            $numbers[$n] = true;
        }
    }
    if ($heavy && $numbers) {
        $in = "'" . implode("','", array_keys($numbers)) . "'";
        foreach ($DB->GetAll("SELECT id, tv_cust_number, lastname, name FROM customers WHERE deleted = 0 AND tv_cust_number IN ($in)") ?: array() as $r) {
            $cust_by_number[$r['tv_cust_number']] = array('id' => $r['id'], 'name' => trim($r['lastname'] . ' ' . $r['name']));
        }
    }

    $devices = array();
    foreach ($by_mac as $m) {
        $label = '';
        // Przy nakładających się podsieciach wygrywa najdłuższy prefiks.
        $best_bits = -1;
        foreach ($subnets as $sn) {
            $bits = (int) (explode('/', $sn['cidr'])[1] ?? 32);
            if ($bits > $best_bits && $m['ip'] !== '' && $in_cidr($m['ip'], $sn['cidr'])) {
                $label = ($sn['num'] !== '' ? $sn['num'] . ' ' : '') . $sn['label'];
                $best_bits = $bits;
            }
        }
        $ipl = ip2long($m['ip']);
        $sms = $stb['map'][$m['mac']] ?? null;
        $owner = ($sms && isset($cust_by_number[$sms['cust_number']])) ? $cust_by_number[$sms['cust_number']]
            : ($owner_by_mac[$m['mac']] ?? ($ipl !== false ? ($owner_by_ip[$ipl] ?? null) : null));
        $groups = array_keys($m['groups']);
        usort($groups, 'strnatcmp');
        $devices[] = array(
            'mac' => strtoupper($m['mac']), 'ip' => $m['ip'], 'model' => $m['model'], 'subnet' => $label,
            'errors' => $m['errors'], 'anomaly' => $m['anomaly'], 'events' => $m['events'], 'groups' => $groups, 'ts' => $m['ts'],
            'customer_id' => $owner['id'] ?? null, 'customer_name' => $owner['name'] ?? null,
            'groups_n' => count($groups),
            'sms_number' => $sms['cust_number'] ?? '',
            'serial' => $sms['serial'] ?? '',
            'broken' => $sms['broken'] ?? false,
            'in_sms' => $sms !== null,
            'search' => mb_strtolower($m['mac'] . ' ' . $m['ip'] . ' ' . $m['model'] . ' ' . $label . ' ' . implode(' ', $groups) . ' ' . ($owner['name'] ?? '') . ' ' . ($sms['cust_number'] ?? '') . ' ' . ($sms['serial'] ?? ''), 'UTF-8'),
        );
    }
    usort($devices, function ($a, $b) {
        return [$b['errors'], $b['events']] <=> [$a['errors'], $a['events']] ?: strcmp($a['mac'], $b['mac']);
    });
    $devices_matched = count(array_filter($devices, function ($d) {
        return $d['customer_id'] !== null;
    }));
    $broken_n = count(array_filter($devices, function ($d) {
        return $d['broken'];
    }));
    if ($broken_n > 0) {
        $hints[] = sprintf(trans('%d dekoderów z błędami ma w SMS oznaczenie „uszkodzony". Rozważ ich wymianę.'), $broken_n);
    }
    $many_groups = count(array_filter($devices, function ($d) {
        return count($d['groups']) >= 5;
    }));
    if ($many_groups > 0) {
        $hints[] = sprintf(
            trans('%d dekoderów zgłasza błędy w co najmniej 5 różnych grupach multicast. Błędy w wielu kanałach naraz wskazują raczej na dekoder lub łącze klienta niż na jeden kanał. Zacznij od tabeli dekoderów.'),
            $many_groups
        );
    }

    // Dekodery w stanie awaryjnym: API podaje tylko liczby (model, podsieć), nie MAC. Kandydaci to dekodery z SMS SGT
    // o modelu i adresie IP pasującym do modelu i podsieci z awarią. To założenie, nie dane z API.
    $emg_models = array();
    foreach ($models as $mo) {
        if ($mo['emergency'] > 0) {
            $emg_models[$mo['name']] = true;
        }
    }
    $emg_subnets = array_values(array_filter($subnets, function ($sn) {
        return $sn['emergency'] > 0;
    }));
    $with_errors_mac = array();
    foreach ($devices as $d) {
        $with_errors_mac[strtolower($d['mac'])] = $d['errors'];
    }
    $candidates = array();
    if ($emg_models && $emg_subnets) {
        foreach ($stb['map'] as $mac => $r) {
            if (empty($emg_models[$r['model'] ?? '']) || ($r['stb_ip'] ?? '') === '') {
                continue;
            }
            foreach ($emg_subnets as $sn) {
                if ($in_cidr($r['stb_ip'], $sn['cidr'])) {
                    $candidates[] = array(
                        'mac' => strtoupper($mac), 'ip' => $r['stb_ip'], 'model' => $r['model'], 'serial' => $r['serial'],
                        'subnet' => ($sn['num'] !== '' ? $sn['num'] . ' ' : '') . $sn['label'],
                        'sms_number' => $r['cust_number'], 'broken' => $r['broken'],
                        'errors' => $with_errors_mac[$mac] ?? 0,
                    );
                    break;
                }
            }
        }
        $cnums = array();
        foreach ($candidates as $c) {
            if ($c['sms_number'] !== '' && ctype_digit($c['sms_number'])) {
                $cnums[$c['sms_number']] = true;
            }
        }
        $cust_c = array();
        if ($heavy && $cnums) {
            $in = "'" . implode("','", array_keys($cnums)) . "'";
            foreach ($DB->GetAll("SELECT id, tv_cust_number, lastname, name FROM customers WHERE deleted = 0 AND tv_cust_number IN ($in)") ?: array() as $r) {
                $cust_c[$r['tv_cust_number']] = array('id' => $r['id'], 'name' => trim($r['lastname'] . ' ' . $r['name']));
            }
        }
        foreach ($candidates as &$c) {
            $c['customer_id'] = $cust_c[$c['sms_number']]['id'] ?? null;
            $c['customer_name'] = $cust_c[$c['sms_number']]['name'] ?? null;
            $c['search'] = mb_strtolower($c['mac'] . ' ' . $c['ip'] . ' ' . $c['model'] . ' ' . $c['subnet'] . ' ' . $c['serial'] . ' ' . ($c['customer_name'] ?? '') . ' ' . $c['sms_number'], 'UTF-8');
        }
        unset($c);
        // Najpierw dekodery z błędami w ostatnich zdarzeniach, potem uszkodzone w SMS.
        usort($candidates, function ($a, $b) {
            return [$b['errors'], (int) $b['broken']] <=> [$a['errors'], (int) $a['broken']] ?: strcmp($a['ip'], $b['ip']);
        });
    }

    // Podsumowanie dla NOC: tylko to, co wymaga reakcji. Progi w src/IptvAlerts.php (z historii migawek).
    $alerts = array();
    $baseline_ok = IptvAlerts::baselineOk($history, $now);
    $normal = IptvAlerts::normalRange($history);
    $spike = array('level' => null, 'median' => 0, 'ratio' => 0);
    $subnet_alerts = array();
    $stream_alerts = array();
    $subnet_label = function ($cidr) use ($subnets) {
        foreach ($subnets as $sn) {
            if ($sn['cidr'] === $cidr) {
                return ($sn['num'] !== '' ? $sn['num'] . ' ' : '') . $sn['label'];
            }
        }
        return $cidr;
    };
    if ($baseline_ok) {
        $spike = IptvAlerts::totalSpike($history, $data['total_errors']);
        if ($spike['level'] !== null) {
            $alerts[] = array(
                'level' => $spike['level'] === 'bad' ? 'bad' : 'warn', 'view' => 'subnets',
                'text' => sprintf(
                    trans('Skok sumy błędów: %d, czyli %s razy więcej niż mediana z ostatniej godziny (%d). Jeśli rośnie we wszystkich podsieciach naraz, szukaj wspólnej przyczyny lub błędu licznika SGT.'),
                    $data['total_errors'], round($spike['ratio'], 1), $spike['median']
                ),
            );
        }
        $cur = array();
        foreach ($subnets as $sn) {
            $cur[$sn['cidr']] = $sn['errors'];
        }
        foreach (array_slice(IptvAlerts::excess($cur, $data['total_errors'], $history, $now, 'subnet:', 10, 2, 3), 0, 3) as $a) {
            $subnet_alerts[] = $a;
            $alerts[] = array(
                'level' => $a['persisted'] ? 'bad' : 'warn', 'view' => 'subnets',
                'text' => sprintf(
                    $a['persisted'] ? trans('Podsieć %s: %d błędów, %s razy więcej niż zwykle, od co najmniej 2 odczytów.')
                        : trans('Podsieć %s: %d błędów, %s razy więcej niż zwykle. Obserwuj.'),
                    $subnet_label($a['key']), $a['errors'], round($a['ratio'], 1)
                ),
            );
        }
        $cur = array();
        foreach ($streams as $st) {
            $cur[$st['group']] = $st['errors'];
        }
        foreach (array_slice(IptvAlerts::excess($cur, $data['total_errors'], $history, $now, 'stream:', 40, 3, 3), 0, 2) as $a) {
            if ($a['persisted']) {
                $stream_alerts[] = $a;
                $alerts[] = array(
                    'level' => 'warn', 'view' => 'streams',
                    'text' => sprintf(trans('Grupa %s: %d błędów, %s razy więcej niż zwykle, od co najmniej 2 odczytów. Sprawdź źródło tego kanału.'), $a['key'], $a['errors'], round($a['ratio'], 1)),
                );
            }
        }
    }
    foreach ($emg_subnets as $sn) {
        $dur = IptvAlerts::emergencyDuration($history, 'subnet_emg:' . $sn['cidr'], $now);
        $mins = intdiv($dur['seconds'], 60);
        $dur_txt = $dur['seconds'] > 0 ? ($dur['full'] ? sprintf(trans('Trwa co najmniej %d h.'), intdiv($mins, 60))
            : sprintf(trans('Trwa %d h %d min.'), intdiv($mins, 60), $mins % 60)) : '';
        $alerts[] = array(
            'level' => 'warn', 'view' => 'emg',
            'text' => sprintf(
                trans('Dekoder w stanie awaryjnym: %d, podsieć %s, modele: %s. API nie podaje MAC, kandydatów jest %d. %s'),
                $sn['emergency'], ($sn['num'] !== '' ? $sn['num'] . ' ' : '') . $sn['label'],
                $emg_models ? implode(', ', array_keys($emg_models)) : '-', count($candidates), $dur_txt
            ),
        );
    }
    // Dekodery z dużą liczbą błędów w jednym odczycie. Progi 3 odczytów lub 5 grup dawały stały szum (15 z 104 dekoderów).
    // Pozycja informacyjna: nie zmienia koloru statusu.
    $heavy_devices = count(array_filter($devices, function ($d) {
        return $d['errors'] >= 20;
    }));
    if ($heavy_devices > 0) {
        $alerts[] = array(
            'level' => 'info', 'view' => 'devices',
            'text' => sprintf(trans('Dekoderów z co najmniej 20 błędami w jednym odczycie: %d. To raczej problem dekodera lub łącza klienta niż kanału.'), $heavy_devices),
        );
    }
    $red = ($spike['level'] === 'bad') || count(array_filter($subnet_alerts, function ($a) {
        return $a['persisted'];
    })) > 0;
    if ($red) {
        $status = 'red';
        $status_text = trans('Wymaga reakcji.');
    } elseif (count(array_filter($alerts, function ($a) {
        return $a['level'] !== 'info';
    })) > 0) {
        $status = 'yellow';
        $status_text = trans('Są pozycje do sprawdzenia.');
    } elseif (!$baseline_ok) {
        $status = 'gray';
        $status_text = trans('Za mało historii (mniej niż 6 h) do oceny progów. Alarmy z progów włączą się po zebraniu historii.');
    } else {
        $status = 'green';
        $status_text = trans('Brak anomalii. Szum w normie.');
    }

    $view += array(
        'status' => $status,
        'status_text' => $status_text,
        'alerts' => $alerts,
        'baseline_ok' => $baseline_ok,
        'normal' => $normal,
        'subnets_alert_count' => count($subnet_alerts),
        'streams_alert_count' => count($stream_alerts),
        'heavy_devices' => $heavy_devices,
        'total_spark' => $spark('total_errors', $data['total_errors']),
        'devices' => $devices,
        'emergency_candidates' => $candidates,
        'devices_events' => count($ev['events']),
        'devices_count' => count($devices),
        'devices_matched' => $devices_matched,
        'devices_in_sms' => count(array_filter($devices, function ($d) {
            return $d['in_sms'];
        })),
        'sms_available' => $stb['available'],
        'sms_error' => $stb['error'],
        'broken_count' => count(array_filter($devices, function ($d) {
            return $d['broken'];
        })),
        'customers_count' => count(array_unique(array_filter(array_column($devices, 'sms_number')))),
        'devices_error' => $ev['error'],
        'hints' => $hints,
        'total_errors' => $data['total_errors'],
        'total_emergency' => $emergency_total,
        'total_errors_delta' => $delta('total_errors', $data['total_errors']),
        'subnets_with_errors' => $with_err,
        'subnets_total' => count($subnets),
        'streams_with_errors' => count(array_filter($streams, function ($s) {
            return $s['errors'] > 0;
        })),
        'streams_total' => count($streams),
        'models' => $models,
        'subnets' => $subnets,
        'streams' => $streams,
        'max_model' => max(1, max(array_column($models, 'errors') ?: array(1))),
        'max_subnet' => max(1, max(array_column($subnets, 'errors') ?: array(1))),
        'max_stream' => max(1, max(array_column($streams, 'errors') ?: array(1))),
        'insights' => $insights,
    );
}

if (($_GET['action'] ?? '') === 'csv' && $data !== null) {
    $table = $_GET['t'] ?? 'subnets';
    $out_rows = array();
    $fmt = function ($d) {
        return $d === null ? '' : $d;
    };
    if ($table === 'devices') {
        $head = array('MAC', 'IP', 'Model', 'Serial', 'Podsieć', 'Błędy (max)', 'Zdarzeń', 'Grupy multicast', 'Ostatnio', 'Klient LMS', 'Nr SMS', 'Uszkodzony w SMS', 'Wartość odstająca');
        foreach ($view['devices'] as $r) {
            $out_rows[] = array($r['mac'], $r['ip'], $r['model'], $r['serial'], $r['subnet'], $r['errors'], $r['events'], implode(' ', $r['groups']), $r['ts'] ? date('Y-m-d H:i:s', $r['ts']) : '', $r['customer_name'] ?? '', $r['sms_number'], $r['broken'] ? 'tak' : '', $r['anomaly'] ?: '');
        }
    } elseif ($table === 'streams') {
        $head = array('Grupa', 'Błędy', 'Zmiana (Δ)');
        foreach ($view['streams'] as $r) {
            $out_rows[] = array($r['group'], $r['errors'], $fmt($r['delta']));
        }
    } elseif ($table === 'models') {
        $head = array('Model', 'Błędy', 'Udział %', 'Zarejestrowane', 'Stawka błędów %', 'Awarie', 'Zmiana (Δ)');
        foreach ($view['models'] as $r) {
            $out_rows[] = array($r['name'], $r['errors'], $r['share'], $r['registered'], $r['rate'] ?? '', $r['emergency'], $fmt($r['delta']));
        }
    } else {
        $table = 'subnets';
        $head = array('Nr', 'Lokalizacja', 'Podsieć', 'Błędy', 'Awarie', 'Zmiana (Δ)');
        foreach ($view['subnets'] as $r) {
            $out_rows[] = array($r['num'], $r['label'], $r['cidr'], $r['errors'], $r['emergency'], $fmt($r['delta']));
        }
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="iptv-' . $table . '-' . date('Ymd-Hi', (int) $view['fetched']) . '.csv"');
    $fh = fopen('php://output', 'w');
    fwrite($fh, "\xEF\xBB\xBF");
    fputcsv($fh, $head, ';');
    // Wartości zaczynające się od = + - @ mogą być wykonane jako formuła w arkuszu. Dopisz apostrof.
    $safe = function ($v) {
        return is_string($v) && $v !== '' && strpos("=+-@\t\r", $v[0]) !== false ? "'" . $v : $v;
    };
    foreach ($out_rows as $r) {
        fputcsv($fh, array_map($safe, $r), ';');
    }
    fclose($fh);
    exit;
}

$layout['pagetitle'] = trans('Błędy IPTV');
$SMARTY->assign('iptv', $view);
$SMARTY->display('iptverrors.html');
