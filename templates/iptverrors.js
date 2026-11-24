/* LMSIptvErrorsPlugin — logika widoku "Błędy IPTV" (templates/iptverrors.js)
 * Stringy użytkownika pobierane z atrybutów data-* kontenera #iptv-errors-content
 * (wstrzykiwane w szablonie przez {trans()|escape}), nie z literałów JS.
 */
(function () {
    'use strict';

    var LS_ONLY = 'iptv-only';
    var LS_AUTO = 'iptv-auto';
    var REFRESH_MS = 60000;
    var showAll = false;

    function store(k, v) { try { localStorage.setItem(k, v); } catch (e) {} }
    function load(k) { try { return localStorage.getItem(k); } catch (e) { return null; } }
    function content() { return document.getElementById('iptv-errors-content'); }
    function qInput() { return document.getElementById('iptv-q'); }
    function onlyBox() { return document.getElementById('iptv-only'); }
    function autoBox() { return document.getElementById('iptv-auto'); }
    function label(name) {
        var c = content();
        return c ? (c.getAttribute('data-' + name) || '') : '';
    }

    function rows() {
        var c = content();
        return c ? c.querySelectorAll('.iptv-tbl tbody tr') : [];
    }

    function apply() {
        var q = qInput();
        var only = onlyBox();
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
        if (e.target.id === 'iptv-auto') { store(LS_AUTO, e.target.checked ? '1' : '0'); }
    });

    document.addEventListener('click', function (e) {
        if (!e.target || !e.target.closest) { return; }
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
    apply();
    setInterval(autoRefresh, REFRESH_MS);
})();
