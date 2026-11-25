# UI pluginów LMS: rekomendacje stylu (własny styl „alfa-calm” (inspiracja: librechat.ai))

Źródło: https://www.librechat.ai (strona główna, /docs, /blog), analiza 2026-11-25.
Pełna notatka w Obsidian: `projects/genieacs-plugin/ui-styl-rekomendacje-librechat-2026-11-25.md`.
Wartości odczytane z arkusza CSS mają znacznik **ODCZYTANE**, wnioski autora **INTERPRETACJA**.
Nie kopiować tekstów ani grafik ze strony — notatka opisuje tylko styl.

Cel: stały wzór UI dla narzędzi roboczych w panelu LMS (BOK, technicy). To nie jest strona marketingowa.

## 1. Paleta

Neutralna: czerń, biel, szarości. Kolor tylko do stanu (info/warning/error/success). **ODCZYTANE:** `--color-fd-primary` = `#171717` (jasny) / `#fafafa` (ciemny).

| Rola | Jasny | Ciemny |
|---|---|---|
| Tło strony | `#f5f5f5` lub `#ffffff` | `#121212` |
| Powierzchnia (karta) | `#ffffff` | `#191919` |
| Popover/menu | `#fafafa` | `#1e1e1e` |
| Tekst | `#0a0a0a` | `#ebebeb` |
| Tekst drugorzędny | `#737373` | `#b3b3b3` |
| Ramka | `#e5e5e5` (hsl 0 0% 90%) | hsl 0 0% 14% |
| Wypełnienie subtelne (hover) | `#ededed` | `#212121` |
| Accent (hover wiersza) | `rgba(209,209,209,.5)` | `rgba(104,104,104,.3)` |
| Primary (przycisk główny) | tło `#171717`, tekst `#fafafa` | odwrócone |
| Pierścień fokusu | `#a3a3a3` | `#8c8c8c` |

Kolory semantyczne (odpowiedniki Tailwind): info `#3b82f6`, warning `#f59e0b`, error `#ef4444`, success `#22c55e`.
Dla pluginu LMS: tło treści `#ffffff`, tło panelu `#f5f5f5`.

## 2. Typografia

- Rodzina: systemowy zapas `ui-sans-serif, system-ui, sans-serif` i `ui-monospace, SFMono-Regular, Menlo, Consolas` (Geist tylko lokalnie, nie z CDN).
- Skala: xs 0.75rem, sm 0.875rem, base 1rem, lg 1.125rem, xl 1.25rem, 2xl 1.5rem. Wagi 400/500/600/700. Interlinia 1.5.
- W UI: treść i tabele 14 px (sm), etykiety/odznaki 12 px (xs), nagłówek sekcji 16–18 px waga 600, tytuł strony 24 px waga 600. Bez nagłówków > 30 px.

## 3. Odstępy i układ

- Baza odstępu 4 px; skala 4/8/12/16/24/32 px.
- Promienie: 6 px (sm), 8 px (bazowy), 12 px (xl). Ramki 1 px.
- **Ramki zamiast cieni** — karty i przyciski bez cienia.
- Przejścia 150 ms `cubic-bezier(.4,0,.2,1)`.
- Siatka kafelków: `repeat(auto-fill, minmax(220px, 1fr))`, odstęp 12 px.

## 4. Komponenty

- **Przyciski:** główny pełny (czarny w jasnym motywie), drugi subtelny (`#ededed` lub sama ramka). Wysokość 32–36 px, promień 6 px, tekst 14 px waga 500, bez gradientów.
- **Karty:** tło lekko inne niż strony, ramka 1 px, hover → accent.
- **Odznaki:** 12 px, promień pełny, tło muted; kolor semantyczny tylko dla stanu.
- **Akordeony:** nagłówek 14 px waga 500, strzałka po prawej, ramka dolna 1 px.
- **Kod:** font mono, blok z tłem karty, przycisk kopiowania.
- **Callout:** warianty info/warning/error/success.

## 5. Zasady ogólne

- Jeden kolor to czerń i biel; kolor tylko do stanu.
- Mała gęstość, dużo białego miejsca, hierarchia przez wagę i rozmiar, nie kolor.
- Jasny i ciemny motyw = ta sama struktura, odwrócona paleta.

## 6. Adaptacja do pluginu LMS

Tokeny CSS (prefiks `--alfa-` przeciw kolizji z motywem LMS) — patrz blok `:root` w `templates/iptverrors.css`.

### Progressive disclosure

- Widok „Podstawowy”: kafelki stanu + 1–2 akcje główne.
- Widok „Zaawansowany”: parametry techniczne; przełącznik w nagłówku, wybór zapamiętany.
- Szczegóły w akordeonach, domyślnie zwiniętych.
- Jedna akcja główna (pełny przycisk) na widok.
- Błąd/ostrzeżenie: kolor semantyczny tylko na ikonie/krawędzi/odznace, nie na całym tle.
- Szukanie i skróty klawiszowe tylko gdy lista > ~20 pozycji.

### Czego NIE przenosić

- Marka, logo, nazwa, ikony, grafiki strony.
- Font Geist z hostingu strony (CDN) — tylko lokalnie, jeśli w ogóle.
- Zrzuty ekranu z cieniami, animacje marketingowe, duży nagłówek hero, teksty i hasła ze strony.
- Zależności od Tailwind/Fumadocs — plugin używa własnych klas `.iptv-*` i tokenów `--alfa-*`.

### Doprecyzowanie 2026-11-25 (po review screenshotów LMSIptvErrorsPlugin)

- Kontener pluginu (`lms-ui-box`) musi mieć białe tło — klasa `.iptv-box` nadpisuje beżowy motyw LMS.
- **Kolor stanu tylko na krawędzi, ikonie lub odznace — nigdy na całym tle kafla/wiersza/alertu.**
  - Kafelki `.iptv-tile`: białe tło, kolorowa górna krawędź 5px + kolor wartości; wariant `.bad` bez różowego tła.
  - Alerty `.iptv-alert`: białe tło + ramka 1px + lewa krawędź 8px w kolorze stanu + kolorowa ikona. Bez pastelowego tła.
  - Wiersze tabeli `.iptv-row-hi/.iptv-row-warn`: tło `surface-muted` + `inset` krawędź 4px zamiast pełnego pastelowego tła.
  - Listy `.iptv-ins li`: tło `surface-muted`, kolor tylko na `border-left`.
  - Jedyny pełny kolorowy blok to hero-status `.iptv-status` (duży blok stanu, jak hero callout).
- Tabele: białe tło, szary nagłówek (`bg-panel`, ciemny tekst), ramki `--alfa-border`, zebra `var(--alfa-hover)`.

### Odchylenia w LMSIptvErrorsPlugin (kontrast WCAG AA)

- `--alfa-error` = `#b00020`, `--alfa-warning` = `#9a6d00`, `--alfa-success` = `#1b7a3a`, `--alfa-info` = `#3b6fb6` — ciemniejsze niż warianty Tailwind, bo tekst/białe tło wymaga lepszego kontrastu niż `#ef4444`/`#f59e0b`/`#22c55e`.
