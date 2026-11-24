# LMSIptvErrorsPlugin

Plugin LMS. Pokazuje błędy IPTV z monitoringu SGT: dekodery, podsieci, grupy multicast i modele.
Menu: **TV → Błędy IPTV** (`?m=iptverrors`). Uprawnienie: `iptverrors_full_access`.
Bez wtyczki `LMSJamboxPlugin` (sekcja TV) wpis trafia do menu **Sieci IP**.

## Wymagania

- LMS Plus z PHP 8.x, rozszerzenie PHP `curl`, katalog `storage/` zapisywalny dla użytkownika WWW (`apache`).
- Klucz API z portalu SGT (`https://portal.sgt.net.pl/`, „Dane do monitoringu w zewnętrznych systemach").
- Opcjonalnie wtyczka `LMSJamboxPlugin` z poprawną konfiguracją `jambox.*`. Bez niej plugin działa, ale klientów dopasowuje tylko po MAC z LMS.
- Opcjonalnie systemd, dla timera historii.

## Instalacja

1. Skopiuj katalog `LMSIptvErrorsPlugin` do `plugins/` w katalogu LMS.
2. Ustaw właściciela jak przy innych wtyczkach (np. `chown -R kyob:kyob`). Przy SELinux uruchom `restorecon -R` na katalogu pluginu.
3. Włącz plugin: Konfiguracja → Wtyczki → „LMS IPTV Errors plugin". Jeśli go nie ma na liście, uruchom w katalogu LMS `sudo -u apache composer update --no-dev`.
4. Zaimportuj ustawienia: Konfiguracja → Interfejs → Import, plik `configexport-iptv-errors-wartoscglobalna.ini`.
5. Dodaj zmienną `api_key` w sekcji `iptv-errors` (Konfiguracja → Interfejs). Klucza nie ma w pliku ini.
6. Nadaj użytkownikom uprawnienie „Błędy IPTV" w prawach dostępu.
7. Opcjonalnie włącz timer historii (sekcja niżej).

Plugin nie zmienia schematu bazy. Zapisuje tylko zmienne w tabeli `uiconfig` (sekcja `iptv-errors`).

## Konfiguracja

Ustawienia są w bazie (Konfiguracja → Interfejs, sekcja `iptv-errors`). Nie używaj `lms.ini`.
Nie wpisuj klucza API do plików w katalogu pluginu. Katalog `plugins/` leży w katalogu serwera WWW.

| Zmienna | Opis | Domyślnie |
|---|---|---|
| `api_key` | klucz API SGT, wysyłany tylko w nagłówku `X-API-Key` (wymagany) | brak |
| `url` | adres metryk | `https://device-errors-api.sgt.net.pl/api/metrics` |
| `errors_url` | adres zdarzeń per dekoder | `url` z `/metrics` zamienionym na `/errors` |
| `cache_ttl` | cache metryk i zdarzeń, sekundy | `30` |
| `history_interval` | odstęp migawek historii, sekundy | `300` |
| `stb_cache_ttl` | cache listy dekoderów z SMS SGT, sekundy | `600` |

## Co pokazuje strona

Strona ma pierwszy ekran dla NOC (podsumowanie) i widoki ze szczegółami. Zakładki u góry przełączają widoki.

**Podsumowanie** (`?m=iptverrors`):

- **Status:** zielony (brak anomalii), żółty (są pozycje do sprawdzenia), czerwony (wymaga reakcji) albo szary (historia krótsza niż 6 h, progi jeszcze nie działają).
- **Kafelki:** suma błędów z Δ i wykresem 24 h, dekodery w awarii, podsieci z alarmem, grupy z alarmem.
- **Wymaga reakcji:** karty z alarmami i linkiem „Szczegóły". Gdy brak alarmów, napis „Nic do zrobienia".
- **Szczegóły:** kafelki prowadzące do widoków. Opisowe wnioski i podpowiedzi są zwinięte na dole.

**Widoki** (`?m=iptverrors&view=...`):

| `view` | Zawartość |
|---|---|
| `subnets` | podsieci: błędy, awarie, zmiana Δ, wykres 24 h |
| `devices` | dekodery z ostatnich zdarzeń: MAC, IP, model, serial (link do SMS), podsieć, błędy, grupy, klient LMS (link), numer SMS |
| `streams` | grupy multicast (15 największych, przycisk pokazuje wszystkie) |
| `models` | modele: błędy, udział, stawka błędów (błędy / zarejestrowane w SMS), awarie, Δ |
| `emg` | kandydaci na dekoder w stanie awaryjnym |

Narzędzia: filtr tekstowy i „Tylko z błędami" (w widokach), auto-odświeżanie co 60 s, przycisk Odśwież, CSV przy tabelach, link do systemu SMS SGT.

## Progi alarmów

Progi są w `src/IptvAlerts.php` i liczą się z historii migawek (`history.json`).
Alarm zależy od udziału w sumie błędów, nie od samego faktu, że są błędy. Rytm dobowy podnosi wszystko naraz i udział go znosi.
Migawki z sumą powyżej 500 nie liczą się do bazy.

| Alarm | Warunek |
|---|---|
| Skok sumy (czerwony) | suma ≥ 2× mediana z ostatnich 12 migawek i ≥ 400 |
| Skok sumy (żółty) | suma ≥ 1,5× mediana i ≥ 250 |
| Podsieć | ≥ 10 błędów i ≥ 2× oczekiwany udział + 3. Czerwony po 2 odczytach z rzędu, żółty po 1 |
| Grupa multicast (żółty) | ≥ 40 błędów i ≥ 3× oczekiwany udział + 3, 2 odczyty z rzędu |
| Awaria dekodera (żółty) | `subnet_emergency` > 0, z czasem trwania |
| Dekodery z dużą liczbą błędów (informacja) | ≥ 20 błędów w jednym odczycie. Nie zmienia koloru statusu |

Oczekiwany udział to mediana udziału z migawek bez skoków. Baza wymaga co najmniej 6 h historii (36 migawek), inaczej status jest szary.
Progi wybrano na podstawie jednej doby danych. Po 7 dniach historii przelicz je ponownie.
Dekoder w stanie awaryjnym: API SGT podaje tylko liczbę (model, podsieć), bez MAC. Widok `emg` pokazuje kandydatów z SMS SGT o tym modelu i IP z tej podsieci. To założenie, nie dane z API.
Wartość `errors` dekodera powyżej 1000 jest odstająca i nie liczy się jako błędy (plakietka „odstająca").

## Źródła danych

1. `GET /api/metrics`: sumy w formacie OpenMetrics (`device_errors`, `device_emergency`, `subnet_errors`, `subnet_emergency`, `stream_errors`).
2. `GET /api/errors`: ostatnie około 200 zdarzeń per dekoder (MAC, IP, model, grupa, liczba błędów, czas).
   Tego endpointu nie ma w instrukcji portalu SGT. SGT może go zmienić bez uprzedzenia.
   Znaczenie pola `errors` to założenie: liczba błędów dekodera w zdarzeniu. Kolumna nazywa się „Błędy (max)".
3. System SMS SGT, przez klasę `send` z `LMSJamboxPlugin` (XML-RPC `stbGetRegistered`, tryb `customer`). Tylko odczyt.
   Wynik łączy MAC dekodera z numerem klienta w SMS, a ten z klientem LMS przez `customers.tv_cust_number`.
   Wynik jest w cache 10 minut.

## Pliki robocze

Katalog `storage/iptv-errors/` tworzy plugin. Pliki zapisuje użytkownik WWW.

| Plik | Zawartość |
|---|---|
| `cache.json` | ostatnia odpowiedź metryk |
| `events.json` | ostatnie zdarzenia per dekoder (zawiera MAC) |
| `stb.json` | dekodery klientów z SMS SGT (zawiera MAC i numery klientów) |
| `history.json` | migawki z 24 godzin (co 5 minut) |
| `history.lock` | blokada zapisu historii |
| `fail-<źródło>.json` | czas i komunikat ostatniego błędu źródła (metryki, zdarzenia, SMS). Przez 90 s plugin nie pyta tego źródła ponownie |

Pliki zawierają dane klientów i są zapisywane z prawami 0600.

## Timer historii

Timer pobiera dane co 5 minut bez otwierania strony. Dzięki temu kolumna Δ i wykresy 24 h mają dane od razu.
Bez timera strona dopisuje migawkę przy otwarciu (co najwyżej raz na `history_interval`).

```bash
cp bin/lms-plugin-iptv-errors-collect.{service,timer} /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now lms-plugin-iptv-errors-collect.timer
systemctl is-active lms-plugin-iptv-errors-collect.timer   # active
```

Jednostka zakłada ścieżkę `/var/www/html/lmsplus`, użytkownika `apache` i `/etc/lms/lms.ini`. Zmień je w pliku `.service`, jeśli są inne.
Ręczny test: `sudo -u apache php bin/lms-iptv-errors-collect.php -C /etc/lms/lms.ini`.

## Ograniczenia

- Lista dekoderów ma około 200 ostatnich zdarzeń, więc nie obejmuje wszystkich dekoderów z błędami. Podsumowanie nad tabelą pokazuje, ile dekoderów obejmuje.
- Kolumna Δ porównuje z migawką sprzed około godziny i jest pusta przez pierwsze 30 minut zbierania historii.
- Progi alarmów działają po 6 h historii. Przed tym status jest szary.
- `?refresh=1` pyta API najwyżej co 15 s (rejestr SMS co 60 s).
- Dopasowanie klientów zależy od danych w SMS SGT i od `customers.tv_cust_number` w LMS.

## Błąd zapisu w storage (SELinux)

Objaw: strona pokazuje „Nie można zapisać /var/www/html/lmsplus/storage/iptv-errors/cache.json" albo „Nie można utworzyć katalogu …/iptv-errors".
Prawa `apache:apache` są poprawne, ale pliki mają kontekst SELinux `httpd_sys_content_t`. Ten kontekst pozwala serwerowi WWW tylko czytać.
Pliki dostają zły kontekst, gdy ktoś utworzy je spoza serwera WWW (np. ręcznie jako root albo przy kopiowaniu).

1. Sprawdź kontekst:
```bash
ls -lZ /var/www/html/lmsplus/storage/iptv-errors
```
2. Jeśli widzisz `httpd_sys_content_t`, przywróć kontekst zgodnie z regułami systemu. Wykonaj jako root:
```bash
/usr/sbin/restorecon -Rv /var/www/html/lmsplus/storage/iptv-errors
```
Oczekiwany wynik: linie `Relabeled … to … httpd_sys_rw_content_t`. Po tym kroku błąd znika (potwierdzone na `serwer produkcyjny`).
3. Jeśli `restorecon` niczego nie zmienia, w systemie brakuje reguły dla `storage/`. Dodaj ją trwale i powtórz krok 2:
```bash
/usr/sbin/semanage fcontext -a -t httpd_sys_rw_content_t "/var/www/html/lmsplus/storage/iptv-errors(/.*)?"
```
4. Odśwież stronę.

Po każdej ręcznej zmianie w `storage/iptv-errors` uruchom `restorecon -Rv` na tym katalogu. Dziennik odmów: `ausearch -m avc -ts recent`.

## Rozwiązywanie problemów

| Objaw | Sprawdź |
|---|---|
| „Nie można zapisać …/cache.json" lub „Nie można utworzyć katalogu …/iptv-errors" | SELinux. Patrz sekcja „Błąd zapisu w storage" niżej |
| HTTP 500 po włączeniu | log `php-fpm` (np. `/var/log/php-fpm/www-error.log`) |
| „Brak klucza API" | zmienna `api_key` w sekcji `iptv-errors` (Konfiguracja → Interfejs) |
| „Błąd pobierania danych … HTTP 401" | klucz API w portalu SGT |
| Brak pozycji w menu | uprawnienie „Błędy IPTV" i włączenie wtyczki (menu TV, potem Sieci IP) |
| Dekodery bez klientów | czy działa `LMSJamboxPlugin` i czy klient ma `tv_cust_number` |
| Pusta kolumna Δ | zaczekaj 30 minut albo włącz timer |
| Szary status „Za mało historii" | zaczekaj 6 h albo włącz timer historii |
| Żółty komunikat „Ostatnia migawka historii jest sprzed N min" | sprawdź `lms-plugin-iptv-errors-collect.timer` |
| „Syntax error in template … Unexpected ?" | w szablonach Smarty nie używaj operatora `? :`, użyj `{if}{else}` |
| Wpis menu w Sieci IP zamiast TV | włącz `LMSJamboxPlugin` |
