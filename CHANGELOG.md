# Changelog

## [1.0.0] - 2026-11-24

### Dodano
- Widok podsumowania dla NOC (status, kafelki, alarmy) i widoki szczegółów: podsieci, dekodery, grupy multicast, modele, awarie (`?m=iptverrors&view=...`).
- Historia metryk (timer systemd, co 5 min) z wykresem 24 h i Δ ~1 h.
- Progi alarmów z historii (skok sumy, podsieci, grupy, awarie) — konfigurowalne w sekcji `iptv-errors`.
- Hook `customerinfo`: box „Błędy IPTV klienta” w karcie klienta.
- Filtr `q` w URL, auto-odświeżanie bez przeładowania, eksport CSV (także widok awarii), kopiowanie MAC, skrót `/`.
- Hardening jednostki systemd; POST + token CSRF dla odświeżania i CSV; zapytania SQL tylko na bindach.

### Zmieniono
- Cache plikowy w `storage/iptv-errors/` z prawami 0600; ciężkie zapytania tylko w widokach devices/emg.

### Naprawiono
- Błąd parsowania szablonu Smarty (usunięty operator `??`).
