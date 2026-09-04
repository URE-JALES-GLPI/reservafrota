/* global document, fetch, window, confirm */

/**
 * Reservafrota — calendário mensal (estilo "calendário de mesa").
 * Lê o array de agendamentos de ajax/month.php e desenha a grade do mês.
 * Só age quando #reservafrota-calendar existe.
 */
(function () {
    'use strict';

    var WEEK = ['Domingo', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado'];
    var WEEK_SHORT = ['DOM', 'SEG', 'TER', 'QUA', 'QUI', 'SEX', 'SÁB'];
    var MONTHS = ['Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho',
                  'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];

    function pad(n) { return n < 10 ? '0' + n : '' + n; }

    var CAR_PALETTE = [
        '#4263eb', '#0ca678', '#e8590c', '#7048e8', '#1098ad', '#d6336c',
        '#f59f00', '#37b24d', '#1c7ed6', '#ae3ec9', '#f76707', '#0c8599',
        '#2b8a3e', '#c2255c', '#5f3dc4', '#0b7285'
    ];
    function carColor(b) {
        var key = b && b.car_id ? b.car_id : 0;
        if (!key && b && b.car) {
            for (var i = 0; i < b.car.length; i++) { key += b.car.charCodeAt(i); }
        }
        return CAR_PALETTE[Math.abs(key) % CAR_PALETTE.length];
    }
    function carTint(hex, a) {
        var n = parseInt(hex.slice(1), 16);
        return 'rgba(' + ((n >> 16) & 255) + ',' + ((n >> 8) & 255) + ',' + (n & 255) + ',' + a + ')';
    }
    function todayStr() {
        var t = new Date();
        return t.getFullYear() + '-' + pad(t.getMonth() + 1) + '-' + pad(t.getDate());
    }

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function timeOf(dt) {
        // "2026-06-10 08:30:00" -> "08:30"
        if (!dt) { return ''; }
        var s = String(dt);
        return s.length >= 16 ? s.substr(11, 5) : s;
    }

    function init() {
        var root = document.getElementById('reservafrota-calendar');
        if (!root) { return; }

        var ajaxMonth = root.dataset.ajaxMonth;
        var bform = root.dataset.bform;
        var blistUrl = root.dataset.blistUrl || '';
        var agendaUrl = root.dataset.aurl || '';
        var csrf = root.dataset.csrf || '';
        var canDelete = root.dataset.candelete === '1';
        var canCreate = root.dataset.cancreate === '1';
        var canApprove = root.dataset.canapprove === '1';

        var grid = document.getElementById('reservafrota-grid');
        var newBtn = document.getElementById('reservafrota-new-booking-btn');
        var carSelect = document.getElementById('cb-m-car');
        var carHint = document.getElementById('cb-m-car-availability');
        var availabilityUrl = root.dataset.availability || (root.dataset.ajaxMonth ? root.dataset.ajaxMonth.replace('month.php','availability.php') : '');
        var latestByDay = {};
        var titleEl = document.getElementById('reservafrota-cal-title');
        var modal = document.getElementById('reservafrota-day-modal');
        var modalTitle = document.getElementById('reservafrota-modal-title');
        var modalExisting = document.getElementById('reservafrota-modal-existing');
        var modalDate = document.getElementById('reservafrota-modal-date');
        var modalDep = document.getElementById('cb-m-dep');
        var modalArr = document.getElementById('cb-m-arr');
        var modalMonth = document.getElementById('reservafrota-modal-month');
        var mDate = document.getElementById('cb-m-date');
        var mTime = document.getElementById('cb-m-time');
        var mADate = document.getElementById('cb-m-adate');
        var mATime = document.getElementById('cb-m-atime');
        var modalForm = document.getElementById('reservafrota-modal-form');
        var modalWeekdays = document.getElementById('cb-m-weekdays');
        var submitBtn = document.getElementById('cb-m-submit');
        var cancelBtn = document.getElementById('cb-m-cancel');
        var arriveBtn = document.getElementById('cb-m-arrive');
        var confirmBtn = document.getElementById('cb-m-confirm');
        var editingStatus = null;
        var editingId = null; // status do agendamento em edição (null = criando um novo)

        // Tooltip flutuante (criado uma vez).
        var tip = document.createElement('div');
        tip.className = 'reservafrota-tip';
        tip.hidden = true;
        document.body.appendChild(tip);

        // mês corrente do componente (Date no dia 1)
        var parts = String(root.dataset.month || '').split('-');
        var cur = new Date(
            parseInt(parts[0], 10) || new Date().getFullYear(),
            (parseInt(parts[1], 10) || (new Date().getMonth() + 1)) - 1,
            1
        );

        function currentYm() {
            return cur.getFullYear() + '-' + pad(cur.getMonth() + 1);
        }

        // agrupa a lista de agendamentos por dia do mês -> { 10: [b, b], ... }
        function groupByDay(list) {
            var map = {};
            if (!Array.isArray(list)) { return map; }
            list.forEach(function (b) {
                if (!b) { return; }
                var d = b.day || (b.date ? parseInt(String(b.date).substr(8, 2), 10) : 0);
                if (!d) { return; }
                if (!map[d]) { map[d] = []; }
                map[d].push(b);
            });
            return map;
        }

        function load() {
            var ym = currentYm();
            titleEl.textContent = MONTHS[cur.getMonth()] + ' ' + cur.getFullYear();
            grid.innerHTML = '<div class="reservafrota-grid-loading"><span class="reservafrota-spinner"></span></div>';
            closeModal();
            if (typeof hideTip === 'function') { hideTip(); }

            fetch(ajaxMonth + '?month=' + encodeURIComponent(ym), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin'
            })
                .then(function (r) {
                    if (!r.ok) { throw new Error('HTTP ' + r.status); }
                    return r.json();
                })
                .then(function (data) {
                    // aceita tanto array puro quanto {bookings:[...]}
                    var list = Array.isArray(data) ? data : (data && data.bookings) || [];
                    render(groupByDay(list));
                })
                .catch(function (err) {
                    // mantém o erro real visível no console para depuração
                    if (window.console) { window.console.error('[reservafrota] calendário:', err); }
                    grid.innerHTML = '<div class="reservafrota-grid-loading">'
                        + 'Não foi possível carregar o calendário. Recarregue a página.</div>';
                });
        }

        function render(byDay) {
            latestByDay = byDay || {};
            var year = cur.getFullYear();
            var month = cur.getMonth();
            var firstWeekday = new Date(year, month, 1).getDay();   // 0=Dom
            var daysInMonth = new Date(year, month + 1, 0).getDate();
            var todayStr = (function () {
                var t = new Date();
                return t.getFullYear() + '-' + pad(t.getMonth() + 1) + '-' + pad(t.getDate());
            }());

            var html = '<div class="reservafrota-grid-head">';
            for (var w = 0; w < 7; w++) {
                html += '<div class="reservafrota-grid-wd">' + WEEK_SHORT[w] + '</div>';
            }
            html += '</div><div class="reservafrota-grid-body">';

            // Dias do mês anterior (preenche grade para mostrar todos os dias)
            var prevDays = new Date(year, month, 0).getDate();
            for (var e = 0; e < firstWeekday; e++) {
                var pd = prevDays - firstWeekday + 1 + e;
                html += '<div class="reservafrota-cell is-empty is-other-month"><span class="reservafrota-cell__num is-other">' + pd + '</span></div>';
            }

            var todayCompare = todayStr();
            for (var d = 1; d <= daysInMonth; d++) {
                var items = byDay[d] || [];
                var dateStr = year + '-' + pad(month + 1) + '-' + pad(d);
                var isToday = dateStr === todayCompare;
                var isPast = !canApprove && dateStr < todayCompare;

                var chips = '';
                if (isPast) {
                    chips = '<span class="reservafrota-evt s-past" title="Data já passou"><i class="ti ti-ban" style="font-size:0.7rem;"></i> Indisponível</span>';
                } else if (items.length === 0) {
                    chips = '<span class="reservafrota-evt s-empty" title="Clique para reservar"><i class="ti ti-plus" style="font-size:0.7rem;"></i> Disponível</span>';
                } else {
                    items.slice(0, 4).forEach(function (b) {
                        chips += '<span class="reservafrota-evt s-' + (b.status || 1) + (b.conflict ? ' is-conflict' : '') + '"'
                            + ' title="' + esc(b.car) + '">'
                            + '<b>' + esc(timeOf(b.departure)) + '</b> '
                            + esc(b.car) + ' — ' + esc(b.user)
                            + '</span>';
                    });
                    if (items.length > 4) {
                        chips += '<span class="reservafrota-evt-more">+' + (items.length - 4) + ' '
                            + (items.length - 4 === 1 ? 'outro' : 'outros') + '</span>';
                    }
                }

                var hasAllArrived = items.length > 0 && items.every(function (b) { return (b.status || 1) === 5; });
                var hasSomeArrived = !hasAllArrived && items.some(function (b) { return (b.status || 1) === 5; });
                // Para o gestor: destaca em laranja o dia com conflito de agendamento.
                var hasConflict = canApprove && items.some(function (b) { return b.conflict; });
                html += '<div class="reservafrota-cell' + (items.length ? ' has-items' : '')
                    + (isToday ? ' is-today' : '')
                    + (isPast ? ' is-past' : '')
                    + (hasAllArrived ? ' is-all-arrived' : (hasSomeArrived ? ' is-some-arrived' : ''))
                    + (hasConflict ? ' is-conflict-day' : '')
                    + '" data-day="' + d + '"' + (isPast ? ' title="Data passada - indisponível"' : '') + '>'
                    + '<span class="reservafrota-cell__num">' + d + '</span>'
                    + '<div class="reservafrota-cell__evts">' + chips + '</div>'
                    + '</div>';
            }

            // Completa última linha com dias do próximo mês
            var totalCells = firstWeekday + daysInMonth;
            var tail = (7 - (totalCells % 7)) % 7;
            for (var t = 1; t <= tail; t++) {
                html += '<div class="reservafrota-cell is-empty is-other-month"><span class="reservafrota-cell__num is-other">' + t + '</span></div>';
            }

            html += '</div>';
            grid.innerHTML = html;

            grid.querySelectorAll('.reservafrota-cell[data-day]').forEach(function (cell) {
                if (cell.classList.contains('is-past')) { return; }
                var dayItems = byDay[cell.dataset.day] || [];
                cell.addEventListener('click', function () {
                    openDay(parseInt(cell.dataset.day, 10), dayItems);
                });
                if (dayItems.length) {
                    cell.addEventListener('mouseenter', function () {
                        showTip(parseInt(cell.dataset.day, 10), dayItems);
                    });
                    cell.addEventListener('mousemove', moveTip);
                    cell.addEventListener('mouseleave', hideTip);
                }
            });
        }

        // ----- Tooltip (passar o mouse mostra os agendamentos do dia) -----
        function tipItem(b) {
            var period = b.arrival
                ? timeOf(b.departure) + ' → ' + timeOf(b.arrival)
                : timeOf(b.departure);
            return '<div class="reservafrota-tip__item s-' + (b.status || 1) + '">'
                + '<div class="reservafrota-tip__line"><b>' + esc(period) + '</b> · ' + esc(b.car) + '</div>'
                + '<div class="reservafrota-tip__sub"><i class="ti ti-user"></i> ' + esc(b.user)
                + ' · <i class="ti ti-steering-wheel"></i> ' + esc(b.driver || '—')
                + ' · ' + esc(b.status_label) + '</div>'
                + (b.destination ? '<div class="reservafrota-tip__sub"><i class="ti ti-map-pin"></i> ' + esc(b.destination) + '</div>' : '')
                + '</div>';
        }

        function showTip(day, items) {
            if (!tip) { return; }
            tip.innerHTML = '<div class="reservafrota-tip__head">' + pad(day) + '/' + pad(cur.getMonth() + 1)
                + ' · ' + items.length + (items.length === 1 ? ' agendamento' : ' agendamentos') + '</div>'
                + items.map(tipItem).join('');
            tip.hidden = false;
        }

        function moveTip(e) {
            if (!tip || tip.hidden) { return; }
            var pad2 = 14;
            var w = tip.offsetWidth, h = tip.offsetHeight;
            var x = e.clientX + pad2;
            var y = e.clientY + pad2;
            if (x + w > window.innerWidth - 8) { x = e.clientX - w - pad2; }
            if (y + h > window.innerHeight - 8) { y = e.clientY - h - pad2; }
            tip.style.left = Math.max(8, x) + 'px';
            tip.style.top = Math.max(8, y) + 'px';
        }

        function hideTip() {
            if (tip) { tip.hidden = true; }
        }

        function openDay(day, items) {
            if (!modal) { return; }
            hideTip();
            var weekday = WEEK[new Date(cur.getFullYear(), cur.getMonth(), day).getDay()];
            var dateStr = cur.getFullYear() + '-' + pad(cur.getMonth() + 1) + '-' + pad(day);

            modalTitle.textContent = pad(day) + '/' + pad(cur.getMonth() + 1) + '/' + cur.getFullYear()
                + ' · ' + weekday;

            // Lista (somente leitura) dos agendamentos já existentes no dia.
            var listHtml = items.map(function (b) {
                var period = b.arrival
                    ? timeOf(b.departure) + ' → ' + timeOf(b.arrival)
                    : 'Saída ' + timeOf(b.departure);
                var cancelBtn = b.can_cancel
                    ? '<button type="button" class="reservafrota-btn-cancel" data-cb-cancel'
                        + ' data-id="' + b.id + '" data-bform="' + bform + '" data-csrf="' + esc(csrf) + '">'
                        + '<i class="ti ti-ban"></i> Cancelar</button>'
                    : '';
                var uploadSheetBtn = '';
                if (!b.has_sheet && (b.status === 5 || b.returned_at)) {
                    uploadSheetBtn = '<button type="button" class="reservafrota-btn-sheet" data-cb-attach'
                        + ' data-id="' + b.id + '" data-bform="' + bform + '" data-csrf="' + esc(csrf) + '"'
                        + ' title="Adicionar folha de agendamento">'
                        + '<i class="ti ti-paperclip"></i> Folha</button>';
                }
                var sheetBtn = (b.has_sheet && bform)
                    ? '<a class="reservafrota-open" href="' + bform.replace('booking.form.php', 'sheet.php') + '?id=' + b.id + '"><i class="ti ti-download"></i> Baixar folha</a>'
                    : '';
                var openUrl = bform ? (bform + '?id=' + b.id) : '';
                var hasObs = (b.status === 5 && b.obs);
                return '<div class="reservafrota-day-item s-' + (b.status || 1) + (b.conflict ? ' is-conflict' : '') + (hasObs ? ' has-obs' : '') + '"'
                    + (openUrl ? ' data-open="' + openUrl + '" style="cursor:pointer;"' : '')
                    + '>'
                    + '<div class="reservafrota-day-item__body">'
                    + '<div class="reservafrota-day-item__top">'
                    + '<span class="reservafrota-chip status-' + (b.conflict ? 'conflict' : statusName(b.status)) + '">'
                    + (b.conflict ? 'Conflito' : esc(b.status_label)) + '</span>'
                    + (hasObs ? '<span class="reservafrota-obsdot" title="Tem observação"></span> ' : '')
                    + '<strong>' + esc(b.car) + '</strong></div>'
                    + '<div class="reservafrota-day-item__meta"><i class="ti ti-user"></i> ' + esc(b.user)
                    + ' &nbsp;·&nbsp; <i class="ti ti-steering-wheel"></i> ' + esc(b.driver || '—') + '</div>'
                    + '<div class="reservafrota-day-item__meta"><i class="ti ti-clock"></i> ' + esc(period)
                    + (b.destination ? ' &nbsp;·&nbsp; <i class="ti ti-map-pin"></i> ' + esc(b.destination) : '')
                    + '</div>'
                    + (b.status === 4 && b.note ? '<div class="reservafrota-day-item__reason"><i class="ti ti-info-circle"></i> Motivo: ' + esc(b.note) + '</div>' : '')
                    + (hasObs ? '<div class="reservafrota-day-item__reason obs"><i class="ti ti-message-circle"></i> Observação: ' + esc(b.obs) + '</div>' : '')
                    + '</div>'
                    + '<div class="reservafrota-day-item__actions">' + sheetBtn + cancelBtn + uploadSheetBtn + '</div>'
                    + '</div>';
            }).join('');

            if (items.length) {
                listHtml = '<div class="reservafrota-modal__existinghead">'
                    + items.length + (items.length === 1 ? ' agendamento neste dia' : ' agendamentos neste dia')
                    + '</div>' + listHtml;
            }
            modalExisting.innerHTML = listHtml;

            // Clicar no card preenche o formulário para edição.
            modalExisting.querySelectorAll('.reservafrota-day-item').forEach(function (card, idx) {
                card.addEventListener('click', function (e) {
                    if (e.target.closest('a, button')) { return; }
                    var b = items[idx];
                    if (!b) { return; }
                    
                    // Muda para modo edição
                    document.getElementById('cb-m-action-add').disabled = true;
                    document.getElementById('cb-m-action-update').disabled = false;
                    document.getElementById('cb-m-id').disabled = false;
                    document.getElementById('cb-m-id').value = b.id;
                    document.getElementById('cb-m-form-title').innerHTML = '<i class="ti ti-pencil"></i> Editar agendamento';
                    document.getElementById('cb-m-submit').innerHTML = '<i class="ti ti-device-floppy"></i> Salvar alterações';

                    // Cancelar/Confirmar/Finalizar ficam disponíveis aqui mesmo (conforme
                    // o status); Salvar só habilita quando algo for alterado (ver markDirty).
                    editingStatus = b.status;
                    if (submitBtn) { submitBtn.disabled = true; }
                    if (cancelBtn) {
                        cancelBtn.hidden = !b.can_cancel;
                        cancelBtn.disabled = false;
                        cancelBtn.setAttribute('data-id', b.id);
                    }
                    if (confirmBtn) {
                        confirmBtn.hidden = !(canApprove && b.status === 1);
                        confirmBtn.disabled = false;
                        confirmBtn.setAttribute('data-id', b.id);
                    }
                    if (arriveBtn) {
                        arriveBtn.hidden = !(canApprove && b.status === 2 && !b.returned_at);
                        arriveBtn.disabled = false;
                        arriveBtn.setAttribute('data-id', b.id);
                    }

                    // Preenche os campos (o carro não é mais escolhido aqui — é
                    // designado pelo gestor ao aprovar; ver "Confirmar" acima).
                    if (carSelect) {
                        // Store editing id for availability check (exclude self)
                        editingId = b.id;
                        carSelect.value = b.car_id ? String(b.car_id) : '';
                        // No agendamento o veiculo nao pode ser alterado na edicao - desabilita para evitar confusao
                        carSelect.disabled = true;
                        // if car no longer active, add option
                        if (b.car_id && !Array.from(carSelect.options).some(function(o){ return o.value==String(b.car_id); })) {
                            var opt = document.createElement('option');
                            opt.value = String(b.car_id);
                            opt.textContent = b.car + ' (atual)';
                            carSelect.appendChild(opt);
                            carSelect.value = String(b.car_id);
                        }
                    }
                    var driverInp = document.getElementById('cb-m-driver');
                    if (driverInp) { driverInp.value = b.driver || ''; }
                    var compQ = document.getElementById('cb-m-companion-q');
                    var compWrap = document.getElementById('cb-m-companion-wrap');
                    if (compQ) { compQ.checked = !!b.has_companion; }
                    if (compWrap) { compWrap.hidden = !b.has_companion; }
                    var compHidden = compWrap ? compWrap.querySelector('.reservafrota-companion-value') : null;
                    var compCount = compWrap ? compWrap.querySelector('.reservafrota-companion-count') : null;
                    if (compHidden) { compHidden.value = b.companion || ''; }
                    // Reaproveita o inicializador do módulo de acompanhantes (agenda.js).
                    if (b.has_companion && compCount && window.cbInitComp) { window.cbInitComp(compCount); }
                    
                    if (mDate) { mDate.value = b.departure.substr(0, 10); }
                    if (mTime) { mTime.value = b.departure.substr(11, 5); }
                    
                    if (mADate) { mADate.value = b.arrival ? b.arrival.substr(0, 10) : ''; }
                    if (mATime) { mATime.value = b.arrival ? b.arrival.substr(11, 5) : ''; }
                    
                    var destInput = document.getElementById('cb-m-dest');
                    if (destInput) { destInput.value = b.destination || ''; }

                    // Remove destaque de outros cards e destaca este
                    modalExisting.querySelectorAll('.reservafrota-day-item').forEach(function(c){ c.classList.remove('is-editing'); });
                    card.classList.add('is-editing');
                });
            });

            // Pré-preenche o dia no formulário do popup (data e hora separadas).
            if (modalDate) { modalDate.value = dateStr; }
            if (modalMonth) { modalMonth.value = currentYm(); }
            if (mDate) {
                // Solicitante não pode escolher data passada; o gestor pode.
                if (canApprove) { mDate.removeAttribute('min'); } else { mDate.min = todayStr(); }
                mDate.value = dateStr;
            }
            if (mTime && !mTime.value) { mTime.value = '08:00'; }
            if (mADate) { mADate.value = ''; }
            if (mATime) { mATime.value = ''; }
            if (carSelect) { carSelect.value = ''; carSelect.disabled = false; }
            editingId = null;
            if (carHint) { carHint.hidden = true; carHint.textContent=''; }

            modal.hidden = false;
            // Atualiza disponibilidade após abrir (timeout para garantir valores preenchidos)
            setTimeout(updateCarAvailability, 120);
            document.body.classList.add('reservafrota-modal-open');
        }

        function closeModal() {
            if (!modal) { return; }
            modal.hidden = true;
            document.body.classList.remove('reservafrota-modal-open');
            
            // Reseta o formulário para modo criação
            document.getElementById('cb-m-action-add').disabled = false;
            document.getElementById('cb-m-action-update').disabled = true;
            document.getElementById('cb-m-id').disabled = true;
            document.getElementById('cb-m-id').value = '';
            document.getElementById('cb-m-form-title').innerHTML = '<i class="ti ti-calendar-plus"></i> Novo agendamento';
            document.getElementById('cb-m-submit').innerHTML = '<i class="ti ti-send"></i> Solicitar agendamento';

            editingStatus = null;
            editingId = null;
            if (carSelect) { carSelect.disabled = false; }
            if (submitBtn) { submitBtn.disabled = false; }
            if (cancelBtn) { cancelBtn.hidden = true; cancelBtn.disabled = false; }
            if (confirmBtn) { confirmBtn.hidden = true; confirmBtn.disabled = false; }
            if (arriveBtn) { arriveBtn.hidden = true; arriveBtn.disabled = false; }

            if (modalForm) { modalForm.reset(); }
            if (carHint) { carHint.hidden=true; }
        }

        function statusName(s) {
            return s === 2 ? 'approved' : (s === 3 ? 'rejected' : (s === 4 ? 'cancelled' : (s === 5 ? 'arrived' : 'pending')));
        }

        function doDelete(id) {
            if (!confirm('Apagar este agendamento? Esta ação não pode ser desfeita.')) { return; }
            var form = document.createElement('form');
            form.method = 'post';
            form.action = bform;
            form.innerHTML =
                '<input type="hidden" name="id" value="' + esc(id) + '">'
                + '<input type="hidden" name="purge" value="1">'
                + '<input type="hidden" name="_glpi_csrf_token" value="' + esc(csrf) + '">';
            document.body.appendChild(form);
            form.submit();
        }

        // navegação
        root.querySelectorAll('[data-nav]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var nav = btn.dataset.nav;
                if (nav === 'today') {
                    cur = new Date();
                    cur.setDate(1);
                } else {
                    cur.setMonth(cur.getMonth() + parseInt(nav, 10));
                }
                load();
            });
        });

        // Botão "Reservar veículo" - abre modal de nova reserva
        if (newBtn) {
            newBtn.addEventListener('click', function () {
                if (!canCreate) { return; }
                var ym = currentYm();
                var today = todayStr();
                var targetDay;
                if (today.substr(0,7) === ym) {
                    targetDay = parseInt(today.substr(8,2),10);
                } else {
                    targetDay = 1;
                }
                var items = (latestByDay && latestByDay[targetDay]) ? latestByDay[targetDay] : [];
                openDay(targetDay, items);
            });
        }

        // Disponibilidade de veículos por horário
        function updateCarAvailability() {
            if (!carSelect || !availabilityUrl) { return; }
            if (!mDate || !mTime || !mDate.value || !mTime.value) { return; }
            var dep = mDate.value + 'T' + mTime.value;
            var arr = '';
            if (mADate && mADate.value) {
                var at = (mATime && mATime.value) ? mATime.value : mTime.value;
                arr = mADate.value + 'T' + at;
            }
            var url = availabilityUrl + '?departure=' + encodeURIComponent(dep);
            if (arr) { url += '&arrival=' + encodeURIComponent(arr); }
            if (editingId) { url += '&exclude=' + encodeURIComponent(editingId); }
            fetch(url, { headers: {'X-Requested-With':'XMLHttpRequest'}, credentials:'same-origin'})
                .then(function(r){ return r.ok ? r.json() : null; })
                .then(function(data){
                    if (!data || !Array.isArray(data.cars)) { return; }
                    var blockedCount = 0;
                    Array.prototype.forEach.call(carSelect.options, function(opt){
                        if (!opt.value) { opt.disabled = false; opt.textContent = opt.textContent.replace(/ \(indisponível\)$/,'').replace(/ \(ocupado.*\)$/,''); return; }
                        var cid = parseInt(opt.value,10);
                        var info = null;
                        for (var i=0;i<data.cars.length;i++){ if (data.cars[i].id===cid){ info=data.cars[i]; break; } }
                        if (info && info.blocked) {
                            opt.disabled = true;
                            if (opt.textContent.indexOf('indisponível')===-1) { opt.textContent += ' (indisponível)'; }
                            blockedCount++;
                            if (carSelect.value==String(cid)) { carHint.hidden=false; carHint.textContent='Este veículo já está reservado neste horário. Escolha outro.'; carHint.className='reservafrota-car-availability is-error'; }
                        } else {
                            opt.disabled = false;
                            opt.textContent = opt.textContent.replace(/ \(indisponível\)$/,'');
                        }
                    });
                    if (carHint) {
                        if (blockedCount>0) {
                            var free = data.cars.length - blockedCount;
                            carHint.hidden = false;
                            carHint.textContent = free + ' veículo(s) disponível(is) neste horário' + (blockedCount ? ' • ' + blockedCount + ' ocupado(s)' : '');
                            carHint.className = 'reservafrota-car-availability ' + (free===0 ? 'is-error' : 'is-ok');
                            if (free===0) { carHint.textContent += ' — tente outro horário'; }
                        } else {
                            carHint.hidden = false;
                            carHint.textContent = 'Todos os ' + data.cars.length + ' veículos disponíveis neste horário';
                            carHint.className = 'reservafrota-car-availability is-ok';
                        }
                    }
                })
                .catch(function(){});
        }
        if (carSelect && mDate) {
            [mDate, mTime, mADate, mATime].forEach(function(el){
                if (el) { el.addEventListener('change', updateCarAvailability); el.addEventListener('input', updateCarAvailability); }
            });
            if (carSelect) { carSelect.addEventListener('change', function(){
                if (carHint && carSelect.options[carSelect.selectedIndex] && carSelect.options[carSelect.selectedIndex].disabled) {
                    carHint.hidden=false; carHint.textContent='Veículo indisponível neste horário'; carHint.className='reservafrota-car-availability is-error';
                }
            });}
        }

        // Fechar o popup: botão X, clique no fundo (backdrop) ou tecla ESC.
        if (modal) {
            modal.querySelectorAll('[data-close]').forEach(function (el) {
                el.addEventListener('click', closeModal);
            });
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && !modal.hidden) { closeModal(); }
            });
        }

        // Qualquer alteração num campo do formulário, em modo edição, habilita
        // "Salvar alterações" e bloqueia Cancelar/Confirmar/Finalizar até a
        // pessoa decidir entre salvar ou descartar a edição.
        function markDirty() {
            var editing = document.getElementById('cb-m-action-update');
            if (!editing || editing.disabled) { return; } // criando um novo: não se aplica
            if (submitBtn) { submitBtn.disabled = false; }
            if (cancelBtn && !cancelBtn.hidden) { cancelBtn.disabled = true; }
            if (confirmBtn && !confirmBtn.hidden) { confirmBtn.disabled = true; }
            if (arriveBtn && !arriveBtn.hidden) { arriveBtn.disabled = true; }
        }
        if (modalForm) {
            modalForm.addEventListener('input', markDirty);
            modalForm.addEventListener('change', markDirty);
        }

        // Cancelar/Finalizar aqui reaproveitam os mesmos pop-ups de confirmação
        // já usados na lista de agendamentos (data-cb-cancel/data-cb-arrive,
        // tratados em agenda.js) — só fecham este popup antes de abri-los.
        if (cancelBtn) { cancelBtn.addEventListener('click', closeModal); }
        if (arriveBtn) { arriveBtn.addEventListener('click', closeModal); }

        // Recarrega só a lista de agendamentos (abaixo do calendário), sem
        // recarregar a página inteira.
        function reloadBookingList() {
            if (!blistUrl) { return; }
            var list = document.getElementById('reservafrota-booking-list');
            if (!list) { return; }
            fetch(blistUrl, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin'
            })
                .then(function (r) { return r.ok ? r.text() : null; })
                .then(function (html) { if (html) { list.outerHTML = html; } })
                .catch(function () {});
        }

        // Combina dia + hora nos campos ocultos e junta os dias da semana
        // marcados, antes de enviar o formulário do popup via AJAX (para não
        // recarregar a página inteira — só a lista de agendamentos é atualizada).
        if (modalForm) {
            modalForm.addEventListener('submit', function (e) {
                e.preventDefault();

                var editing = document.getElementById('cb-m-action-update');
                var isEditing = editing && !editing.disabled;
                if (isEditing && editingStatus === 2) {
                    var ok = window.confirm(
                        'Ao salvar, este agendamento aprovado voltará para Pendente e precisará ser aprovado novamente. Deseja continuar?'
                    );
                    if (!ok) { return; }
                }

                if (carSelect && carSelect.hasAttribute('required') && !carSelect.value) {
                    alert('Selecione o veículo que deseja reservar.');
                    carSelect.focus();
                    return;
                }
                if (mDate && mTime && mDate.value && mTime.value) {
                    // Valida se data nao é passada (para nao aprovador)
                    if (!canApprove) {
                        var today = todayStr();
                        if (mDate.value < today) {
                            alert('Não é possível reservar em data passada. Escolha hoje ou futuro.');
                            return;
                        }
                    }
                    modalDep.value = mDate.value + 'T' + mTime.value;
                } else {
                    alert('Informe o dia e a hora da saída.');
                    return;
                }
                // Chegada: basta escolher o DIA. Se não informar a hora,
                // herda a hora da saída — assim o intervalo (saída → chegada)
                // é preenchido no calendário mesmo sem digitar horário.
                if (modalArr) {
                    if (mADate && mADate.value) {
                        var at = (mATime && mATime.value)
                            ? mATime.value
                            : (mTime && mTime.value ? mTime.value : '18:00');
                        modalArr.value = mADate.value + 'T' + at;
                    } else {
                        modalArr.value = '';
                    }
                }

                // Mostra estado de envio
                if (submitBtn) { submitBtn.disabled = true; submitBtn.innerHTML = '<i class="ti ti-loader ti-spin"></i> Enviando...'; }
                var formData = new FormData(modalForm);
                // Garante que token atual está no FormData (atualiza caso tenha sido renovado)
                if (csrf) { formData.set('_glpi_csrf_token', csrf); }
                fetch(bform, {
                    method: 'POST',
                    body: formData,
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-Glpi-Csrf-Token': csrf },
                    credentials: 'same-origin'
                })
                    .then(function(r){
                        return r.text().then(function(text){
                            var data = null;
                            try { data = JSON.parse(text); } catch(e) { /* resposta pode ser HTML em caso de redirect */ }
                            return {ok: r.ok, status: r.status, data: data, text: text};
                        });
                    })
                    .then(function(res){
                        // Atualiza token CSRF se servidor retornou novo
                        if (res.data && res.data.csrf_token) {
                            csrf = res.data.csrf_token;
                            root.dataset.csrf = csrf;
                            var csrfInput = document.getElementById('reservafrota-modal-form') ? document.getElementById('reservafrota-modal-form').querySelector('input[name="_glpi_csrf_token"]') : null;
                            if (csrfInput) { csrfInput.value = csrf; }
                            // Atualiza também todos os data-csrf dos botoes
                            document.querySelectorAll('[data-csrf]').forEach(function(el){ el.setAttribute('data-csrf', csrf); });
                        }
                        if (res.data && res.data.success) {
                            closeModal();
                            reloadBookingList();
                            load();
                            // Toast sucesso
                            var toast = document.createElement('div');
                            toast.className = 'reservafrota-toast';
                            toast.style.background = '#059669';
                            toast.innerHTML = '<i class="ti ti-check"></i> Reserva solicitada com sucesso! <button class="reservafrota-toast__close" onclick="this.parentElement.remove()"><i class="ti ti-x"></i></button>';
                            document.body.appendChild(toast);
                            setTimeout(function(){ if(toast.parentElement) toast.remove(); }, 4000);
                        } else if (res.data && !res.data.success) {
                            var err = res.data.error || 'Erro ao criar reserva';
                            // Tenta extrair mensagens detalhadas
                            if (res.data.messages) {
                                try {
                                    var msgs = res.data.messages;
                                    var flat = [];
                                    for (var k in msgs) {
                                        if (Array.isArray(msgs[k])) { flat = flat.concat(msgs[k]); }
                                        else if (typeof msgs[k]==='string') { flat.push(msgs[k]); }
                                    }
                                    if (flat.length) { err = flat.join(' '); }
                                } catch(e){}
                            }
                            alert(err);
                            if (submitBtn) { submitBtn.disabled = false; submitBtn.innerHTML = isEditing ? '<i class="ti ti-device-floppy"></i> Salvar alterações' : '<i class="ti ti-send"></i> Solicitar agendamento'; }
                        } else {
                            // Fallback: se resposta não é JSON mas ok (redirect HTML), considera sucesso e recarrega
                            if (res.ok) {
                                closeModal();
                                reloadBookingList();
                                load();
                            } else {
                                var msg = 'Erro ao solicitar reserva (HTTP '+res.status+').';
                                if (res.status === 403) {
                                    msg += '\n\nPossíveis causas:\n- Sua sessão expirou (recarregue a página)\n- Seu perfil não tem permissão "Criar" em Administração > Perfis > Reserva de Frota\n- Token CSRF inválido';
                                }
                                // Tenta extrair mensagem do HTML de erro do GLPI
                                try {
                                    var m = res.text.match(/<div[^>]*class="[^"]*alert[^"]*"[^>]*>(.*?)<\/div>/i);
                                    if (m && m[1]) {
                                        var tmp = document.createElement('div'); tmp.innerHTML = m[1]; msg += '\n\nDetalhe: ' + tmp.textContent.trim().substring(0,200);
                                    }
                                } catch(e){}
                                alert(msg);
                                if (submitBtn) { submitBtn.disabled = false; submitBtn.innerHTML = isEditing ? '<i class="ti ti-device-floppy"></i> Salvar alterações' : '<i class="ti ti-send"></i> Solicitar agendamento'; }
                            }
                        }
                    })
                    .catch(function () {
                        // Sem rede/erro inesperado: cai para o envio normal do formulário.
                        if (submitBtn) { submitBtn.disabled = false; submitBtn.innerHTML = isEditing ? '<i class="ti ti-device-floppy"></i> Salvar alterações' : '<i class="ti ti-send"></i> Solicitar agendamento'; }
                        // tenta envio normal se AJAX falhar
                        // modalForm.submit();
                        alert('Falha de conexão. Verifique sua internet e tente novamente.');
                    });
            });
        }

        // Confirmar (aprovar com escolha de carro) — abre o mesmo pop-up usado
        // no kanban, fechando primeiro este popup do dia.
        if (confirmBtn) {
            confirmBtn.addEventListener('click', function () {
                var id = confirmBtn.getAttribute('data-id');
                closeModal();
                if (window.cbOpenApprove) { window.cbOpenApprove(id, bform, csrf); }
            });
        }

        load();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}());
