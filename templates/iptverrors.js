/* LMSIptvErrorsPlugin — logika widoku "Błędy IPTV" (templates/iptverrors.js)
 * Stringy użytkownika pobierane z atrybutów data-* kontenera #iptv-errors-content
 * (wstrzykiwane w szablonie przez {trans()|escape}), nie z literałów JS.
 */
(function () {
    'use strict';

    var LS_ONLY = 'iptv-only';
    var LS_SILENT = 'iptv-silent';
    var LS_AUTO = 'iptv-auto';
    var SILENT_AGE_S = 86400; /* cichy zanik: ostatnie zdarzenie starsze niż 24 h */
    var REFRESH_MS = 60000;
    var showAll = false;

    function store(k, v) { try { localStorage.setItem(k, v); } catch (e) {} }
    function load(k) { try { return localStorage.getItem(k); } catch (e) { return null; } }
    function content() { return document.getElementById('iptv-errors-content'); }
    function qInput() { return document.getElementById('iptv-q'); }
    function onlyBox() { return document.getElementById('iptv-only'); }
    function multiBox() { return document.getElementById('iptv-multi'); }
    function silentBox() { return document.getElementById('iptv-silent'); }
    function autoBox() { return document.getElementById('iptv-auto'); }
    function label(name) {
        var c = content();
        return c ? (c.getAttribute('data-' + name) || '') : '';
    }

    function rows() {
        var c = content();
        return c ? c.querySelectorAll('.iptv-tbl tbody tr') : [];
    }

    /* Sortowanie klikalne po nagłówkach tabel (.iptv-tbl).
     * Stan (kolumna:kierunek) w localStorage per tabela — przeżywa auto-odświeżanie. */
    var LS_SORT = 'iptv-sort-';

    function sortState(table) { return load(LS_SORT + (table.id || 'x')); }

    function cellVal(row, idx) {
        var td = row.children[idx];
        return td ? (td.textContent || '').replace(/\s+/g, ' ').trim() : '';
    }

    function isNum(s) { return s !== '' && !isNaN(Number(s)); }

    function sortRows(table, idx, dir) {
        var tbody = table.tBodies[0];
        if (!tbody) { return; }
        var list = Array.prototype.slice.call(tbody.rows);
        var data = [];
        var empty = [];
        for (var i = 0; i < list.length; i++) {
            (list[i].classList.contains('iptv-empty') ? empty : data).push(list[i]);
        }
        data.sort(function (a, b) {
            var va = cellVal(a, idx);
            var vb = cellVal(b, idx);
            var cmp = (isNum(va) && isNum(vb))
                ? Number(va) - Number(vb)
                : va.localeCompare(vb, 'pl');
            return cmp * dir;
        });
        for (var j = 0; j < data.length; j++) { tbody.appendChild(data[j]); }
        for (var k = 0; k < empty.length; k++) { tbody.appendChild(empty[k]); }
    }

    function markTh(table, idx, dir) {
        var head = table.tHead;
        if (!head || !head.rows[0]) { return; }
        var ths = head.rows[0].children;
        for (var i = 0; i < ths.length; i++) {
            ths[i].classList.remove('iptv-sort-asc', 'iptv-sort-desc');
            if (i === idx) { ths[i].classList.add(dir > 0 ? 'iptv-sort-asc' : 'iptv-sort-desc'); }
        }
    }

    function applySort() {
        var c = content();
        var tables = c ? c.querySelectorAll('.iptv-tbl') : [];
        for (var t = 0; t < tables.length; t++) {
            var st = sortState(tables[t]);
            if (!st) { continue; }
            var parts = st.split(':');
            var idx = parseInt(parts[0], 10);
            var dir = parts[1] === 'desc' ? -1 : 1;
            sortRows(tables[t], idx, dir);
            markTh(tables[t], idx, dir);
        }
    }

    function initSortable() {
        var c = content();
        var ths = c ? c.querySelectorAll('.iptv-tbl thead th') : [];
        for (var i = 0; i < ths.length; i++) {
            if ((ths[i].textContent || '').trim() !== '') { ths[i].classList.add('iptv-sortable'); }
        }
    }

    /* Cichy zanik: wiersz kwalifikuje się, gdy data-ts (ostatnie zdarzenie) jest starsze niż 24 h. */
    function silentOk(r, nowSec) {
        if (!r.hasAttribute('data-ts')) { return false; }
        var ts = parseInt(r.getAttribute('data-ts'), 10);
        return !isNaN(ts) && ts > 0 && (nowSec - ts) > SILENT_AGE_S;
    }

    function apply() {
        var q = qInput();
        var only = onlyBox();
        var silent = silentBox();
        var nowSec = Math.floor(Date.now() / 1000);
        var term = q ? q.value.trim().toLowerCase() : '';
        var list = rows();
        var visible = 0;
        var more = document.getElementById('iptv-more');
        var extra = content() ? content().querySelectorAll('.iptv-extra') : [];

        for (var i = 0; i < list.length; i++) {
            var r = list[i];
            if (r.classList.contains('iptv-empty')) { continue; }
            var n = parseInt(r.getAttribute('data-n'), 10);
            var s = r.getAttribute('data-s') || '';
            var hide = (only && only.checked && (isNaN(n) ? 0 : n) === 0)
                || (silent && silent.checked && !silentOk(r, nowSec))
                || (term && s.toLowerCase().indexOf(term) === -1)
                || (!term && !showAll && r.classList.contains('iptv-extra'));
            r.style.display = hide ? 'none' : '';
            if (!hide) { visible++; }
        }

        var count = document.getElementById('iptv-count');
        if (count) { count.textContent = visible + ' / ' + list.length; }

        var noRes = document.getElementById('iptv-no-results');
        if (noRes) { noRes.hidden = !(term && visible === 0); }

        if (more) {
            more.style.display = extra.length ? '' : 'none';
            more.textContent = showAll
                ? label('show-top')
                : label('show-all') + ' (' + list.length + ')';
        }

        applySort();
    }

    function syncUrl(v) {
        try {
            var url = new URL(window.location.href);
            if (v) { url.searchParams.set('q', v); } else { url.searchParams.delete('q'); }
            history.replaceState(null, '', url);
        } catch (e) {}
    }

    function initPrefs() {
        var o = load(LS_ONLY);
        var only = onlyBox();
        if (o !== null && only) { only.checked = o === '1'; }
        var si = load(LS_SILENT);
        var silent = silentBox();
        if (si !== null && silent) { silent.checked = si === '1'; }
        var auto = autoBox();
        if (auto) { auto.checked = load(LS_AUTO) === '1'; }
    }

    function fallbackCopy(text) {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.setAttribute('readonly', '');
        ta.style.position = 'absolute';
        ta.style.left = '-9999px';
        document.body.appendChild(ta);
        ta.select();
        var ok = false;
        try { ok = document.execCommand('copy'); } catch (e) {}
        document.body.removeChild(ta);
        return ok;
    }

    function copyText(text, btn) {
        var done = function (ok) {
            if (!btn) { return; }
            var old = btn.getAttribute('data-copy-label') || btn.title;
            btn.classList.add('iptv-copied');
            btn.title = ok ? label('copied') : old;
            setTimeout(function () {
                btn.classList.remove('iptv-copied');
                btn.title = old;
            }, 1500);
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(
                function () { done(true); },
                function () { done(fallbackCopy(text)); }
            );
        } else {
            done(fallbackCopy(text));
        }
    }

    /* Auto-odświeżanie co 60 s BEZ pełnego reload: fetch widoku + podmiana
     * fragmentu #iptv-errors-content (pozycja przewinięcia zostaje). */
    function autoRefresh() {
        var auto = autoBox();
        var q = qInput();
        // Nie odświeżaj, gdy użytkownik pisze w filtrze lub ma w nim tekst (reload skasowałby wpis).
        if (!auto || !auto.checked) { return; }
        if (q && (q.value !== '' || document.activeElement === q)) { return; }
        if (document.hidden) { return; }
        fetch(window.location.href, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin'
        }).then(function (r) {
            return r.ok ? r.text() : null;
        }).then(function (html) {
            if (!html) { return; }
            var doc = new DOMParser().parseFromString(html, 'text/html');
            var fresh = doc.getElementById('iptv-errors-content');
            var cur = content();
            if (fresh && cur) {
                cur.replaceWith(fresh);
                initPrefs();
                initSortable();
                apply();
            }
        }).catch(function () { /* zostawiamy bieżący widok */ });
    }

    document.addEventListener('input', function (e) {
        if (e.target && e.target.id === 'iptv-q') {
            syncUrl(e.target.value.trim());
            apply();
        }
    });

    document.addEventListener('change', function (e) {
        if (!e.target) { return; }
        if (e.target.id === 'iptv-only') { store(LS_ONLY, e.target.checked ? '1' : '0'); apply(); }
        if (e.target.id === 'iptv-silent') { store(LS_SILENT, e.target.checked ? '1' : '0'); apply(); }
        if (e.target.id === 'iptv-auto') { store(LS_AUTO, e.target.checked ? '1' : '0'); }
    });

    document.addEventListener('click', function (e) {
        if (!e.target || !e.target.closest) { return; }
        var th = e.target.closest('.iptv-tbl thead th.iptv-sortable');
        if (th) {
            var table = th.closest('table');
            var idx = Array.prototype.indexOf.call(th.parentNode.children, th);
            var st = sortState(table);
            var cur = st ? st.split(':') : null;
            var asc = !(cur && parseInt(cur[0], 10) === idx && cur[1] === 'asc');
            var dir = asc ? 1 : -1;
            store(LS_SORT + (table.id || 'x'), idx + ':' + (asc ? 'asc' : 'desc'));
            sortRows(table, idx, dir);
            markTh(table, idx, dir);
            return;
        }
        var more = e.target.closest('#iptv-more');
        if (more) { showAll = !showAll; apply(); return; }
        var cp = e.target.closest('.iptv-copy');
        if (cp) { copyText(cp.getAttribute('data-copy') || '', cp); }
    });

    /* Skrót klawiszowy "/" fokusuje filtr q */
    document.addEventListener('keydown', function (e) {
        if (e.key !== '/') { return; }
        var ae = document.activeElement;
        if (ae && /^(INPUT|TEXTAREA|SELECT)$/.test(ae.tagName)) { return; }
        var q = qInput();
        if (q) {
            e.preventDefault();
            q.focus();
            q.select();
        }
    });

    initPrefs();
    initSortable();
    apply();
    setInterval(autoRefresh, REFRESH_MS);
})();
