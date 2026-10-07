/**
 * MRS — Dark / Light mode
 * ------------------------------------------------------------------
 * Jukumu la faili hii:
 *   1. KUBADILISHA mode (bofya [data-theme-toggle] au fungua kwa keyboard)
 *   2. KUONGEZA class `theme-anim` kwa <html> ili CSS ifanye transition
 *      POLE POLE laini (0.6s) wakati wa kubadilisha — kisha kuiondoa.
 *   3. KUHIFADHI choice kwenye localStorage ('mrs_theme') ili ikidumu.
 *   4. Kusasisha aria-checked / aria-label / title ya switch zote.
 *
 * Mwanzo wa mode: script ndogo kwenye <head> ya kila ukurasa (boot)
 * inasoma localStorage au systemu (prefers-color-scheme) KABLA ya render
 * — hivyo hakuna "flash" ya rangi isiyotarajiwa.
 *
 * API: window.mrsTheme.get() / .set('dark'|'light') / .toggle() / .stored()
 */
(function () {
    'use strict';

    var KEY = 'mrs_theme';
    var root = document.documentElement;
    var animTimer = null;

    function stored() {
        try {
            var v = localStorage.getItem(KEY);
            return (v === 'dark' || v === 'light') ? v : null;
        } catch (e) { return null; }
    }

    function system() {
        try {
            return (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches)
                ? 'dark' : 'light';
        } catch (e) { return 'light'; }
    }

    function current() {
        return root.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
    }

    /* Inasasisha hali ya switch zote (navbar + logo ya auth) */
    function sync(mode) {
        var dark = mode === 'dark';
        var label = dark ? 'Switch to light mode' : 'Switch to dark mode';
        var nodes = document.querySelectorAll('[data-theme-toggle]');
        for (var i = 0; i < nodes.length; i++) {
            nodes[i].setAttribute('aria-checked', dark ? 'true' : 'false');
            nodes[i].setAttribute('aria-label', label);
            nodes[i].setAttribute('title', label);
        }
    }

    function apply(mode, animate) {
        if (animate) {
            root.classList.add('theme-anim');      /* huanza transition pole pole */
            clearTimeout(animTimer);
            animTimer = setTimeout(function () {
                root.classList.remove('theme-anim'); /* hover/transform kurudi kawaida */
            }, 750);
        }
        root.setAttribute('data-theme', mode);
        try { localStorage.setItem(KEY, mode); } catch (e) {}
        sync(mode);
    }

    function toggle() {
        apply(current() === 'dark' ? 'light' : 'dark', true);
    }

    function target(e) {
        return (e && e.target && e.target.closest) ? e.target.closest('[data-theme-toggle]') : null;
    }

    /* Bofya popote kwenye kipengele cha kubadilisha */
    document.addEventListener('click', function (e) {
        if (!target(e)) return;
        e.preventDefault();
        toggle();
    });

    /* Keyboard: Enter / Space (accessibility) */
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter' && e.key !== ' ' && e.key !== 'Spacebar') return;
        if (!target(e)) return;
        e.preventDefault();
        toggle();
    });

    /* Bado hakuna choice iliyohifadhiwa → fuata systemu ikibadilika */
    try {
        var mq = window.matchMedia('(prefers-color-scheme: dark)');
        var onChange = function () { if (!stored()) apply(system(), false); };
        if (mq.addEventListener) mq.addEventListener('change', onChange);
        else if (mq.addListener) mq.addListener(onChange);
    } catch (e) {}

    /* DOM ikiwa tayari (au baada ya kupakia) — sync hali ya switch */
    sync(current());
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { sync(current()); });
    }

    /* API kwa ajili ya majaribio na code nyingine */
    window.mrsTheme = {
        get: current,
        set: function (m) { apply(m === 'dark' ? 'dark' : 'light', true); },
        toggle: toggle,
        stored: stored
    };
})();
