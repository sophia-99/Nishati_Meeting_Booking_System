(function () {
    'use strict';

    function escapeHtml(value) {
        return String(value).replace(/[&<>"']/g, function (char) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[char];
        });
    }

    // CSRF: token inatoka kwenye navbar (window.MRS_CSRF) au fomu yoyopo ya kwenye page
    function csrfToken() {
        if (window.MRS_CSRF) return String(window.MRS_CSRF);
        var existing = document.querySelector('input[name="csrf_token"]');
        return existing ? existing.value : '';
    }

    // Rudisha HTML ya input ya CSRF kwa fomu zinazotengenezwa na JavaScript
    function csrfFieldHtml() {
        return '<input type="hidden" name="csrf_token" value="' + escapeHtml(csrfToken()) + '">';
    }

    function closeModal() {
        var overlay = document.getElementById('mrsModal');
        if (overlay) overlay.remove();
        document.body.classList.remove('modal-open');
    }

    function openModal(options) {
        closeModal();
        var mode = options.mode || 'confirm';
        var isResult = mode === 'success' || mode === 'error';
        var danger = mode === 'error' || options.danger === true;
        var iconClass = 'mrs-modal-icon';
        var iconInner = '';

        if (mode === 'success') {
            iconClass += ' result success';
            iconInner =
                '<span class="mrs-icon-spinner" aria-hidden="true"></span>' +
                '<svg class="mrs-icon-mark" viewBox="0 0 24 24" aria-hidden="true">' +
                    '<path class="mrs-icon-mark-path" d="M5 13l4 4L19 7" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>' +
                '</svg>';
        } else if (mode === 'error') {
            iconClass += ' result error';
            iconInner =
                '<span class="mrs-icon-spinner" aria-hidden="true"></span>' +
                '<svg class="mrs-icon-mark" viewBox="0 0 24 24" aria-hidden="true">' +
                    '<path class="mrs-icon-mark-path x1" d="M7 7l10 10" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"/>' +
                    '<path class="mrs-icon-mark-path x2" d="M17 7L7 17" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"/>' +
                '</svg>';
        } else {
            iconClass += danger ? ' danger' : ' info';
            iconInner = danger ? '!' : 'i';
        }

        var actions =
            '<div class="mrs-modal-actions">' +
                (isResult
                    ? '<button type="button" class="btn ' + (danger ? 'btn-danger' : '') + '" data-modal-confirm>OK</button>'
                    : '<button type="button" class="btn btn-secondary" data-modal-cancel>Cancel</button>' +
                      '<button type="button" class="btn ' + (danger ? 'btn-danger' : '') + '" data-modal-confirm>' + escapeHtml(options.confirmText || 'OK') + '</button>') +
            '</div>';

        var overlay = document.createElement('div');
        overlay.id = 'mrsModal';
        overlay.className = 'mrs-modal-overlay';
        overlay.innerHTML =
            '<div class="mrs-modal-card" role="dialog" aria-modal="true" aria-labelledby="mrsModalTitle">' +
                '<div class="' + iconClass + '">' + iconInner + '</div>' +
                '<h3 id="mrsModalTitle">' + escapeHtml(options.title || 'Please confirm') + '</h3>' +
                '<p>' + escapeHtml(options.message || '') + '</p>' +
                actions +
            '</div>';
        document.body.appendChild(overlay);
        document.body.classList.add('modal-open');

        if (mode === 'success' || mode === 'error') {
            var iconEl = overlay.querySelector('.mrs-modal-icon');
            var reveal = function () {
                overlay.classList.add('mrs-result-revealed');
                if (iconEl) iconEl.classList.add('mrs-result-revealed');
            };
            window.setTimeout(reveal, 700);
            // Fallback: force reveal even if timer/background tab delays
            window.setTimeout(reveal, 1400);
        }

        var cancelBtn = overlay.querySelector('[data-modal-cancel]');
        if (cancelBtn) {
            cancelBtn.addEventListener('click', closeModal);
        }
        overlay.querySelector('[data-modal-confirm]').addEventListener('click', function () {
            closeModal();
            if (typeof options.onConfirm === 'function') options.onConfirm();
        });
        overlay.addEventListener('click', function (event) {
            if (event.target === overlay) closeModal();
        });
    }

    /* --------------------------------------------------------------
     * KALENDA YA TAREHE — popup ya mwezi kama ile ya picha
     * ( < September [v] 2026 > + grid ya siku + display DD/MM/YYYY)
     * - isAnyDay:true  => siku ZOTE zinawaka (booking single / jumla);
     *   siku za nyuma ya min zimezimwa (leo + future tu kama min=leo).
     * - getWeekday()   => continuous: siku za weekday iliyochaguliwa
     *   pekee ndizo zinawaka; bila kuchagua => "Choose the meeting day
     *   first..." (kama picha ya pili).
     * - opts hujilinda kwenye input._mrsCalOpts — mwombaji wa pili
     *   (mf. setupBookingType) huongeza sheria zake bila kutengeneza
     *   kalenda nyingine.
     * -------------------------------------------------------------- */
    function createMrsCalendar(input, opts) {
        if (!input) return null;
        opts = opts || {};
        if (input.dataset.calReady === '1') {
            input._mrsCalOpts = Object.assign(input._mrsCalOpts || {}, opts);
            if (input._mrsCal) input._mrsCal.refresh();
            return input._mrsCal;
        }
        input.dataset.calReady = '1';
        input._mrsCalOpts = opts;

        var wrap = document.createElement('div');
        wrap.className = 'mrs-cal-wrap';
        wrap.style.display = 'none';

        var display = document.createElement('input');
        display.type = 'text';
        display.readOnly = true;
        display.className = 'mrs-cal-input';
        display.placeholder = 'DD/MM/YYYY';
        wrap.appendChild(display);

        var popup = document.createElement('div');
        popup.className = 'mrs-cal-popup';
        popup.style.display = 'none';
        wrap.appendChild(popup);

        var today = new Date();
        var viewY = today.getFullYear();
        var viewM = today.getMonth();
        try {
            var cur = input.value ? new Date(input.value + 'T00:00:00') : null;
            var min0 = opts.getMin ? opts.getMin() : (input.getAttribute('min') || '');
            var base = cur || (min0 ? new Date(min0 + 'T00:00:00') : today);
            viewY = base.getFullYear();
            viewM = base.getMonth();
        } catch (e) {}

        var monthNamesFull = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
        var pad2 = function (n) { return (n < 10 ? '0' : '') + n; };
        var toYMD = function (dt) { return dt.getFullYear() + '-' + pad2(dt.getMonth() + 1) + '-' + pad2(dt.getDate()); };
        var toDMY = function (ymd) { var p = String(ymd || '').split('-'); return (p.length === 3) ? (p[2] + '/' + p[1] + '/' + p[0]) : ''; };

        function closePopup() { popup.style.display = 'none'; }
        function openPopup() {
            document.querySelectorAll('.mrs-cal-popup').forEach(function (p) { if (p !== popup) p.style.display = 'none'; });
            syncViewFromInput();
            render();
            popup.style.display = 'block';
        }

        function syncViewFromInput() {
            try {
                var v = input.value ? new Date(input.value + 'T00:00:00') : null;
                if (v && !isNaN(v)) { viewY = v.getFullYear(); viewM = v.getMonth(); return; }
                var minS = opts.getMin ? opts.getMin() : '';
                var b = minS ? new Date(minS + 'T00:00:00') : new Date();
                if (b && !isNaN(b)) { viewY = b.getFullYear(); viewM = b.getMonth(); }
            } catch (e) {}
        }

        function syncDisplay() {
            display.value = toDMY(input.value);
        }

        function render() {
            var O = input._mrsCalOpts || opts;
            var wd = O.getWeekday ? O.getWeekday() : null;
            var anyDay = typeof O.isAnyDay === 'function' ? O.isAnyDay() : !!O.isAnyDay;
            var minS = O.getMin ? O.getMin() : (input.getAttribute('min') || '');
            var minD = null;
            try { if (minS) minD = new Date(minS + 'T00:00:00'); } catch (e) {}
            var dayNames = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
            var wdName = (wd !== null && dayNames[wd]) ? dayNames[wd] : '';

            popup.innerHTML = '';

            var head = document.createElement('div');
            head.className = 'mrs-cal-head';

            var prev = document.createElement('button');
            prev.type = 'button';
            prev.className = 'mrs-cal-nav';
            prev.textContent = '‹';
            prev.setAttribute('aria-label', 'Previous month');
            prev.dataset.mrsIconSkip = '1';
            prev.addEventListener('click', function (ev) {
                ev.stopPropagation();
                viewM--;
                if (viewM < 0) { viewM = 11; viewY--; }
                render();
            });

            var title = document.createElement('div');
            title.className = 'mrs-cal-title';
            var monSel = document.createElement('select');
            monSel.className = 'mrs-cal-month';
            monthNamesFull.forEach(function (nm, idx) {
                var o = document.createElement('option');
                o.value = String(idx);
                o.textContent = nm;
                if (idx === viewM) o.selected = true;
                monSel.appendChild(o);
            });
            monSel.addEventListener('change', function () {
                viewM = parseInt(monSel.value, 10) || 0;
                render();
            });
            monSel.addEventListener('click', function (ev) { ev.stopPropagation(); });
            var yearSpan = document.createElement('span');
            yearSpan.className = 'mrs-cal-year';
            yearSpan.textContent = String(viewY);
            title.appendChild(monSel);
            title.appendChild(yearSpan);

            var next = document.createElement('button');
            next.type = 'button';
            next.className = 'mrs-cal-nav';
            next.textContent = '›';
            next.setAttribute('aria-label', 'Next month');
            next.dataset.mrsIconSkip = '1';
            next.addEventListener('click', function (ev) {
                ev.stopPropagation();
                viewM++;
                if (viewM > 11) { viewM = 0; viewY++; }
                render();
            });

            head.appendChild(prev);
            head.appendChild(title);
            head.appendChild(next);
            popup.appendChild(head);

            if (wd === null && !anyDay) {
                var hint = document.createElement('div');
                hint.className = 'mrs-cal-hint';
                hint.textContent = 'Choose the meeting day first...';
                popup.appendChild(hint);
            }

            var dow = document.createElement('div');
            dow.className = 'mrs-cal-dow';
            ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'].forEach(function (d) {
                var s = document.createElement('span');
                s.textContent = d;
                dow.appendChild(s);
            });
            popup.appendChild(dow);

            var grid = document.createElement('div');
            grid.className = 'mrs-cal-grid';
            var first = new Date(viewY, viewM, 1);
            var startOffset = first.getDay();
            var daysInMonth = new Date(viewY, viewM + 1, 0).getDate();
            var daysInPrev = new Date(viewY, viewM, 0).getDate();

            for (var i = 0; i < 42; i++) {
                var cellDate, otherMonth = false;
                var dayNum;
                if (i < startOffset) {
                    dayNum = daysInPrev - startOffset + 1 + i;
                    cellDate = new Date(viewY, viewM - 1, dayNum);
                    otherMonth = true;
                } else if (i >= startOffset + daysInMonth) {
                    dayNum = i - startOffset - daysInMonth + 1;
                    cellDate = new Date(viewY, viewM + 1, dayNum);
                    otherMonth = true;
                } else {
                    dayNum = i - startOffset + 1;
                    cellDate = new Date(viewY, viewM, dayNum);
                }

                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'mrs-cal-day';
                btn.textContent = String(cellDate.getDate());
                btn.dataset.mrsIconSkip = '1';

                var ymd = toYMD(cellDate);
                var isMatch = anyDay || (wd !== null && cellDate.getDay() === wd);
                var isPast = (minD && cellDate < new Date(minD.getFullYear(), minD.getMonth(), minD.getDate()));
                var disabled = otherMonth || !isMatch || !!isPast;
                if (otherMonth) btn.classList.add('is-other');
                if (!isMatch) btn.classList.add('is-off');
                if (isPast && !otherMonth) btn.classList.add('is-past');
                if (input.value === ymd) btn.classList.add('is-selected');
                if (disabled) {
                    btn.disabled = true;
                } else {
                    (function (pick) {
                        btn.addEventListener('click', function (ev) {
                            ev.stopPropagation();
                            input.value = pick;
                            syncDisplay();
                            closePopup();
                            input.dispatchEvent(new Event('change', { bubbles: true }));
                            if (typeof opts.onPick === 'function') opts.onPick(pick);
                        });
                    })(ymd);
                }
                grid.appendChild(btn);
            }
            popup.appendChild(grid);

            if (wdName && O.hintEl) {
                O.hintEl.textContent = O.hintPrefix + ' (must be a ' + wdName + ')';
            }
        }

        display.addEventListener('click', function (ev) {
            ev.stopPropagation();
            if (popup.style.display === 'block') closePopup();
            else openPopup();
        });
        popup.addEventListener('click', function (ev) { ev.stopPropagation(); });

        if (input.parentNode) {
            var anchor = input;
            if (input.parentNode.querySelector('.date-dd')) {
                anchor = input.parentNode.querySelector('.date-dd');
            }
            anchor.parentNode.insertBefore(wrap, anchor.nextSibling);
        }

        var api = {
            wrap: wrap,
            popup: popup,
            display: display,
            render: render,
            refresh: function () { syncDisplay(); if (popup.style.display === 'block') render(); },
            open: openPopup,
            close: closePopup
        };
        input._mrsCal = api;
        syncDisplay();
        return api;
    }

    /* ------------------------------------------------------------------
     * KALENDA YA TAREHE (JUMLA) — kila input[type=date] kwenye mfumo.
     * Badala ya dropdown za DD/MM/YYYY, kila tarehe sasa ni kalenda ya
     * mwezi (display DD/MM/YYYY + popup ya grid kama kwenye picha):
     * - Siku zote za mwezi zinawaka (weekend ndani), isipokuwa siku za
     *   NYUMA ya `min` (mf. booking: min = leo => leo + future tu).
     * - Input halisi hufichwa (.date-dd-store); value hushikwa na kalenda.
     * - `required` huondolewa kwenye input iliyofichwa (Chrome huzuia
     *   submit kimya kwa input ya display:none) — ulinzi: JS guard + server.
     * ------------------------------------------------------------------ */
    function setupDatePickers(root) {
        if (!root) return;
        root.querySelectorAll('input[type="date"]:not([data-cal-ready])').forEach(function (input) {
            if (input.hasAttribute('required')) {
                input.removeAttribute('required');
            }
            input.classList.add('date-dd-store');
            createMrsCalendar(input, {
                isAnyDay: true,
                getMin: function () { return input.getAttribute('min') || ''; }
            });
        });
    }

    function openFormModal(options) {
        closeModal();
        var overlay = document.createElement('div');
        overlay.id = 'mrsModal';
        overlay.className = 'mrs-modal-overlay';
        overlay.innerHTML =
            '<div class="mrs-modal-card mrs-form-modal" role="dialog" aria-modal="true" aria-labelledby="mrsFormModalTitle">' +
                '<h3 id="mrsFormModalTitle">' + escapeHtml(options.title || '') + '</h3>' +
                options.html +
            '</div>';
        document.body.appendChild(overlay);
        document.body.classList.add('modal-open');
        overlay.addEventListener('click', function (event) {
            if (event.target === overlay) closeModal();
        });
        overlay.querySelectorAll('[data-modal-cancel]').forEach(function (button) {
            button.addEventListener('click', closeModal);
        });
        setupDatePickers(overlay);
        if (typeof options.onOpen === 'function') options.onOpen(overlay);
    }

    function showAlert(alert) {
        var isError = alert.classList.contains('alert-error');
        var text = alert.textContent.trim();
        alert.style.display = 'none';
        openModal({
            mode: isError ? 'error' : 'success',
            title: isError ? 'Unable to complete request' : 'Success',
            message: text,
            confirmText: 'OK',
            danger: isError
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        /* Tarehe zilizo nje ya modals (mf. filters za Reports) pia kalenda */
        setupDatePickers(document);
        document.querySelectorAll('.alert').forEach(showAlert);

        var loginForm = document.getElementById('loginForm');
        if (loginForm) {
            loginForm.addEventListener('submit', function (event) {
                if (loginForm.dataset.submitting === '1') return;
                event.preventDefault();
                loginForm.dataset.submitting = '1';
                var loader = document.createElement('div');
                loader.className = 'mrs-loading-overlay';
                loader.innerHTML = '<div class="mrs-loader-card">'
                    + '<img class="mrs-loader-logo" src="../assets/img/coat-of-arms-of-tanzania-logo-png_seeklogo-311608.png?v=1" alt="Coat of Arms of Tanzania">'
                    + '<div class="loader-wrap"><div class="loader"></div></div>'
                    + '<strong class="mrs-loader-text">Confirming your details...</strong>'
                    + '</div>';
                document.body.appendChild(loader);

                /* Maandishi yanayobadilika kila 1.4s: #1 -> #2 -> #3,
                   na yanaishia kwenye la mwisho (hayarudi mwanzo) */
                var loadMessages = ['Confirming your details...', 'Preparing your dashboard...', 'Almost ready...'];
                var msgIndex = 0;
                var msgEl = loader.querySelector('.mrs-loader-text');
                var msgTimer = window.setInterval(function () {
                    msgIndex++;
                    if (msgIndex >= loadMessages.length) { window.clearInterval(msgTimer); return; }
                    msgEl.classList.add('is-out');
                    window.setTimeout(function () {
                        msgEl.textContent = loadMessages[msgIndex];
                        msgEl.classList.remove('is-out');
                    }, 300);
                }, 1400);

                window.setTimeout(function () {
                    window.clearInterval(msgTimer);
                    HTMLFormElement.prototype.submit.call(loginForm);
                }, 4000);
            });
        }

        document.querySelectorAll('form[data-confirm]').forEach(function (form) {
            form.addEventListener('submit', function (event) {
                if (form.dataset.confirmed === '1') {
                    form.dataset.confirmed = '';
                    return;
                }
                event.preventDefault();
                var submitter = event.submitter;
                openModal({
                    title: form.dataset.confirmTitle || 'Confirm action',
                    message: form.dataset.confirm,
                    confirmText: form.dataset.confirmText || 'Continue',
                    danger: form.dataset.confirmDanger === '1',
                    onConfirm: function () {
                        form.dataset.confirmed = '1';
                        if (form.requestSubmit) form.requestSubmit(submitter);
                        else form.submit();
                    }
                });
            });
        });
        document.querySelectorAll('a[data-confirm], button[data-confirm]').forEach(function (element) {
            element.addEventListener('click', function (event) {
                if (element.dataset.confirmed === '1') {
                    element.dataset.confirmed = '';
                    return;
                }
                event.preventDefault();
                openModal({
                    title: element.dataset.confirmTitle || 'Confirm action',
                    message: element.dataset.confirm,
                    confirmText: element.dataset.confirmText || 'Continue',
                    danger: element.dataset.confirmDanger === '1',
                    onConfirm: function () {
                        element.dataset.confirmed = '1';
                        element.click();
                    }
                });
            });
        });

        /* SPRINT 3 (gating): zuia double-submit kwa fomu ZOTE. KUPONZA
           ku-disable vitufe ndani ya submit event: browser huondoa controls
           zilizo-disabled kwenye form data (hata submitter mwenyewe), hivyo
           majina ya vitufe kama "book_room" hupotea na handler ya server
           haiondoi — ukurasa huonekana "reloading" tu bila ujumbe. Badala
           yake: zuia submit event ya pili kwa alama ya kila fomu. */
        document.addEventListener('submit', function (event) {
            var form = event.target;
            if (!form || form.tagName !== 'FORM' || event.defaultPrevented) return;
            if (form.dataset.mrsSubmitted === '1') {
                event.preventDefault();
                return;
            }
            form.dataset.mrsSubmitted = '1';
        });

        function closeKebabMenu(menu) {
            if (!menu) return;
            menu.classList.remove('open');
            if (menu.dataset.originalParent) {
                var parent = document.querySelector(menu.dataset.originalParent);
                if (parent) {
                    parent.appendChild(menu);
                }
                menu.style.left = '';
                menu.style.top = '';
                menu.style.maxHeight = '';
                menu.classList.remove('mrs-kebab-menu-portal');
                delete menu.dataset.originalParent;
            }
        }

        function closeAllKebabMenus(except) {
            document.querySelectorAll('.mrs-kebab-menu.open').forEach(function (openMenu) {
                if (openMenu !== except) closeKebabMenu(openMenu);
            });
        }

        document.querySelectorAll('.mrs-kebab-toggle').forEach(function (button, index) {
            var menu = button.nextElementSibling;
            button.addEventListener('click', function (event) {
                event.stopPropagation();
                if (!menu) return;
                if (menu.classList.contains('open')) {
                    closeKebabMenu(menu);
                    return;
                }

                closeAllKebabMenus(menu);
                var parent = menu.parentElement;
                var parentId = parent.id;
                if (!parentId) {
                    parentId = 'mrs-kebab-' + index;
                    parent.id = parentId;
                }
                menu.dataset.originalParent = '#' + parentId;
                document.body.appendChild(menu);
                menu.classList.add('mrs-kebab-menu-portal');
                menu.classList.add('open');

                var buttonRect = button.getBoundingClientRect();
                var menuWidth = Math.max(menu.offsetWidth, 170);
                var menuHeight = menu.offsetHeight;
                var margin = 8;
                var viewportWidth = window.innerWidth;
                var viewportHeight = window.innerHeight;

                var left = buttonRect.right - menuWidth;
                if (left + menuWidth > viewportWidth - margin) {
                    left = viewportWidth - menuWidth - margin;
                }
                if (left < margin) {
                    left = margin;
                }

                var spaceBelow = viewportHeight - buttonRect.bottom - margin;
                var spaceAbove = buttonRect.top - margin;
                var top;

                if (spaceBelow >= menuHeight + margin) {
                    top = buttonRect.bottom + margin;
                } else if (spaceAbove >= menuHeight + margin) {
                    top = buttonRect.top - menuHeight - margin;
                } else if (spaceBelow >= spaceAbove) {
                    top = Math.max(margin, viewportHeight - menuHeight - margin);
                } else {
                    top = margin;
                }

                if (top + menuHeight > viewportHeight - margin) {
                    top = Math.max(margin, viewportHeight - menuHeight - margin);
                }
                if (top < margin) {
                    top = margin;
                }

                menu.style.left = left + 'px';
                menu.style.top = top + 'px';
                menu.style.maxHeight = (viewportHeight - margin * 2) + 'px';
            });
        });
        document.querySelectorAll('.mrs-kebab-menu').forEach(function (menu) {
            menu.addEventListener('click', function (event) {
                event.stopPropagation();
            });
        });
        document.addEventListener('click', function () {
            closeAllKebabMenus();
            document.querySelectorAll('.mrs-cal-popup').forEach(function (p) { p.style.display = 'none'; });
        });
        document.addEventListener('keydown', function (ev) {
            if (ev.key === 'Escape') {
                document.querySelectorAll('.mrs-cal-popup').forEach(function (p) { p.style.display = 'none'; });
            }
        });

        document.querySelectorAll('.mrs-edit-room').forEach(function (button) {
            button.addEventListener('click', function () {
                closeAllKebabMenus();
                openFormModal({
                    title: 'Edit Room',
                    html:
                        '<form method="POST">' +
                            csrfFieldHtml() + '<input type="hidden" name="room_id" value="' + escapeHtml(button.dataset.id || '') + '">' +
                            '<label>Room Name</label>' +
                            '<input type="text" name="room_name" value="' + escapeHtml(button.dataset.name || '') + '" required>' +
                            '<label>Capacity (number of people)</label>' +
                            '<input type="number" name="capacity" value="' + escapeHtml(button.dataset.capacity || '') + '" required>' +
                            '<label>Location</label>' +
                            '<input type="text" name="location" value="' + escapeHtml(button.dataset.location || '') + '" required>' +
                            '<div class="mrs-modal-actions">' +
                                '<button type="button" class="btn btn-secondary" data-modal-cancel>Cancel</button>' +
                                '<button type="submit" name="edit_room" class="btn">Save Changes</button>' +
                            '</div>' +
                        '</form>'
                });
            });
        });

        function postponeTimeField(label, name) {
            var hours = '<option value="">HH</option>';
            for (var hour = 0; hour < 24; hour++) {
                var formattedHour = String(hour).padStart(2, '0');
                hours += '<option value="' + formattedHour + '">' + formattedHour + '</option>';
            }
            var minutes = '<option value="">MM</option>';
            ['00', '15', '30', '45'].forEach(function (minute) {
                minutes += '<option value="' + minute + '">' + minute + '</option>';
            });

            return '<label>' + label + ' (optional)</label>' +
                '<div style="display:flex; gap:8px; align-items:center; margin-bottom:15px;">' +
                    '<select name="' + name + '_hour" aria-label="' + label + ' hour" style="margin-bottom:0;">' + hours + '</select>' +
                    '<span>:</span>' +
                    '<select name="' + name + '_minute" aria-label="' + label + ' minutes" style="margin-bottom:0;">' + minutes + '</select>' +
                    '<span style="font-size:12px; color:var(--text-muted);">(24hr)</span>' +
                    '<input type="hidden" name="' + name + '">' +
                '</div>';
        }

        document.querySelectorAll('.mrs-postpone-booking').forEach(function (button) {
            button.addEventListener('click', function () {
                closeAllKebabMenus();
                var isSeries = button.dataset.series === '1';
                var scopeHtml = '';
                if (isSeries) {
                    scopeHtml =
                        '<label>What do you want to postpone?</label>' +
                        '<div class="postpone-scope-row">' +
                            '<label class="postpone-scope-option"><input type="radio" name="postpone_scope" value="day" checked> This day only</label>' +
                            '<label class="postpone-scope-option"><input type="radio" name="postpone_scope" value="series"> Entire continuous series (times only, dates stay)</label>' +
                        '</div>';
                }
                openFormModal({
                    title: isSeries ? 'Postpone Continuous Meeting' : 'Postpone Booking',
                    html:
                        '<form method="POST">' +
                            '<input type="hidden" name="booking_id" value="' + escapeHtml(button.dataset.id || '') + '">' +
                            csrfFieldHtml() + '<input type="hidden" name="series_id" value="' + escapeHtml(button.dataset.seriesId || '') + '">' +
                            scopeHtml +
                            postponeTimeField('New Start Time', 'postponed_start_time') +
                            postponeTimeField('New End Time', 'postponed_end_time') +
                            '<div class="postpone-date-wrap"' + (isSeries ? ' style="display:none;"' : '') + '>' +
                                '<label>New Date (optional)</label>' +
                                '<input type="date" name="postponed_date">' +
                            '</div>' +
                            '<label>Reason for Postponing</label>' +
                            '<input type="text" name="postponed_reason">' +
                            '<div class="mrs-modal-actions">' +
                                '<button type="button" class="btn btn-secondary" data-modal-cancel>Cancel</button>' +
                                '<button type="submit" name="postpone_booking" class="btn">Confirm Postpone</button>' +
                            '</div>' +
                        '</form>',
                    onOpen: function (overlay) {
                        var form = overlay.querySelector('form');
                        form.addEventListener('submit', function (event) {
                            var valid = true;
                            ['postponed_start_time', 'postponed_end_time'].forEach(function (name) {
                                var hour = form.querySelector('select[name="' + name + '_hour"]');
                                var minute = form.querySelector('select[name="' + name + '_minute"]');
                                var hidden = form.querySelector('input[type="hidden"][name="' + name + '"]');
                                hour.setCustomValidity('');
                                minute.setCustomValidity('');
                                if (!hour.value && !minute.value) {
                                    hidden.value = '';
                                } else if (!hour.value || !minute.value) {
                                    var incomplete = hour.value ? minute : hour;
                                    incomplete.setCustomValidity('Choose both the hour and minutes.');
                                    incomplete.reportValidity();
                                    valid = false;
                                } else {
                                    hidden.value = hour.value + ':' + minute.value;
                                }
                            });
                            if (!valid) event.preventDefault();
                        });

                        if (!isSeries) return;
                        var dateWrap = overlay.querySelector('.postpone-date-wrap');
                        var dateInput = overlay.querySelector('input[name="postponed_date"]');
                        overlay.querySelectorAll('input[name="postpone_scope"]').forEach(function (radio) {
                            radio.addEventListener('change', function () {
                                var show = radio.value === 'day' && radio.checked;
                                if (dateWrap) dateWrap.style.display = show ? '' : 'none';
                                if (dateInput && !show) {
                                    dateInput.value = '';
                                    if (dateInput._mrsCal) dateInput._mrsCal.refresh();
                                }
                            });
                        });
                    }
                });
            });
        });

        document.querySelectorAll('.mrs-report-issue').forEach(function (button) {
            button.addEventListener('click', function () {
                closeAllKebabMenus();
                openFormModal({
                    title: 'Report Room Issue',
                    html:
                        '<form method="POST">' +
                            '<input type="hidden" name="booking_id" value="' + escapeHtml(button.dataset.id || '') + '">' +
                            '<label>Describe the problem with this room</label>' +
                            csrfFieldHtml() + '<textarea name="issue_message" rows="3" required></textarea>' +
                            '<div class="mrs-modal-actions">' +
                                '<button type="button" class="btn btn-secondary" data-modal-cancel>Cancel</button>' +
                                '<button type="submit" name="report_issue" class="btn">Send to Admin</button>' +
                            '</div>' +
                        '</form>'
                });
            });
        });

        document.querySelectorAll('.mrs-book-room').forEach(function (button) {
            button.addEventListener('click', function () {
                closeAllKebabMenus();
                /* PER-ROOM PERMISSION: admin akakataa chumba hiki kwa role hii
                   → onyo "ACCESS DENIED TO BOOK THIS ROOM" + OK (hakuna form). */
                if (button.dataset.allowed === '0') {
                    openModal({
                        mode: 'error',
                        danger: true,
                        title: 'Access denied',
                        message: 'ACCESS DENIED TO BOOK THIS ROOM',
                        confirmText: 'OK'
                    });
                    return;
                }
                var template = document.getElementById(button.dataset.template || '');
                if (!template) return;
                var roomName = (button.dataset.roomName || button.getAttribute('data-room-name') || '').trim();
                var title = roomName ? roomName.toUpperCase() : 'Book This Room';
                openFormModal({
                    title: title,
                    html: template.innerHTML,
                    onOpen: function (overlay) {
                        setupProjectorPicker(overlay);
                        setupBookingType(overlay);
                    }
                });
            });
        });

        document.querySelectorAll('.mrs-edit-projector').forEach(function (button) {
            button.addEventListener('click', function () {
                closeAllKebabMenus();
                openFormModal({
                    title: 'Edit Projector',
                    html:
                        '<form method="POST">' +
                            csrfFieldHtml() + '<input type="hidden" name="projector_id" value="' + escapeHtml(button.dataset.id || '') + '">' +
                            '<label>Projector Name</label>' +
                            '<input type="text" name="name" value="' + escapeHtml(button.dataset.name || '') + '" required>' +
                            '<label>Model (optional)</label>' +
                            '<input type="text" name="model" value="' + escapeHtml(button.dataset.model || '') + '">' +
                            '<label>Location / Store (optional)</label>' +
                            '<input type="text" name="location" value="' + escapeHtml(button.dataset.location || '') + '">' +
                            '<div class="mrs-modal-actions">' +
                                '<button type="button" class="btn btn-secondary" data-modal-cancel>Cancel</button>' +
                                '<button type="submit" name="edit_projector" class="btn">Save Changes</button>' +
                            '</div>' +
                        '</form>'
                });
            });
        });

        function setupProjectorPicker(overlay) {
            var form = overlay.querySelector('form');
            if (!form) return;

            var picker = form.querySelector('#projector-picker') || form.querySelector('.projector-picker');
            var select = form.querySelector('#projector-select') || form.querySelector('.projector-select');
            var hint = form.querySelector('#projector-hint') || form.querySelector('.projector-hint');
            if (!picker || !select) return;

            var dateInput = form.querySelector('input[name="booking_date"]');
            var startHour = form.querySelector('select[name="start_time_hour"]');
            var startMin = form.querySelector('select[name="start_time_minute"]');
            var endHour = form.querySelector('select[name="end_time_hour"]');
            var endMin = form.querySelector('select[name="end_time_minute"]');
            var radios = form.querySelectorAll('input[name="Accessories"]');

            function getVal(el) {
                return el ? el.value : '';
            }

            function buildStart() {
                var h = getVal(startHour);
                var m = getVal(startMin);
                return (h && m) ? (h + ':' + m) : '';
            }

            function buildEnd() {
                var h = getVal(endHour);
                var m = getVal(endMin);
                return (h && m) ? (h + ':' + m) : '';
            }

            function isProjectorSelected() {
                var checked = form.querySelector('input[name="Accessories"]:checked');
                return checked && checked.value === 'projector';
            }

            function togglePicker() {
                if (isProjectorSelected()) {
                    picker.style.display = 'block';
                    select.setAttribute('required', 'required');
                    loadAvailability();
                } else {
                    picker.style.display = 'none';
                    select.removeAttribute('required');
                    select.value = '';
                    if (hint) {
                        hint.textContent = '';
                        hint.classList.remove('is-ok', 'is-warn');
                    }
                }
            }

            function loadAvailability() {
                var date = getVal(dateInput);
                var start = buildStart();
                var end = buildEnd();

                if (!date || !start || !end || start >= end) {
                    select.innerHTML = '<option value="">Choose date &amp; time first...</option>';
                    if (hint) hint.textContent = (start && end && start >= end) ? 'Start time must be before end time.' : '';
                    return;
                }

                if (hint) hint.textContent = 'Checking availability...';
                select.innerHTML = '<option value="">Loading projectors...</option>';

                var params = new URLSearchParams({ date: date, start: start, end: end });
                fetch('../includes/projector_availability.php?' + params.toString())
                    .then(function (res) { return res.json(); })
                    .then(function (data) {
                        if (!data.ok || !data.projectors) {
                            select.innerHTML = '<option value="">Failed to load projectors</option>';
                            if (hint) hint.textContent = 'Could not load projectors. Please try again.';
                            return;
                        }

                        if (!data.projectors.length) {
                            select.innerHTML = '<option value="">No active projectors available</option>';
                            if (hint) hint.textContent = 'No active projectors in inventory.';
                            return;
                        }

                        var html = '<option value="">Select a projector</option>';
                        data.projectors.forEach(function (p) {
                            var label = p.name + (p.model ? ' (' + p.model + ')' : '');
                            if (p.available) {
                                html += '<option value="' + escapeHtml(String(p.id)) + '">' + escapeHtml(label) + ' — Available</option>';
                            } else {
                                var busyLabel = label + ' — In Use';
                                html += '<option value="" disabled>' + escapeHtml(busyLabel) + '</option>';
                            }
                        });
                        select.innerHTML = html;

                        if (hint) {
                            var freeCount = data.projectors.filter(function (p) { return p.available; }).length;
                            var busyCount = data.projectors.length - freeCount;
                            hint.classList.remove('is-ok', 'is-warn');
                            hint.style.background = '';
                            hint.style.borderColor = '';
                            hint.style.color = '';
                            if (freeCount === 0) {
                                hint.textContent = 'All projectors are already taken for this time slot.';
                                hint.classList.add('is-warn');
                                hint.style.background = '#fee4e2';
                                hint.style.borderColor = '#f3b4ae';
                                hint.style.color = '#a8221a';
                            } else {
                                hint.textContent = freeCount + ' available   ·   ' + busyCount + ' already in use for this time';
                                hint.classList.add('is-ok');
                                hint.style.background = '#f3f4f6';
                                hint.style.borderColor = '#e5e7eb';
                                hint.style.color = '#374151';
                            }
                        }

                        // Show details for a busy projector when user somehow focuses it
                        select.onchange = function () {
                            var chosen = data.projectors.find(function (p) { return String(p.id) === select.value; });
                            if (chosen && !chosen.available && hint) {
                                var b = chosen.busy || {};
                                hint.classList.remove('is-ok', 'is-warn');
                                hint.textContent = 'Already taken: ' + (b.meeting || '') + ', ' + (b.start || '') + '-' + (b.end || '') + ' (' + (b.user || '') + ')';
                                hint.classList.add('is-warn');
                                select.value = '';
                            }
                        };
                    })
                    .catch(function () {
                        select.innerHTML = '<option value="">Failed to load projectors</option>';
                        if (hint) hint.textContent = 'Network error while checking projectors.';
                    });
            }

            radios.forEach(function (radio) {
                radio.addEventListener('change', togglePicker);
            });

            [dateInput, startHour, startMin, endHour, endMin].forEach(function (el) {
                if (el) el.addEventListener('change', function () {
                    if (isProjectorSelected()) loadAvailability();
                });
            });

            togglePicker();
        }

        function setupBookingType(overlay) {
            var form = overlay.querySelector('form');
            if (!form) return;

            var typeRadios = form.querySelectorAll('input[name="booking_type"]');
            var continuousBox = form.querySelector('.continuous-options');
            var endDate = form.querySelector('input[name="booking_end_date"]');
            var startDate = form.querySelector('input[name="booking_date"]');
            var dateLabel = form.querySelector('#booking-date-label');
            var dateGroup = form.querySelector('#date-field-group');
            var startSlot = form.querySelector('#continuous-start-slot');
            var preview = form.querySelector('#series-preview');
            var weekdayRadios = form.querySelectorAll('input[name="meeting_weekday"]');
            if (!typeRadios.length || !continuousBox) return;

            var dateGroupParent = dateGroup ? dateGroup.parentNode : null;
            var dateGroupNext = dateGroup ? dateGroup.nextSibling : null;

            function dayNames() {
                return ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
            }

            function selectedWeekday() {
                /* Continuous pekee ndio unahitaji weekday; kwenye single
                   (hata kama radio zimebaki checked) → null. */
                var t = form.querySelector('input[name="booking_type"]:checked');
                if (t && t.value !== 'continuous') return null;
                var r = form.querySelector('input[name="meeting_weekday"]:checked');
                return r ? parseInt(r.value, 10) : null;
            }

            function isSingleMode() {
                var t = form.querySelector('input[name="booking_type"]:checked');
                return !t || t.value !== 'continuous';
            }

            function sessionDates(start, end, weekday) {
                var out = [];
                if (!start || !end || end < start || weekday === null) return out;
                var d = new Date(start + 'T00:00:00');
                var e = new Date(end + 'T00:00:00');
                var guard = 0;
                while (d <= e && guard < 60) {
                    if (d.getDay() === weekday) {
                        out.push(new Date(d.getTime()));
                    }
                    d.setDate(d.getDate() + 1);
                    guard++;
                }
                return out;
            }

            function formatDate(dt) {
                var days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
                var dd = String(dt.getDate()).padStart(2, '0');
                var mm = String(dt.getMonth() + 1).padStart(2, '0');
                return days[dt.getDay()] + ' ' + dd + '/' + mm;
            }

            function updatePreview() {
                if (!preview) return;
                var type = form.querySelector('input[name="booking_type"]:checked');
                if (!type || type.value !== 'continuous') {
                    preview.textContent = '';
                    return;
                }
                var wd = selectedWeekday();
                var names = dayNames();
                if (wd === null) {
                    preview.textContent = 'Choose the meeting day (Monday–Friday) first...';
                    return;
                }
                var s = startDate ? startDate.value : '';
                var e = endDate ? endDate.value : '';
                if (!s || !e) {
                    preview.textContent = 'Choose start and end dates to see ' + names[wd] + ' sessions...';
                    return;
                }
                if (e < s) {
                    preview.textContent = 'End date must be on or after the start date.';
                    return;
                }
                var startDt = new Date(s + 'T00:00:00');
                if (startDt.getDay() !== wd) {
                    preview.textContent = 'Start date must be a ' + names[wd] + '.';
                    return;
                }
                var span = Math.floor((new Date(e + 'T00:00:00') - startDt) / 86400000) + 1;
                if (span > 30) {
                    preview.textContent = 'Range cannot exceed 30 days.';
                    return;
                }
                var dates = sessionDates(s, e, wd);
                if (dates.length < 2) {
                    preview.textContent = 'Range must include at least 2 ' + names[wd] + 's — extend the end date.';
                    return;
                }
                preview.textContent = dates.length + ' ' + names[wd] + ' sessions: ' + dates.map(formatDate).join(', ');
            }

            var endLabel = form.querySelector('#booking-end-label');

            var startCal = createMrsCalendar(startDate, {
                getWeekday: selectedWeekday,
                isAnyDay: isSingleMode,
                getMin: function () { return startDate ? (startDate.getAttribute('min') || '') : ''; },
                hintEl: dateLabel,
                hintPrefix: 'From Date',
                onPick: function () { updatePreview(); if (endCal) endCal.refresh(); }
            });
            var endCal = createMrsCalendar(endDate, {
                /* TO DATE: kalenda ya kawaida — kila siku (kuanzia tarehe
                   ya From Date) inachaguliwa; hakuna kizuizi cha weekday.
                   Tarehe za nyuma/ kabla ya From Date bado zimezuiwa na
                   getMin (hiyo ndiyo "hakuna tarehe za nyuma").
                   FROM DATE (startCal hapo juu) hairuhuswi kubadilishwa. */
                isAnyDay: true,
                getMin: function () { return (startDate && startDate.value) || (startDate && startDate.getAttribute('min')) || ''; },
                hintEl: endLabel,
                hintPrefix: 'To Date',
                onPick: updatePreview
            });

            function updateCalLabels() {
                var wd = selectedWeekday();
                var names = dayNames();
                if (wd !== null) {
                    if (dateLabel) dateLabel.textContent = 'From Date (must be a ' + names[wd] + ')';
                    /* TO DATE: jina tu — mtumiaji anachagua siku yeyote
                       (muda/ idadi ya sessions huonekana kwenye preview). */
                    if (endLabel) endLabel.textContent = 'To Date';
                } else {
                    if (dateLabel) dateLabel.textContent = 'From Date';
                    if (endLabel) endLabel.textContent = 'To Date (max 30 days)';
                }
            }

            function clearMismatchDates() {
                var wd = selectedWeekday();
                if (wd === null || !startDate || !endDate) return;
                try {
                    /* FROM DATE pekee ndiyo huhitaji weekday halali. TO DATE
                       imeachwa huru (mtumiaji anachagua siku yeyote). */
                    if (startDate.value) {
                        var sd = new Date(startDate.value + 'T00:00:00');
                        if (sd.getDay() !== wd) {
                            startDate.value = '';
                            if (startCal) startCal.refresh();
                        }
                    }
                } catch (e) {}
            }

            function setContinuousMode(on) {
                /* Kalenda: Date (start) = DAIMA inaonekana (single na
                   continuous); To Date (end) = continuous pekee.
                   Dropdown za DD/MM/YYYY zimeondolewa kwenye mfumo wote. */
                if (startCal) startCal.wrap.style.display = 'block';
                if (endCal) endCal.wrap.style.display = on ? 'block' : 'none';
                if (endDate) endDate.removeAttribute('required');
            }

            function toggleType() {
                var type = form.querySelector('input[name="booking_type"]:checked');
                var isContinuous = type && type.value === 'continuous';
                continuousBox.style.display = isContinuous ? 'block' : 'none';

                if (dateGroup && startSlot && dateGroupParent) {
                    if (isContinuous) {
                        startSlot.appendChild(dateGroup);
                    } else {
                        dateGroupParent.insertBefore(dateGroup, dateGroupNext);
                        if (dateLabel) dateLabel.textContent = 'Date';
                    }
                }

                setContinuousMode(isContinuous);
                if (isContinuous) {
                    updateCalLabels();
                }
                if (startCal) startCal.refresh();
                if (endCal) endCal.refresh();

                if (endDate) {
                    if (!isContinuous) {
                        endDate.value = '';
                        if (endCal) endCal.refresh();
                    }
                }
                updatePreview();
            }

            /* Tarehe ziko kwenye input iliyofichwa (kalenda), kwa hiyo HTML5
               required haifanyi kazi — thibitisha hapa ili mtumiaji apate
               feedback badala ya ukimya (single na continuous). */
            if (!form.dataset.mrsContGuard) {
                form.dataset.mrsContGuard = '1';
                form.addEventListener('submit', function (ev) {
                    var t = form.querySelector('input[name="booking_type"]:checked');
                    if (t && t.value === 'continuous') {
                        if ((startDate && !startDate.value) || (endDate && !endDate.value)) {
                            ev.preventDefault();
                            openModal({
                                mode: 'error',
                                title: 'Missing dates',
                                message: 'Please choose both From Date and To Date for the continuous meeting.',
                                danger: true
                            });
                        }
                    } else if (startDate && !startDate.value) {
                        ev.preventDefault();
                        openModal({
                            mode: 'error',
                            title: 'Missing date',
                            message: 'Please choose a date — today or any future date.',
                            danger: true
                        });
                    }
                });
            }

            typeRadios.forEach(function (radio) {
                radio.addEventListener('change', toggleType);
            });
            weekdayRadios.forEach(function (radio) {
                radio.addEventListener('change', function () {
                    clearMismatchDates();
                    updateCalLabels();
                    if (startCal) startCal.refresh();
                    if (endCal) endCal.refresh();
                    updatePreview();
                });
            });
            if (startDate) startDate.addEventListener('change', function () {
                if (endDate && endDate.value && startDate.value && endDate.value < startDate.value) {
                    endDate.value = '';
                }
                if (endCal) endCal.refresh();
                updatePreview();
            });
            if (endDate) endDate.addEventListener('change', updatePreview);

            toggleType();
        }
    });

    window.mrsModal = openModal;
    window.mrsFormModal = openFormModal;
})();
