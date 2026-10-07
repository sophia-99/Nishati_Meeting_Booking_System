/**
 * MRS — Button icons (SVG) + animation hooks
 * ------------------------------------------------------------------
 * Kila button ya mfumo inapata icon ya kazi yake (SVG, si PNG/emoji).
 * Faili hii haibadilishi HTML ya kurasa: inatafuta button kwenye DOM
 * kisha inaingiza SVG ya moja kwa moja (auto-inject).
 *
 * Jinsi ya kubadilisha icon ya button fulani:
 *   - weka `data-icon="jina_la_icon"` kwenye button (inashinda matching),
 *   - au ongeza/boresha RULES hapa chini.
 *
 * Viungo vya nje: window.mrsApplyIcons(root) — ili kurudisha icons
 * baada ya kubadilisha HTML kwa mkono.
 */
(function () {
    'use strict';

    /* ------------------------------------------------------------------
     * 1. MAKTABA YA ICONS
     * Mtindo ule ule wa navbar: viewBox 0 0 24 24, stroke=currentColor.
     * ------------------------------------------------------------------ */
    var ICONS = {
        'calendar-plus': '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18"/><path d="M8 3v4"/><path d="M16 3v4"/><path d="M12 13.5v5"/><path d="M9.5 16h5"/>',
        'calendar-check': '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18"/><path d="M8 3v4"/><path d="M16 3v4"/><path d="m9 15.5 2 2 4-4"/>',
        'x': '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
        'x-circle': '<circle cx="12" cy="12" r="9"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/>',
        'check': '<path d="M5 12.5 9.5 17 19 7"/>',
        'check-circle': '<circle cx="12" cy="12" r="9"/><path d="m8.5 12.5 2.5 2.5 4.5-5"/>',
        'log-in': '<path d="M15 3h4a1 1 0 0 1 1 1v16a1 1 0 0 1-1 1h-4"/><path d="m10 17 5-5-5-5"/><path d="M15 12H3"/>',
        'log-out': '<path d="M9 21H5a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/>',
        'user-plus': '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 19c.8-3.2 3.4-5 6.5-5 1.3 0 2.5.3 3.5.9"/><path d="M17.5 14v6"/><path d="M14.5 17h6"/>',
        'key': '<circle cx="8" cy="15.5" r="3.5"/><path d="m10.6 13 8-8"/><path d="m16.5 7 2 2"/><path d="m14 9.5 2 2"/>',
        'shield-check': '<path d="M12 3l7 3v5c0 4.5-3 7.5-7 9-4-1.5-7-4.5-7-9V6l7-3z"/><path d="m9.5 12 1.8 1.8L15 10"/>',
        'send': '<path d="M21 3 10.5 13.5"/><path d="M21 3 14.5 21l-4-8-8-4L21 3z"/>',
        'mail': '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3.5 7 8.5 6 8.5-6"/>',
        'refresh': '<path d="M21 12a9 9 0 1 1-2.64-6.36"/><path d="M21 4v5h-5"/>',
        'eye': '<path d="M2 12s3.6-6.5 10-6.5S22 12 22 12s-3.6 6.5-10 6.5S2 12 2 12z"/><circle cx="12" cy="12" r="2.75"/>',
        'eye-off': '<path d="m3 3 18 18"/><path d="M10.6 6.2A10.7 10.7 0 0 1 12 6c6.4 0 10 6 10 6a18.5 18.5 0 0 1-3.7 4.3"/><path d="M6.6 7.9A17.6 17.6 0 0 0 2 12s3.6 6 10 6a10.6 10.6 0 0 0 3.7-.65"/><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/>',
        'filter': '<path d="M3 5h18l-7 8.2V19l-4 2v-7.8L3 5z"/>',
        'download': '<path d="M12 3v11"/><path d="m7.5 10.5 4.5 4.5 4.5-4.5"/><path d="M4 19.5h16"/>',
        'plus-circle': '<circle cx="12" cy="12" r="9"/><path d="M12 8.5v7"/><path d="M8.5 12h7"/>',
        'alert-triangle': '<path d="M12 4 2.8 20h18.4L12 4z"/><path d="M12 10v4"/><path d="M12 17h.01"/>',
        'edit': '<path d="M12 20.5h8.5"/><path d="M16.4 3.6a2.05 2.05 0 0 1 2.9 2.9L7.5 18.3l-4 1.2 1.2-4L16.4 3.6z"/>',
        'eye-view': '<path d="M2 12s3.6-6.5 10-6.5S22 12 22 12s-3.6 6.5-10 6.5S2 12 2 12z"/><circle cx="12" cy="12" r="2.75"/>',
        'arrow-left': '<path d="M19.5 12H5"/><path d="m11 18-6-6 6-6"/>',
        'chevron-left': '<path d="m14.5 6-6 6 6 6"/>',
        'chevron-right': '<path d="m9.5 6 6 6-6 6"/>',
        'save': '<path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><path d="M17 21v-8H7v8"/><path d="M7 3v5h7"/>',
        'lock': '<rect x="4" y="10.5" width="16" height="10.5" rx="2"/><path d="M8 10.5V7a4 4 0 0 1 8 0v3.5"/>',
        'unlock': '<rect x="4" y="10.5" width="16" height="10.5" rx="2"/><path d="M8 10.5V7a4 4 0 0 1 7.6-1.6"/>',
        'trash': '<path d="M3.5 6h17"/><path d="M8.5 6V4.5A1.5 1.5 0 0 1 10 3h4a1.5 1.5 0 0 1 1.5 1.5V6"/><path d="M18.5 6l-.9 14a1 1 0 0 1-1 .9H7.4a1 1 0 0 1-1-.9L5.5 6"/><path d="M10 10.5v6"/><path d="M14 10.5v6"/>',
        'clock': '<circle cx="12" cy="12" r="9"/><path d="M12 7v5.3l3.2 1.9"/>',
        'power': '<path d="M12 3v9"/><path d="M7.4 6.6a7.5 7.5 0 1 0 9.2 0"/>',
        'dots': '<path stroke-width="3" d="M12 5h.01"/><path stroke-width="3" d="M12 12h.01"/><path stroke-width="3" d="M12 19h.01"/>'
    };

    /* ------------------------------------------------------------------
     * 2. SHERIA ZA KUCHANGA: maandishi ya button -> jina la icon
     * mpangilio ni muhimu (detected kutoka juu hadi chini).
     * ------------------------------------------------------------------ */
    var RULES = [
        [/confirm booking/, 'calendar-check'],
        [/book this room|^book$/, 'calendar-plus'],
        [/cancel this day|cancel entire series|cancel .*day|cancel .*series/, 'x-circle'],
        [/^cancel$|^close$/, 'x'],
        [/postpone/, 'clock'],
        [/report issue/, 'alert-triangle'],
        [/delete/, 'trash'],
        [/mark resolved|^resolved$/, 'check-circle'],
        [/change status|toggle status|deactivate|activate/, 'refresh'],
        [/switch to edit|\bunlock\b/, 'unlock'],
        [/switch to read-?only|^\block\b/, 'lock'],
        [/\bedit\b/, 'edit'],
        [/^view\b/, 'eye'],
        [/\bpassword\b/, 'key'],
        [/save changes|^save\b/, 'save'],
        [/verify/, 'shield-check'],
        [/resend/, 'refresh'],
        [/send test|test email/, 'send'],
        [/send code/, 'send'],
        [/send link|send reset|forgot/, 'mail'],
        [/sign in|^log ?in$|login/, 'log-in'],
        [/log ?out|logout/, 'log-out'],
        [/register|sign ?up/, 'user-plus'],
        [/filter/, 'filter'],
        [/^apply$/, 'check'],
        [/clear log|clear audit log|delete log/, 'trash'],
        [/^clear/, 'refresh'],
        [/export|download/, 'download'],
        [/^add\b/, 'plus-circle'],
        [/^prev/, 'chevron-left'],
        [/^next/, 'chevron-right'],
        [/^back\b/, 'arrow-left'],
        [/^ok$|^continue$|^done$|^finish$/, 'check']
    ];

    /* Buttons zilizo na "class" maalum — zinatanguliza kuliko maandishi */
    var CLASS_ICONS = [
        ['password-toggle', null],          /* maandishi huamua: eye / eye-off */
        ['mrs-kebab-toggle', 'dots'],
        ['mrs-postpone-booking', 'clock'],
        ['mrs-report-issue', 'alert-triangle'],
        ['mrs-edit-room', 'edit'],
        ['mrs-edit-projector', 'edit'],
        ['mrs-edit-user', 'edit']
    ];

    var SELECTOR = [
        'button',
        'a.btn',
        '.link-btn',
        '.password-toggle',
        '.mrs-kebab-menu a',
        '.mrs-kebab-menu button',
        '.mrs-postpone-booking',
        '.mrs-report-issue',
        '[data-icon]'
    ].join(',');

    function normalize(text) {
        return String(text || '')
            .toLowerCase()
            .replace(/[^a-z0-9]+/g, ' ')
            .replace(/\s+/g, ' ')
            .trim();
    }

    function svgMarkup(name) {
        if (!ICONS[name]) return '';
        return '<svg class="mrs-btn-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" ' +
            'stroke-width="2" stroke-linecap="round" stroke-linejoin="round" ' +
            'aria-hidden="true" focusable="false">' + ICONS[name] + '</svg>';
    }

    function pickIcon(el) {
        /* 1. kipengele cha wazi: data-icon="..." */
        if (el.dataset && el.dataset.icon && ICONS[el.dataset.icon]) return el.dataset.icon;

        /* 2. kulingana na class ya button */
        var i, pair;
        for (i = 0; i < CLASS_ICONS.length; i++) {
            pair = CLASS_ICONS[i];
            if (el.classList.contains(pair[0])) {
                if (pair[1]) return pair[1];
                /* password-toggle: text hubadilika Show <-> Hide */
                return /hide|off/i.test(el.textContent) ? 'eye-off' : 'eye';
            }
        }

        /* 3. kulingana na maandishi (label / aria-label) */
        var label = normalize(el.getAttribute('aria-label') || el.textContent || '');
        if (!label) return null;
        for (i = 0; i < RULES.length; i++) {
            if (RULES[i][0].test(label)) return RULES[i][1];
        }
        return null;
    }

    function applyTo(el) {
        if (!el || el.nodeType !== 1 || !el.matches || !el.matches(SELECTOR)) return;
        if (el.dataset.mrsIconSkip === '1') return;

        /* Kama tayari ina SVG yake mwenyewe (mf. navbar) — siingilii */
        if (el.querySelector('svg:not(.mrs-btn-icon)')) {
            el.dataset.mrsIconSkip = '1';
            return;
        }

        var name = pickIcon(el);
        if (!name) name = el.dataset.mrsIconName || null;   /* text huondolewa (mf. kebab) */
        if (!name) return;

        var existing = el.querySelector('svg.mrs-btn-icon');
        if (existing && el.dataset.mrsIconName === name) return;

        if (existing) existing.remove();
        /* Kebab toggle: ondoa maandishi ya "|" (⋮) — ni SVG pekee inayobaki */
        if (el.classList.contains('mrs-kebab-toggle')) el.textContent = '';
        el.insertAdjacentHTML('afterbegin', svgMarkup(name));
        el.dataset.mrsIconName = name;
    }

    function scan(root) {
        var scope = root || document;
        if (!scope || !scope.querySelectorAll) return;
        if (scope.nodeType === 1 && scope.matches && scope.matches(SELECTOR)) applyTo(scope);
        var list = scope.querySelectorAll(SELECTOR);
        for (var i = 0; i < list.length; i++) applyTo(list[i]);
    }

    /* ------------------------------------------------------------------
     * 3. FUNGUA: sasa + HTML mpya (modal, kebab menu, n.k.)
     * ------------------------------------------------------------------ */
    function boot() {
        scan(document);

        if (typeof MutationObserver === 'undefined' || !document.body) return;
        var observer = new MutationObserver(function (records) {
            for (var i = 0; i < records.length; i++) {
                var rec = records[i];
                if (rec.type === 'characterData') {
                    var parent = rec.target && rec.target.parentElement;
                    if (parent) applyTo(parent);            /* mf. Show -> Hide */
                } else if (rec.type === 'childList') {
                    for (var j = 0; j < rec.addedNodes.length; j++) {
                        var node = rec.addedNodes[j];
                        if (node.nodeType === 1) scan(node); /* modal mpya, kadi mpya */
                    }
                    /* Mzazi akibadilisha maandishi yake (mf. "Show" -> "Hide"),
                       text node huchukuliwa — hivyo tujadiliane naye moja kwa moja. */
                    if (rec.target && rec.target.nodeType === 1) applyTo(rec.target);
                }
            }
        });
        observer.observe(document.body, {
            childList: true,
            subtree: true,
            characterData: true
        });

        /* Vyeo vya nje kwa code nyingine (ui.js n.k.) */
        window.mrsApplyIcons = function (root) { scan(root || document); };
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
