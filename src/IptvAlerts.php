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
 * Progi alarmów dla NOC liczone z historii migawek (IptvMetrics::history()).
 * Zasada: alarm zależy od udziału podsieci/grupy w sumie błędów, nie od samego faktu, że są błędy.
 * Rytm dobowy podnosi wszystko naraz (wieczorem), więc udział w sumie go znosi.
 */
class IptvAlerts
{
    // Domyślne progi alarmów; każdy można nadpisać w uiconfig (sekcja iptv-errors,
    // klucze iptv-errors.alert_threshold_*) — patrz self::threshold().
    const MIN_BASELINE_HOURS = 6; // krócej niż to mediana jest niestabilna
    const MAX_USABLE_TOTAL = 500; // migawki ze skokiem sumy nie liczą się do bazy
    const SPIKE_WINDOW = 12; // ostatnie migawki (ok. 1 h) do mediany sumy
    const SPIKE_BAD_MIN = 400;
    const SPIKE_WARN_MIN = 250;
    const PREV_MIN_AGE = 150; // "poprzednia" migawka musi być starsza niż 2,5 min (bieżąca bywa już zapisana)

    private static $thresholds = array();

    /**
     * Próg alarmu z uiconfig (sekcja iptv-errors), keszowany w ramach jednego requestu.
     */
    private static function threshold($name, $default)
    {
        if (!array_key_exists($name, self::$thresholds)) {
            self::$thresholds[$name] = (int) ConfigHelper::getConfig('iptv-errors.alert_threshold_' . $name, $default);
        }
        return self::$thresholds[$name];
    }

    public static function median(array $values)
    {
        if (!$values) {
            return 0;
        }
        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);
        return $n % 2 ? $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;
    }

    public static function percentile(array $values, $p)
    {
        if (!$values) {
            return 0;
        }
        sort($values);
        return $values[(int) min(count($values) - 1, floor($p * (count($values) - 1)))];
    }

    /**
     * Migawki z sumą błędów w normie (bez skoków).
     */
    private static function usable(array $history)
    {
        $out = array();
        foreach ($history as $snap) {
            $t = (int) ($snap['v']['total_errors'] ?? 0);
            if ($t > 0 && $t <= self::threshold('max_usable_total', self::MAX_USABLE_TOTAL)) {
                $out[] = $snap;
            }
        }
        return $out;
    }

    public static function baselineOk(array $history, $now)
    {
        return $history
            && ($now - (int) $history[0]['t']) >= self::threshold('min_baseline_hours', self::MIN_BASELINE_HOURS) * 3600
            && count(self::usable($history)) >= 36;
    }

    /**
     * Typowa suma błędów: mediana oraz zakres p10-p90 z migawek bez skoków.
     */
    public static function normalRange(array $history)
    {
        $totals = array();
        foreach (self::usable($history) as $snap) {
            $totals[] = (int) $snap['v']['total_errors'];
        }
        return array('median' => (int) self::median($totals), 'low' => (int) self::percentile($totals, 0.10), 'high' => (int) self::percentile($totals, 0.90));
    }

    /**
     * Skok sumy względem mediany z ostatnich migawek.
     *
     * @return array ['level' => null|'warn'|'bad', 'median' => int, 'ratio' => float]
     */
    public static function totalSpike(array $history, $total)
    {
        $recent = array();
        foreach (array_slice($history, -self::threshold('spike_window', self::SPIKE_WINDOW)) as $snap) {
            $recent[] = (int) ($snap['v']['total_errors'] ?? 0);
        }
        $median = self::median($recent);
        $ratio = $median > 0 ? $total / $median : 0;
        $level = null;
        if (count($recent) >= 6 && $median > 0) {
            if ($ratio >= 2 && $total >= self::threshold('spike_bad_min', self::SPIKE_BAD_MIN)) {
                $level = 'bad';
            } elseif ($ratio >= 1.5 && $total >= self::threshold('spike_warn_min', self::SPIKE_WARN_MIN)) {
                $level = 'warn';
            }
        }
        return array('level' => $level, 'median' => (int) $median, 'ratio' => $ratio);
    }

    /**
     * Oczekiwany udział klucza w sumie: mediana udziału z migawek bez skoków.
     */
    private static function expectedShares(array $history, $prefix)
    {
        $keys = array();
        $usable = self::usable($history);
        foreach ($usable as $snap) {
            foreach ($snap['v'] as $k => $v) {
                if (strpos($k, $prefix) === 0) {
                    $keys[$k] = true;
                }
            }
        }
        $shares = array();
        foreach (array_keys($keys) as $k) {
            $vals = array();
            foreach ($usable as $snap) {
                $vals[] = (int) ($snap['v'][$k] ?? 0) / (int) $snap['v']['total_errors'];
            }
            $shares[$k] = self::median($vals);
        }
        return $shares;
    }

    private static function previous(array $history, $now)
    {
        for ($i = count($history) - 1; $i >= 0; $i--) {
            if ($now - (int) $history[$i]['t'] >= self::threshold('prev_min_age', self::PREV_MIN_AGE)) {
                return $history[$i];
            }
        }
        return null;
    }

    /**
     * Podsieci lub grupy z nadmiarem błędów ponad oczekiwany udział w sumie.
     *
     * @param array $current klucz (np. cidr lub grupa) => błędy
     * @return array lista ['key', 'errors', 'expected', 'ratio', 'persisted']
     */
    public static function excess(array $current, $total, array $history, $now, $prefix, $min, $mult, $add)
    {
        $shares = self::expectedShares($history, $prefix);
        $prev = self::previous($history, $now);
        $prev_total = $prev ? (int) ($prev['v']['total_errors'] ?? 0) : 0;
        $out = array();
        foreach ($current as $key => $errors) {
            $share = $shares[$prefix . $key] ?? 0;
            $expected = $share * $total;
            if ($errors < $min || $errors < $mult * $expected + $add) {
                continue;
            }
            $persisted = false;
            if ($prev && $prev_total > 0) {
                $pe = (int) ($prev['v'][$prefix . $key] ?? 0);
                $persisted = $pe >= $min && $pe >= $mult * $share * $prev_total + $add;
            }
            $out[] = array('key' => $key, 'errors' => $errors, 'expected' => $expected, 'ratio' => $errors / max(1.0, $expected), 'persisted' => $persisted);
        }
        usort($out, function ($a, $b) {
            return [(int) $b['persisted'], $b['errors']] <=> [(int) $a['persisted'], $a['errors']];
        });
        return $out;
    }

    /**
     * Jak długo klucz awarii (np. subnet_emg:cidr) jest ciągle większy od zera.
     *
     * @return array ['seconds' => int, 'full' => bool] full = awaria od początku historii (czas jest dolną granicą)
     */
    public static function emergencyDuration(array $history, $key, $now)
    {
        $since = null;
        $full = true;
        for ($i = count($history) - 1; $i >= 0; $i--) {
            if ((int) ($history[$i]['v'][$key] ?? 0) > 0) {
                $since = (int) $history[$i]['t'];
            } else {
                $full = false;
                break;
            }
        }
        return array('seconds' => $since === null ? 0 : $now - $since, 'full' => $since !== null && $full);
    }
}
