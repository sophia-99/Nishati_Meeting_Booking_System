/* ===========================================================================
 * SESSION CONTROL — dakika 10 bila shughuli => auto-logout
 * assets/js/session_timeout.js
 *
 * Jinsi inavyofanya kazi:
 *  1. Kila shughuli ya mtumiaji (mouse, key, touch, scroll) husasisha
 *     "lastActivity" na kupiga ping kwenye session_ping.php ili server
 *     iwe juu ya muda halisi wa shughuli (last_activity).
 *  2. Baada ya sekunde za WARN_AT (dakika 9) popup ya onyo inaonekana na
 *     hesabu ya kurudi nyuma (dakika 1).
 *  3. Mtumiaji anaweza kuchagua: "Stay signed in" (session inaendelea) au
 *     "Sign out now". Bila uchaguzi, sekunde 60 zikisha => auto logout.
 *  4. Server pia ina kikolo chake (session_guard) — JS hii ni onyo tu.
 * =========================================================================== */
(function () {
    'use strict';

    var cfg = window.MRS_SESSION || {};

    var IDLE_LIMIT = parseInt(cfg.idleLimit, 10);
    if (!IDLE_LIMIT || IDLE_LIMIT < 60) IDLE_LIMIT = 600;      // dakika 10
    var WARN_AT = parseInt(cfg.warnAt, 10);
    if (!WARN_AT || WARN_AT >= IDLE_LIMIT) WARN_AT = IDLE_LIMIT - 60;

    var PING_URL = cfg.pingUrl || 'session_ping.php';
    var PING_EVERY = 5000;   // sekunde 5 kati ya ping (bila shughuli hakuna ping)

    var lastActivity = Date.now();
    var lastPing = 0;
    var pinging = false;
    var goingOut = false;
    var overlay = null;
    var countEl = null;
    var ticker = null;

    /* ---------------------------------------------------------------------
     * PING — sasisha last_activity kwenye server
     * ------------------------------------------------------------------- */
    function ping(force) {
        if (goingOut || pinging) return false;
        var now = Date.now();
        if (!force && now - lastPing < PING_EVERY) return false;
        lastPing = now;
        pinging = true;

        try {
            var xhr = new XMLHttpRequest();
            xhr.open('GET', PING_URL + (PING_URL.indexOf('?') >= 0 ? '&' : '?') + '_=' + now, true);
            xhr.withCredentials = true;
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.setRequestHeader('Accept', 'application/json');
            xhr.onreadystatechange = function () {
                if (xhr.readyState !== 4) return;
                if (xhr.status === 0) { pinging = false; return; } // network: jaribu tena baadaye
                var body = null;
                try { body = JSON.parse(xhr.responseText); } catch (e) { body = null; }
                pinging = false;
                // Session imeisha (401 / ok:false / HTML ya login) => toka
                if (!(xhr.status === 200 && body && body.ok === true)) signOut(true);
            };
            xhr.send(null);
        } catch (e) {
            pinging = false;
        }
        return true;
    }

    /* ---------------------------------------------------------------------
     * KUTOKA — nenda logout (URL ya CSRF kutoka navbar)
     * ------------------------------------------------------------------- */
    function signOut(expired) {
        if (goingOut) return;
        goingOut = true;
        hideWarning();

        var url = cfg.logoutUrl || '';
        if (!url) {
            var link = document.querySelector('.nav-logout');
            url = link ? link.getAttribute('href') : 'logout.php';
            if (expired && url.indexOf('expired=') === -1) {
                url += (url.indexOf('?') >= 0 ? '&' : '?') + 'expired=1';
            }
        }
        window.location.href = url;
    }

    /* ---------------------------------------------------------------------
     * POPUP YA ONYO
     * ------------------------------------------------------------------- */
    function showWarning() {
        if (overlay || goingOut) return;

        overlay = document.createElement('div');
        overlay.id = 'mrsIdleModal';
        overlay.className = 'mrs-modal-overlay';
        overlay.setAttribute('role', 'alertdialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.innerHTML =
            '<div class="mrs-modal-card" role="dialog" aria-labelledby="mrsIdleTitle">' +
                '<div class="mrs-modal-icon danger" aria-hidden="true">!</div>' +
                '<h3 id="mrsIdleTitle">Session expiring</h3>' +
                '<p>You have been inactive. You will be signed out in ' +
                    '<strong id="mrsIdleCount">60</strong> seconds unless you continue.</p>' +
                '<div class="mrs-modal-actions">' +
                    '<button type="button" class="btn btn-secondary" data-idle-stay>Stay signed in</button>' +
                    '<button type="button" class="btn btn-danger" data-idle-out>Sign out now</button>' +
                '</div>' +
            '</div>';
        document.body.appendChild(overlay);
        document.body.classList.add('modal-open');

        countEl = overlay.querySelector('#mrsIdleCount');
        overlay.querySelector('[data-idle-stay]').addEventListener('click', stay);
        overlay.querySelector('[data-idle-out]').addEventListener('click', function () {
            signOut(true);
        });
        // Kusi-click backdrop: chagua kwa hiari (huondoa bila kuahirisha)
        overlay.addEventListener('click', function (ev) {
            if (ev.target === overlay) ev.preventDefault();
        });

        ticker = window.setInterval(updateCount, 500);
        updateCount();
    }

    function hideWarning() {
        if (ticker) { window.clearInterval(ticker); ticker = null; }
        if (overlay && overlay.parentNode) overlay.parentNode.removeChild(overlay);
        overlay = null;
        countEl = null;
        if (!document.querySelector('.mrs-modal-overlay')) {
            document.body.classList.remove('modal-open');
        }
    }

    function updateCount() {
        if (!overlay) return;
        var idle = (Date.now() - lastActivity) / 1000;
        var left = Math.ceil(IDLE_LIMIT - idle);
        if (left < 0) left = 0;
        if (countEl) countEl.textContent = String(left);
        if (left <= 0) signOut(true);
    }

    function stay() {
        lastActivity = Date.now();
        hideWarning();
        ping(true);
    }

    /* ---------------------------------------------------------------------
     * SHUGHULI YA MTUMIAJI
     * ------------------------------------------------------------------- */
    function onActivity(ev) {
        if (goingOut) return;
        // Vitufe vya popup (Stay / Sign out) vinasimamiwa na wenyewe
        if (overlay && ev && ev.target && ev.target.nodeType === 1
            && typeof overlay.contains === 'function' && overlay.contains(ev.target)) {
            return;
        }
        lastActivity = Date.now();
        ping(false);
        if (overlay) hideWarning();
    }

    function tick() {
        if (goingOut) return;
        var idle = (Date.now() - lastActivity) / 1000;
        if (idle >= IDLE_LIMIT) { signOut(true); return; }
        if (idle >= WARN_AT) {
            if (!overlay) showWarning(); else updateCount();
        }
    }

    ['mousemove', 'mousedown', 'keydown', 'wheel', 'touchstart', 'scroll', 'click']
        .forEach(function (type) {
            document.addEventListener(type, onActivity, { passive: true });
        });

    window.setInterval(tick, 500);
})();
