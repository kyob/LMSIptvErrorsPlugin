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
 * Porównanie dzień-do-dnia (Δ vs ta sama pora wczoraj) z historii migawek
 * (IptvMetrics::history(): [{t, v:{klucz: wartość}}], migawki co history_interval).
 *
 * Świeża instalacja ma w historii mniej niż 24 h — wtedy w oknie 23-25 h wstecz
 * nie ma żadnej migawki i yesterday/delta/pct wracają jako null
 * (UI pokazuje „brak danych sprzed 24 h”). Jako baza liczą się tylko wartości > 0:
 * flatten() zapisuje tylko wartości niezerowe, więc klucza z wartością 0
 * w migawce wczorajszej po prostu nie ma — traktujemy to jak brak bazy,
 * a nie jak skok od zera.
 */
class IptvDod
{
    const DAY = 86400;        // 24 h w sekundach: cel porównania
    const WINDOW_MIN = 82800; // 23 h: najbliższa dopuszczalna migawka „24 h temu”
    const WINDOW_MAX = 90000; // 25 h: najdalsza dopuszczalna migawka „24 h temu”

    /**
     * Porównanie jednego klucza: wartość teraz vs ~24 h temu
     * (najbliższa migawka w oknie 23-25 h wstecz).
     *
     * @param array $history migawki [{t, v:{klucz: wartość}}] z IptvMetrics::history()
     * @param string $key klucz płaskiej mapy, np. 'total_errors', 'subnet:10.0.0.0/24', 'stream:grupa'
     * @param int $now timestamp „teraz”
     * @return array ['now'=>int, 'yesterday'=>int|null, 'delta'=>int|null, 'pct'=>float|null]
     */
    public static function compare(array $history, $key, $now)
    {
        $now = (int) $now;

        $current = self::latestSnapshot($history, $now);
        $nowVal = (int) (self::values($current)[$key] ?? 0);

        $yesterday = null;
        $snap = self::snapshot24hAgo($history, $now);
        if ($snap !== null) {
            $base = (int) (self::values($snap)[$key] ?? 0);
            if ($base > 0) { // tylko wartości > 0 liczą się jako baza
                $yesterday = $base;
            }
        }

        $delta = $yesterday === null ? null : $nowVal - $yesterday;
        $pct = $yesterday === null ? null : round($delta / $yesterday * 100, 1);

        return array(
            'now' => $nowVal,
            'yesterday' => $yesterday,
            'delta' => $delta,
            'pct' => $pct,
        );
    }

    /**
     * Migawka najbliższa $now - 24 h w oknie 23-25 h wstecz; null, gdy w oknie
     * nie ma żadnej migawki (świeża instalacja, historia < 24 h).
     *
     * @return array|null migawka ['t'=>..., 'v'=>...] albo null
     */
    private static function snapshot24hAgo(array $history, $now)
    {
        $target = $now - self::DAY;
        $min = $now - self::WINDOW_MAX; // 25 h wstecz
        $max = $now - self::WINDOW_MIN; // 23 h wstecz
        $best = null;
        $bestDist = null;
        foreach ($history as $snap) {
            if (!is_array($snap)) {
                continue;
            }
            $t = (int) ($snap['t'] ?? 0);
            if ($t < $min || $t > $max) {
                continue;
            }
            $dist = abs($t - $target);
            if ($best === null || $dist < $bestDist) {
                $best = $snap;
                $bestDist = $dist;
            }
        }
        return $best;
    }

    /**
     * Najnowsza migawka nie nowsza niż $now; gdy wszystkie są „z przyszłości”
     * (przestawiony zegar), bierze ostatnią z tablicy. Pusta historia => pusta migawka.
     */
    private static function latestSnapshot(array $history, $now)
    {
        $latest = null;
        foreach ($history as $snap) {
            if (!is_array($snap)) {
                continue;
            }
            $t = (int) ($snap['t'] ?? 0);
            if ($t <= $now && ($latest === null || $t >= (int) ($latest['t'] ?? 0))) {
                $latest = $snap;
            }
        }
        if ($latest === null) {
            $last = $history ? end($history) : null;
            $latest = is_array($last) ? $last : array('t' => 0, 'v' => array());
        }
        return $latest;
    }

    /**
     * Mapa wartości migawki (pole 'v'); nie-tablica => pusta mapa.
     */
    private static function values(array $snap)
    {
        $v = $snap['v'] ?? array();
        return is_array($v) ? $v : array();
    }
}
