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
 * Szereg czasowy błędów per dekoder (do wykresu 24 h w UI):
 * punkty z historii migawek (IptvMetrics::getDeviceSeries) + bieżąca wartość
 * z events.json (/api/errors), mini-wykres SVG (sparkline) i podsumowanie
 * min/max/avg z trendem. Tylko PHP, bez zależności.
 */
class IptvTimeline
{
    /**
     * Punkty szeregu [{t, errors}] dla MAC-a: historia 24 h (co 5 min)
     * + bieżąca wartość z events.json dopięta jako ostatni punkt.
     * Bieżąca wartość to max zdarzeń tego MAC-a (jak w widoku dekoderów);
     * punkt ląduje tylko, gdy jest nowszy niż ostatnia migawka (przy tym
     * samym t zastępuje ostatni punkt, żeby nie było dubla).
     *
     * @param IptvMetrics $m
     * @param string $mac adres MAC (dowolny format, patrz IptvMetrics::normalizeMac)
     * @param int $hours ile godzin wstecz z historii
     * @return array lista ['t' => int, 'errors' => int]
     */
    public static function seriesForMac(IptvMetrics $m, $mac, $hours = 24)
    {
        $mac = IptvMetrics::normalizeMac($mac);
        $points = $m->getDeviceSeries($mac, $hours);

        $current = 0;
        $currentTs = 0;
        foreach ((array) ($m->getEvents()['events'] ?? array()) as $e) {
            if (IptvMetrics::normalizeMac($e['mac'] ?? '') !== $mac) {
                continue;
            }
            $current = max($current, (int) ($e['errors'] ?? 0));
            $currentTs = max($currentTs, (int) ($e['ts'] ?? 0));
        }
        if ($currentTs > 0) {
            $t = max($currentTs, time());
            $last = $points ? end($points) : null;
            if ($last && (int) $last['t'] === $t) {
                $points[count($points) - 1] = array('t' => $t, 'errors' => $current);
            } elseif (!$last || (int) $last['t'] < $t) {
                $points[] = array('t' => $t, 'errors' => $current);
            }
        }
        return $points;
    }

    /**
     * Lekki SVG polyline z punktów [{t, errors}] — logika jak $spark w module:
     * punkty równomiernie na szerokości, wartość w skali do max.
     * Puste stringi, gdy za mało punktów albo wszystkie wartości zerowe.
     *
     * @param array $points lista ['t' => int, 'errors' => int]
     * @param int $w szerokość viewBox
     * @param int $h wysokość viewBox
     * @return string SVG (lub '' gdy brak sensu rysować)
     */
    public static function sparklineSvg(array $points, $w = 96, $h = 22)
    {
        $vals = array();
        foreach ($points as $p) {
            $vals[] = (int) ($p['errors'] ?? 0);
        }
        $n = count($vals);
        if ($n < 2) {
            return '';
        }
        $max = max($vals);
        if ($max <= 0) {
            return '';
        }
        $w = max(1, (int) $w);
        $h = max(1, (int) $h);
        $pts = array();
        foreach ($vals as $i => $v) {
            $pts[] = round($i * $w / max(1, $n - 1), 1) . ',' . round($h - 1 - ($v / $max) * ($h - 3), 1);
        }
        return '<svg width="' . $w . '" height="' . $h . '" viewBox="0 0 ' . $w . ' ' . $h . '">'
            . '<polyline points="' . implode(' ', $pts) . '" fill="none" stroke="#3b6fb6" stroke-width="1.5"/>'
            . '</svg>';
    }

    /**
     * Podsumowanie szeregu (ostatnie 24 h): min/max/avg oraz trend —
     * porównanie średniej pierwszej i ostatniej ćwiartki punktów.
     * Trend: 'rising' gdy ostatnia ćwiartka wyraźnie wyżej (>= +10 %),
     * 'falling' gdy wyraźnie niżej (<= -10 %), w pozostałych przypadkach 'flat'.
     *
     * @param array $points lista ['t' => int, 'errors' => int]
     * @return array ['count', 'min', 'max', 'avg', 'first_quarter_avg', 'last_quarter_avg', 'trend']
     */
    public static function summary(array $points)
    {
        $vals = array();
        foreach ($points as $p) {
            $vals[] = (int) ($p['errors'] ?? 0);
        }
        $n = count($vals);
        if ($n === 0) {
            return array(
                'count' => 0, 'min' => 0, 'max' => 0, 'avg' => 0.0,
                'first_quarter_avg' => 0.0, 'last_quarter_avg' => 0.0, 'trend' => 'flat',
            );
        }
        $q = max(1, (int) floor($n / 4));
        $firstQ = array_slice($vals, 0, $q);
        $lastQ = array_slice($vals, -$q);
        $firstAvg = array_sum($firstQ) / count($firstQ);
        $lastAvg = array_sum($lastQ) / count($lastQ);

        if ($lastAvg >= $firstAvg * 1.1) {
            $trend = 'rising';
        } elseif ($lastAvg <= $firstAvg * 0.9) {
            $trend = 'falling';
        } else {
            $trend = 'flat';
        }
        // Gdy obie ćwiartki są zerowe, nie ma wzrostu mimo progu procentowego.
        if ($firstAvg == 0.0 && $lastAvg == 0.0) {
            $trend = 'flat';
        }

        return array(
            'count' => $n,
            'min' => min($vals),
            'max' => max($vals),
            'avg' => round(array_sum($vals) / $n, 2),
            'first_quarter_avg' => round($firstAvg, 2),
            'last_quarter_avg' => round($lastAvg, 2),
            'trend' => $trend,
        );
    }
}
