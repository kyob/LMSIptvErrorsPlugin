# LMSIptvErrorsPlugin

Plugin LMS-PLUS dla NOC/ISP. Pokazuje błędy IPTV z monitoringu SGT: dekodery, podsieci, grupy multicast, modele i dekodery w stanie awaryjnym.

Menu: **TV → Błędy IPTV** (`?m=iptverrors`). Uprawnienie: `iptverrors_full_access`.
Bez wtyczki `LMSJamboxPlugin` (sekcja TV) wpis trafia do menu **Sieci IP**.

![Podsumowanie NOC](docs/screenshot.png?raw=true)

Spis treści:

- [Wymagania](#wymagania)
- [Instalacja](#instalacja)
- [Konfiguracja](#konfiguracja)
- [Co pokazuje strona](#co-pokazuje-strona)
- [Progi alarmów](#progi-alarmów)
- [Źródła danych](#źródła-danych)
- [Pliki robocze](#pliki-robocze)
- [Timer historii](#timer-historii)
- [Ograniczenia](#ograniczenia)
- [Błąd zapisu w storage (SELinux)](#błąd-zapisu-w-storage-selinux)
- [Rozwiązywanie problemów](#rozwiązywanie-problemów)
- [Licencja](#licencja)

## Wymagania

- LMS-PLUS z PHP 8.x i rozszerzeniem `curl`.
- Katalog `storage/` zapisywalny dla użytkownika WWW (np. `apache`).
- Klucz API z portalu SGT (`https://portal.sgt.net.pl/`, „Dane do monitoringu w zewnętrznych systemach”).
- Opcjonalnie `LMSJamboxPlugin` (dopasowanie klientów przez SMS SGT). Bez niego klientów szuka tylko po MAC z LMS.
- Opcjonalnie systemd (timer historii).

## Instalacja

1. Skopiuj katalog `LMSIptvErrorsPlugin` do `plugins/` w katalogu LMS.
2. Ustaw właściciela jak przy innych wtyczkach. Przy SELinux: `restorecon -R` na katalogu pluginu.
3. Włącz: Konfiguracja → Wtyczki → „LMS IPTV Errors plugin”. Gdy go nie ma na liście: `sudo -u apache composer update --no-dev` w katalogu LMS.
4. Zaimportuj ustawienia: Konfiguracja → Interfejs → Import, plik `configexport-iptv-errors-wartoscglobalna.ini`.
5. Dodaj `api_key` w sekcji `iptv-errors` (Konfiguracja → Interfejs). Klucza nie ma w pliku ini.
6. Nadaj użytkownikom uprawnienie „Błędy IPTV”.
7. Opcjonalnie włącz timer historii (sekcja [Timer historii](#timer-historii)).

Plugin nie zmienia schematu bazy. Zapisuje tylko zmienne w tabeli `uiconfig` (sekcja `iptv-errors`).

## Konfiguracja

Ustawienia są w bazie (Konfiguracja → Interfejs, sekcja `iptv-errors`). Nie używaj `lms.ini`.
Nie wpisuj klucza API do plików w katalogu pluginu — katalog `plugins/` leży w katalogu serwera WWW.

| Zmienna | Opis | Domyślnie |
|---|---|---|
| `api_key` | klucz API SGT, tylko w nagłówku `X-API-Key` (wymagany) | brak |
| `url` | adres metryk | `https://device-errors-api.sgt.net.pl/api/metrics` |
| `errors_url` | adres zdarzeń per dekoder | `url` z `/metrics` → `/errors` |
| `cache_ttl` | cache metryk i zdarzeń, s | `30` |
| `history_interval` | odstęp migawek historii, s | `300` |
| `stb_cache_ttl` | cache listy dekoderów z SMS SGT, s | `600` |
| `alert_threshold_*` | progi alarmów (patrz [Progi alarmów](#progi-alarmów)) | wartości z tabeli niżej |

## Co pokazuje strona

Pierwszy ekran to podsumowanie dla NOC. Zakładki u góry przełączają widoki szczegółów.

**Podsumowanie** (`?m=iptverrors`):

- **Status:** zielony (brak anomalii), żółty (do sprawdzenia), czerwony (wymaga reakcji), szary (historia < 6 h, progi nie działają).
- **Kafelki:** suma błędów z Δ i wykresem 24 h, dekodery w awarii, podsieci z alarmem, grupy z alarmem.
- **Wymaga reakcji:** karty alarmów z linkiem „Szczegóły”. Gdy brak: „Nic do zrobienia”.

**Widoki** (`?m=iptverrors&view=...`):

| `view` | Zawartość |
|---|---|
| `subnets` | podsieci: błędy, awarie, Δ, wykres 24 h |
| `devices` | dekodery z ostatnich zdarzeń: MAC, IP, model, serial (link do SMS), podsieć, błędy, grupy, klient LMS (link), numer SMS |
| `streams` | grupy multicast (15 największych, przycisk pokazuje wszystkie) |
| `models` | modele: błędy, udział, stawka błędów (błędy / zarejestrowane w SMS), awarie, Δ |
| `emg` | kandydaci na dekoder w stanie awaryjnym |

Narzędzia na każdym widoku: filtr tekstowy (`q` w URL), „Tylko z błędami”, auto-odświeżanie co 60 s, przycisk Odśwież, CSV przy tabelach, link do SMS SGT.

## Progi alarmów

Progi liczą się z historii migawek (`history.json`), z udziału w sumie błędów. Rytm dobowy podnosi wszystkie podsieci naraz — udział go znosi.
Migawki z sumą > 500 nie liczą się do bazy. Baza: min. 6 h historii (36 migawek), inaczej status szary.

| Alarm | Warunek |
|---|---|
| Skok sumy (czerwony) | suma ≥ 2× mediana z 12 migawek i ≥ 400 |
| Skok sumy (żółty) | suma ≥ 1,5× mediana i ≥ 250 |
| Podsieć | ≥ 10 błędów i ≥ 2× oczekiwany udział + 3; czerwony po 2 odczytach, żółty po 1 |
| Grupa multicast (żółty) | ≥ 40 błędów i ≥ 3× oczekiwany udział + 3, 2 odczyty z rzędu |
| Awaria dekodera (żółty) | `subnet_emergency` > 0, z czasem trwania |
| Dekoder z dużą liczbą błędów (info) | ≥ 20 błędów w jednym odczycie; nie zmienia statusu |

Uwagi:

- Oczekiwany udział = mediana udziału z migawek bez skoków.
- Progi dobrano na podstawie jednej doby. Po 7 dniach historii przelicz je ponownie.
- Awaria: API SGT podaje tylko liczbę (model, podsieć), bez MAC. Widok `emg` pokazuje kandydatów z SMS SGT (ten model, IP z tej podsieci) — to założenie, nie dane z API. `in_cidr` matchuje tylko IPv4.
- `errors` dekodera > 1000 to wartość odstająca — plakietka „odstająca”, nie liczy się jako błąd.

## Źródła danych

1. **API SGT** `GET {url}` — OpenMetrics: `device_errors`, `device_emergency`, `subnet_errors`, `subnet_emergency`, `stream_errors`.
2. **API SGT** `GET {errors_url}` — ostatnie ~200 zdarzeń per dekoder (MAC, IP, model, grupa, `errors`, czas). Endpoint nieudokumentowany u SGT; znaczenie `errors` to założenie.
3. **SMS SGT** przez `LMSJamboxPlugin` (XML-RPC `stbGetRegistered`, tylko odczyt): MAC → numer klienta SMS → klient LMS przez `customers.tv_cust_number`. Cache 10 min (`stb_cache_ttl`).

## Pliki robocze

Katalog `storage/iptv-errors/` tworzy plugin, zapisuje użytkownik WWW, prawa 0600. Pliki zawierają dane klientów.

| Plik | Zawartość |
|---|---|
| `cache.json` | ostatnia odpowiedź metryk |
| `events.json` | ostatnie zdarzenia per dekoder (MAC) |
| `stb.json` | dekodery z SMS SGT (MAC, numery klientów) |
| `history.json` | migawki 24 h (co 5 min) |
| `history.lock` | blokada zapisu historii |
| `fail-<źródło>.json` | ostatni błąd źródła; przez 90 s plugin go nie pyta ponownie |

## Timer historii

Timer zapisuje migawki co 5 min bez otwierania strony — Δ i wykresy 24 h mają dane od razu.
Bez timera strona dopisuje migawkę przy otwarciu (max raz na `history_interval`).

```bash
cp bin/lms-plugin-iptv-errors-collect.{service,timer} /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now lms-plugin-iptv-errors-collect.timer
systemctl is-active lms-plugin-iptv-errors-collect.timer   # active
```

Jednostka zakłada `/var/www/html/lmsplus`, użytkownika `apache`, `/etc/lms/lms.ini` — zmień w `.service` jeśli inne.
Ręczny test: `sudo -u apache php bin/lms-iptv-errors-collect.php -C /etc/lms/lms.ini`.

## Ograniczenia

- `events` obejmuje ~200 ostatnich zdarzeń, nie wszystkie dekodery z błędami.
- Δ jest puste przez pierwsze 30 min historii (porównanie z migawką sprzed ~1 h).
- Progi działają po 6 h historii; wcześniej status szary.
- Przycisk Odśwież pyta API max co 15 s (SMS max co 60 s).
- Dopasowanie klientów zależy od SMS SGT i `customers.tv_cust_number` w LMS.

## Błąd zapisu w storage (SELinux)

Objaw: „Nie można zapisać …/storage/iptv-errors/cache.json” mimo praw `apache:apache`.
Przyczyna: kontekst SELinux `httpd_sys_content_t` (tylko odczyt dla httpd). Pliki dostają go przy kopiowaniu spoza serwera WWW.

```bash
ls -lZ /var/www/html/lmsplus/storage/iptv-errors          # sprawdź kontekst
sudo /usr/sbin/restorecon -Rv /var/www/html/lmsplus/storage/iptv-errors
# gdy restorecon nie pomaga — reguła na stałe:
sudo /usr/sbin/semanage fcontext -a -t httpd_sys_rw_content_t "/var/www/html/lmsplus/storage/iptv-errors(/.*)?"
sudo /usr/sbin/restorecon -Rv /var/www/html/lmsplus/storage/iptv-errors
```

Po każdej ręcznej zmianie w `storage/iptv-errors` uruchom `restorecon -Rv` na tym katalogu. Dziennik odmów: `ausearch -m avc -ts recent`.

## Rozwiązywanie problemów

| Objaw | Sprawdź |
|---|---|
| „Nie można zapisać/utworzyć … storage/iptv-errors” | [SELinux](#błąd-zapisu-w-storage-selinux) |
| HTTP 500 po włączeniu | log php-fpm (np. `/var/log/php-fpm/www-error.log`) |
| „Brak klucza API” | `api_key` w sekcji `iptv-errors` (Konfiguracja → Interfejs) |
| „HTTP 401” przy pobieraniu danych | klucz API w portalu SGT |
| Brak pozycji w menu | uprawnienie „Błędy IPTV” + włączona wtyczka (menu TV, bez Jambox → Sieci IP) |
| Dekodery bez klientów | `LMSJamboxPlugin` i `tv_cust_number` klienta |
| Pusta kolumna Δ | poczekaj 30 min lub włącz timer |
| Szary status „Za mało historii” | poczekaj 6 h lub włącz timer |
| „Ostatnia migawka sprzed N min” | `systemctl status lms-plugin-iptv-errors-collect.timer` |
| „Syntax error in template … Unexpected ?” | w szablonach Smarty nie używaj `?:`, tylko `{if}{else}` |

## Licencja

GPL-2.0-only — tak jak LMS-PLUS. Pełny tekst: [LICENSE](LICENSE). Historia zmian: [CHANGELOG.md](CHANGELOG.md).
