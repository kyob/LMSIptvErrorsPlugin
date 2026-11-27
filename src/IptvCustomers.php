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
 * Agregacja „klienci problematyczni" z gotowej tablicy $devices z heavy blocku
 * modules/iptverrors.php (każdy wiersz: customer_id, customer_name, errors, mac,
 * subnet, location, ...).
 *
 * Klient z więcej niż 1 dekoderem w błędach to zwykle problem po stronie klienta
 * (instalacja/dekodery), nie sieci — stąd osobną listę robimy dla stb_n >= min_stb.
 * Czysty PHP, bez zależności od LMS — klasa przyjmuje tylko dane wejściowe.
 */
class IptvCustomers
{
    /**
     * Grupuje dekodery per klient i zwraca agregację klientów problematycznych.
     *
     * @param array $devices tablica wierszy $devices z heavy blocku (klucze: customer_id,
     *                       customer_name, errors, mac, subnet, location)
     * @param int $minStb minimalna liczba dekodów w błędach, by klient trafił na listę
     *                    (problem po stronie klienta); domyślnie 2
     * @param int $singleStbErrors próg „dużej sumy błędów", przy którym klient z 1 dekoderem
     *                             też trafia na listę (pojedynczy uszkodzony dekoder/łącze)
     * @return array ['customers' => [...], 'stats' => ['total_customers_with_errors' => int, 'total_multi_stb' => int]]
     *               customers posortowani po errors_sum malejąco, tylko stb_n >= $minStb
     *               lub (stb_n >= 1 && errors_sum >= $singleStbErrors)
     */
    public static function fromDevices(array $devices, $minStb = 2, $singleStbErrors = 20)
    {
        $minStb = max(1, (int) $minStb);
        $singleStbErrors = max(0, (int) $singleStbErrors);

        // Grupowanie po customer_id; dekodery bez klienta (customer_id puste) odpadają —
        // nie da się ich przypisać do osoby, więc nie są „klientem problematycznym".
        $byCustomer = array();
        foreach ($devices as $d) {
            $cid = $d['customer_id'] ?? null;
            if ($cid === null || $cid === '' || $cid === 0) {
                continue;
            }
            $cid = (int) $cid;
            if (!isset($byCustomer[$cid])) {
                $byCustomer[$cid] = array(
                    'customer_id' => $cid,
                    'name' => '',
                    'stb_n' => 0,
                    'errors_sum' => 0,
                    'macs' => array(),
                    'subnets' => array(),
                    'location' => '',
                );
            }
            $c = &$byCustomer[$cid];
            $mac = strtoupper(trim((string) ($d['mac'] ?? '')));
            if ($mac !== '' && !in_array($mac, $c['macs'], true)) {
                $c['macs'][] = $mac;
                $c['stb_n']++;
            }
            $c['errors_sum'] += (int) ($d['errors'] ?? 0);
            $subnet = trim((string) ($d['subnet'] ?? ''));
            if ($subnet !== '' && !in_array($subnet, $c['subnets'], true)) {
                $c['subnets'][] = $subnet;
            }
            if ($c['name'] === '') {
                $c['name'] = trim((string) ($d['customer_name'] ?? ''));
            }
            if ($c['location'] === '') {
                $c['location'] = trim((string) ($d['location'] ?? ''));
            }
            unset($c);
        }

        // Statystyki liczymy na pełnej populacji klientów z błędami (przed filtrem min_stb).
        $totalWithErrors = 0;
        $totalMultiStb = 0;
        foreach ($byCustomer as $c) {
            if ($c['errors_sum'] > 0) {
                $totalWithErrors++;
            }
            if ($c['stb_n'] >= 2) {
                $totalMultiStb++;
            }
        }

        // Lista klientów problematycznych: stb_n >= min_stb LUB pojedynczy dekoder z dużą sumą błędów.
        $customers = array();
        foreach ($byCustomer as $c) {
            if ($c['stb_n'] >= $minStb || ($c['stb_n'] >= 1 && $c['errors_sum'] >= $singleStbErrors)) {
                sort($c['macs'], SORT_STRING);
                sort($c['subnets'], SORT_STRING);
                $customers[] = $c;
            }
        }
        usort($customers, function ($a, $b) {
            return [$b['errors_sum'], $b['stb_n'], $a['customer_id']]
                <=> [$a['errors_sum'], $a['stb_n'], $b['customer_id']];
        });

        return array(
            'customers' => $customers,
            'stats' => array(
                'total_customers_with_errors' => $totalWithErrors,
                'total_multi_stb' => $totalMultiStb,
            ),
        );
    }
}
