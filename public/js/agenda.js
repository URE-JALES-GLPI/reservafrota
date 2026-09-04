/* global window, document, fetch */

/**
 * Reservafrota — lógica da página de agenda.
 * Carregado em todas as páginas do GLPI, mas só age quando o board existe.
 */
(function () {
    'use strict';

    /* Botão "Voltar" — funciona em qualquer página do plugin (delegação no
       documento, fora do init, pois este script carrega em todo o GLPI). */
    document.addEventListener('click', function (e) {
        var back = e.target.closest && e.target.closest('.reservafrota-back');
        if (!back) { return; }
        if (back.hasAttribute('data-x')) { return; } // botão de fechar modal: tratado em outro handler
        e.preventDefault();
        // Vai direto para o destino do botão (link ou data-home); só usa o
        // histórico do navegador como último recurso.
        var dest = back.dataset.home || (back.tagName === 'A' ? back.getAttribute('href') : '');
        if (dest) {
            window.location.href = dest;
        } else if (window.history.length > 1) {
            window.history.back();
        }
    });

    /* Popup informativo (ex.: ver motivo do agendamento). */
    document.addEventListener('click', function (e) {
        var btn = e.target.closest && e.target.closest('[data-cb-info]');
        if (!btn) { return; }
        e.preventDefault();
        var title = btn.getAttribute('data-title') || 'Detalhes';
        var text = btn.getAttribute('data-text') || '';
        var overlay = document.createElement('div');
        overlay.className = 'reservafrota-modal';
        var dlg = document.createElement('div');
        dlg.className = 'reservafrota-modal__dialog';
        dlg.style.maxWidth = '460px';
        var head = document.createElement('div');
        head.className = 'reservafrota-modal__head';
        var h = document.createElement('h3'); h.textContent = title;
        var x = document.createElement('button');
        x.type = 'button'; x.className = 'reservafrota-modal__close'; x.innerHTML = '<i class="ti ti-x"></i>';
        head.appendChild(h); head.appendChild(x);
        var body = document.createElement('div');
        body.className = 'reservafrota-modal__body';
        var p = document.createElement('p');
        p.style.whiteSpace = 'pre-wrap'; p.style.margin = '0'; p.textContent = text;
        body.appendChild(p);
        var bd = document.createElement('div'); bd.className = 'reservafrota-modal__backdrop';
        dlg.appendChild(head); dlg.appendChild(body);
        overlay.appendChild(bd); overlay.appendChild(dlg);
        document.body.appendChild(overlay);
        document.body.classList.add('reservafrota-modal-open');
        function close() { overlay.remove(); document.body.classList.remove('reservafrota-modal-open'); }
        x.addEventListener('click', close);
        bd.addEventListener('click', close);
    });

    /* ---- Acompanhantes: "Sim/Não" + quantidade (máx. 4) + N campos de nome ---- */
    var CB_MAX_COMP = 4;

    function cbCompBlock(el) { return el.closest('.reservafrota-companion-block'); }

    function cbSyncComp(block) {
        if (!block) { return; }
        var hidden = block.querySelector('.reservafrota-companion-value');
        if (!hidden) { return; }
        var names = [].slice.call(block.querySelectorAll('.cb-cname')).map(function (i) {
            return i.value.trim();
        }).filter(Boolean);
        hidden.value = names.join(', ');
    }

    function cbRenderNames(countSel, prefill) {
        var block = cbCompBlock(countSel);
        if (!block) { return; }
        var namesDiv = block.querySelector('.reservafrota-companion-names');
        if (!namesDiv) { return; }
        var n = parseInt(countSel.value, 10) || 1;
        if (n < 1) { n = 1; }
        if (n > CB_MAX_COMP) { n = CB_MAX_COMP; countSel.value = n; }
        // Preserva os nomes já digitados.
        var existing = prefill || [].slice.call(namesDiv.querySelectorAll('.cb-cname')).map(function (i) { return i.value; });
        var html = '';
        for (var k = 0; k < n; k++) {
            var val = existing[k] ? ('' + existing[k]).replace(/"/g, '&quot;') : '';
            html += '<input type="text" class="form-control cb-cname" style="margin-top:0.4rem;" placeholder="Nome do acompanhante ' + (k + 1) + '" value="' + val + '">';
        }
        namesDiv.innerHTML = html;
        cbSyncComp(block);
    }

    function cbInitComp(countSel) {
        var block = cbCompBlock(countSel);
        if (!block) { return; }
        var hidden = block.querySelector('.reservafrota-companion-value');
        var names = (hidden && hidden.value) ? hidden.value.split(',').map(function (s) { return s.trim(); }).filter(Boolean) : [];
        var n = names.length || (parseInt(countSel.value, 10) || 1);
        if (n < 1) { n = 1; } if (n > CB_MAX_COMP) { n = CB_MAX_COMP; }
        countSel.value = n;
        cbRenderNames(countSel, names);
    }
    window.cbInitComp = cbInitComp;

    // Sim/Não → mostra/esconde e inicializa os campos.
    document.addEventListener('change', function (e) {
        var sel = e.target.closest && e.target.closest('.reservafrota-companion-q');
        if (!sel) { return; }
        var wrap = document.getElementById(sel.getAttribute('data-target'));
        if (!wrap) { return; }
        var on = sel.type === 'checkbox' ? sel.checked : (sel.value === '1');
        wrap.hidden = !on;
        if (on) {
            var countSel = wrap.querySelector('.reservafrota-companion-count');
            if (countSel) { cbInitComp(countSel); }
        } else {
            var block = wrap.querySelector('.reservafrota-companion-block');
            if (block) { var h = block.querySelector('.reservafrota-companion-value'); if (h) { h.value = ''; } }
        }
    });
    // Mudou a quantidade → renderiza N campos.
    document.addEventListener('change', function (e) {
        var cs = e.target.closest && e.target.closest('.reservafrota-companion-count');
        if (cs) { cbRenderNames(cs); }
    });
    // Digitou um nome → atualiza o valor enviado.
    document.addEventListener('input', function (e) {
        var nm = e.target.closest && e.target.closest('.cb-cname');
        if (nm) { cbSyncComp(cbCompBlock(nm)); }
    });
    // Inicializa os blocos já presentes na página.
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.reservafrota-companion-q').forEach(function (sel) {
            var wrap = document.getElementById(sel.getAttribute('data-target'));
            var on = sel.type === 'checkbox' ? sel.checked : sel.value === '1';
            if (wrap && on) {
                var cs = wrap.querySelector('.reservafrota-companion-count');
                if (cs) { cbInitComp(cs); }
            }
        });
    });

    /* Tela única do agendamento (aberta ao clicar no card da lista): editar
       os campos e, conforme o status, Cancelar / Confirmar (aprovar +
       escolher carro) / Finalizar viagem. Qualquer alteração num campo
       habilita "Salvar" e bloqueia as outras ações até salvar ou fechar. */
    function openEditModal(b, bform, csrf, canApprove) {
        function esc2(s) {
            return ('' + (s == null ? '' : s)).replace(/[&<>"]/g, function (c) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
            });
        }
        function dtLocal(s) { return s ? ('' + s).replace(' ', 'T').slice(0, 16) : ''; }

        var usersTpl = document.getElementById('reservafrota-users-template');
        var usersOptions = usersTpl ? usersTpl.innerHTML : '<option value="">—</option>';
        var hasComp = (parseInt(b.has_companion, 10) === 1);
        var status = parseInt(b.status, 10) || 1;
        var canCancel = !!b.can_cancel;
        var canConfirm = canApprove && status === 1;
        var canFinish = canApprove && status === 2 && !b.returned_at;

        var overlay = document.createElement('div');
        overlay.className = 'reservafrota-modal';
        overlay.innerHTML =
            '<div class="reservafrota-modal__backdrop" data-x></div>'
          + '<div class="reservafrota-modal__dialog" style="max-width:560px;">'
          + '<div class="reservafrota-modal__head"><h3><i class="ti ti-edit"></i> Agendamento</h3>'
          + '<button type="button" class="reservafrota-modal__close" data-x><i class="ti ti-x"></i></button></div>'
          + '<form method="post" action="' + bform + '" class="reservafrota-modal__body">'
          + '<input type="hidden" name="update" value="1">'
          + '<input type="hidden" name="id" value="' + esc2(b.id) + '">'
          + '<input type="hidden" name="_glpi_csrf_token" value="' + esc2(csrf) + '">'
          + '<input type="hidden" name="_from_calendar" value="1">'
          + '<label class="form-label">Carro</label>'
          + '<input type="text" class="form-control" value="' + esc2(b.car || 'A designar na aprovação') + '" disabled>'
          + '<label class="form-label" style="margin-top:0.6rem;">Motorista</label>'
          + '<select class="form-select cb-e-driver" name="driver">' + usersOptions + '</select>'
          + '<input type="hidden" name="has_companion" value="0">'
          + '<div class="form-check form-switch" style="margin-top:0.6rem;">'
          + '<input class="form-check-input reservafrota-companion-q cb-e-compq" type="checkbox" role="switch" id="cb-e-compq" name="has_companion" value="1"' + (hasComp ? ' checked' : '') + ' data-target="cb-e-compwrap">'
          + '<label class="form-check-label" for="cb-e-compq">Acompanhante</label>'
          + '</div>'
          + '<div id="cb-e-compwrap" class="reservafrota-companion-wrap"' + (hasComp ? '' : ' hidden') + '>'
          + '<div class="reservafrota-companion-block">'
          + '<label class="form-label" style="margin-top:0.5rem;">Quantos acompanhantes? (máx. 4)</label>'
          + '<select class="form-select reservafrota-companion-count" data-names="cb-e-cnames"><option value="1">1</option><option value="2">2</option><option value="3">3</option><option value="4">4</option></select>'
          + '<div id="cb-e-cnames" class="reservafrota-companion-names"></div>'
          + '<input type="hidden" name="companion" class="reservafrota-companion-value" value="' + esc2(b.companion || '') + '">'
          + '</div>'
          + '</div>'
          + '<label class="form-label" style="margin-top:0.6rem;">Saída — dia e hora</label>'
          + '<input type="datetime-local" class="form-control" name="date_departure" value="' + dtLocal(b.departure) + '" step="1800" required>'
          + '<label class="form-label" style="margin-top:0.6rem;">Chegada — dia e hora (opcional)</label>'
          + '<input type="datetime-local" class="form-control" name="date_arrival" value="' + dtLocal(b.arrival) + '" step="1800">'
          + '<label class="form-label" style="margin-top:0.6rem;">Destino</label>'
          + '<input type="text" class="form-control" name="destination" value="' + esc2(b.destination) + '">'
          + '<div class="reservafrota-confirm-actions">'
          + (canCancel ? '<button type="button" class="reservafrota-back cb-e-cancel"><i class="ti ti-ban"></i> Cancelar</button>' : '')
          + (canConfirm ? '<button type="button" class="reservafrota-btn-approve cb-e-confirm"><i class="ti ti-check"></i> Confirmar</button>' : '')
          + (canFinish ? '<button type="button" class="reservafrota-btn-info cb-e-arrive"><i class="ti ti-flag-check"></i> Finalizar viagem</button>' : '')
          + '<button type="submit" class="reservafrota-submit cb-e-save" style="margin:0;" disabled><i class="ti ti-device-floppy"></i> Salvar alterações</button>'
          + '</div></form></div>';
        document.body.appendChild(overlay);
        document.body.classList.add('reservafrota-modal-open');

        var form = overlay.querySelector('form');
        // Pré-seleciona o motorista atual.
        var drv = overlay.querySelector('.cb-e-driver');
        if (drv) { drv.value = b.driver || ''; }
        // Inicializa os campos de acompanhantes (se houver).
        var cs = overlay.querySelector('.reservafrota-companion-count');
        if (cs && parseInt(b.has_companion, 10) === 1) { cbInitComp(cs); }

        function close() { overlay.remove(); document.body.classList.remove('reservafrota-modal-open'); }
        overlay.querySelectorAll('[data-x]').forEach(function (el) { el.addEventListener('click', close); });

        // Qualquer alteração habilita Salvar e bloqueia Cancelar/Confirmar/Finalizar.
        var saveBtn = overlay.querySelector('.cb-e-save');
        var cancelBtn = overlay.querySelector('.cb-e-cancel');
        var confirmBtn = overlay.querySelector('.cb-e-confirm');
        var arriveBtn = overlay.querySelector('.cb-e-arrive');
        function markDirty() {
            if (saveBtn) { saveBtn.disabled = false; }
            if (cancelBtn) { cancelBtn.disabled = true; }
            if (confirmBtn) { confirmBtn.disabled = true; }
            if (arriveBtn) { arriveBtn.disabled = true; }
        }
        form.addEventListener('input', markDirty);
        form.addEventListener('change', markDirty);

        if (cancelBtn) {
            cancelBtn.addEventListener('click', function () { close(); openCancelModal(b.id, bform, csrf); });
        }
        if (confirmBtn) {
            confirmBtn.addEventListener('click', function () { close(); cbOpenApprove(b.id, bform, csrf); });
        }
        if (arriveBtn) {
            arriveBtn.addEventListener('click', function () { close(); openArriveModal(b.id, bform, csrf); });
        }

        // Avisa que volta para Pendente ao salvar uma edição aprovada.
        form.addEventListener('submit', function (e) {
            if (status === 2) {
                if (!window.confirm('Ao salvar, este agendamento aprovado voltará para Pendente e precisará ser aprovado novamente. Deseja continuar?')) {
                    e.preventDefault();
                }
            }
        });
    }

    document.addEventListener('click', function (e) {
        var card = e.target.closest && e.target.closest('.reservafrota-blist-item[data-cb-edit]');
        if (!card) { return; }
        if (e.target.closest('a, button')) { return; } // ex.: "Baixar folha"
        e.preventDefault();
        var data = {};
        try { data = JSON.parse(card.getAttribute('data-booking') || '{}'); } catch (x) {}
        openEditModal(data, card.getAttribute('data-bform'), card.getAttribute('data-csrf'), card.getAttribute('data-can-approve') === '1');
    });

    /* Clicar na notificação de pendentes leva até a coluna de Pendentes. */
    document.addEventListener('click', function (e) {
        var box = e.target.closest && e.target.closest('[data-cb-goto-pending]');
        if (!box) { return; }
        var col = document.querySelector('.col-pending');
        if (!col) { return; }
        col.scrollIntoView({ behavior: 'smooth', block: 'start' });
        col.classList.add('is-drop-over');
        setTimeout(function () { col.classList.remove('is-drop-over'); }, 1200);
    });

    /* Editar / remover a observação de uma viagem concluída. */
    document.addEventListener('click', function (e) {
        var btn = e.target.closest && e.target.closest('[data-cb-editobs]');
        if (!btn) { return; }
        e.preventDefault();
        var id = btn.getAttribute('data-id');
        var obs = btn.getAttribute('data-obs') || '';
        var bform = btn.getAttribute('data-bform');
        var csrf = btn.getAttribute('data-csrf');

        var overlay = document.createElement('div');
        overlay.className = 'reservafrota-modal';
        overlay.innerHTML =
            '<div class="reservafrota-modal__backdrop" data-x></div>'
          + '<div class="reservafrota-modal__dialog" style="max-width:460px;">'
          + '<div class="reservafrota-modal__head"><h3><i class="ti ti-message-circle"></i> Observação da viagem</h3>'
          + '<button type="button" class="reservafrota-modal__close" data-x><i class="ti ti-x"></i></button></div>'
          + '<form method="post" action="' + bform + '" class="reservafrota-modal__body">'
          + '<input type="hidden" name="update_obs" value="1">'
          + '<input type="hidden" name="id" value="' + id + '">'
          + '<input type="hidden" name="_glpi_csrf_token" value="' + csrf + '">'
          + '<textarea name="arrival_obs" class="form-control" rows="3" placeholder="Digite a observação...">' + obs + '</textarea>'
          + '<div class="reservafrota-confirm-actions">'
          + '<button type="button" class="reservafrota-back cb-obs-remove" data-x style="color:#c92a2a;border-color:#f3b6b6;"><i class="ti ti-trash"></i> Remover</button>'
          + '<button type="submit" class="reservafrota-submit" style="margin:0;"><i class="ti ti-device-floppy"></i> Salvar</button>'
          + '</div></form></div>';
        document.body.appendChild(overlay);
        document.body.classList.add('reservafrota-modal-open');

        function close() { overlay.remove(); document.body.classList.remove('reservafrota-modal-open'); }
        overlay.querySelectorAll('[data-x]').forEach(function (el) {
            if (el.classList.contains('cb-obs-remove')) { return; }
            el.addEventListener('click', close);
        });
        // Remover = limpa o texto e envia (observação vazia apaga).
        overlay.querySelector('.cb-obs-remove').addEventListener('click', function () {
            var form = overlay.querySelector('form');
            form.querySelector('textarea[name="arrival_obs"]').value = '';
            if (form.requestSubmit) { form.requestSubmit(); } else { form.submit(); }
        });
    });


    /* Confirmação estilizada para formulários (ex.: aprovar com conflito). */
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form || !form.getAttribute || !form.getAttribute('data-cb-confirm')) { return; }
        if (form.dataset.cbConfirmed === '1') { return; }
        e.preventDefault();
        var msg = form.getAttribute('data-cb-confirm');
        var title = form.getAttribute('data-cb-confirm-title') || 'Confirmação';
        var overlay = document.createElement('div');
        overlay.className = 'reservafrota-modal';
        overlay.innerHTML =
            '<div class="reservafrota-modal__backdrop" data-x></div>'
          + '<div class="reservafrota-modal__dialog" style="max-width:440px;">'
          + '<div class="reservafrota-modal__head"><h3><i class="ti ti-alert-triangle" style="color:#f97316;"></i> ' + title + '</h3>'
          + '<button type="button" class="reservafrota-modal__close" data-x><i class="ti ti-x"></i></button></div>'
          + '<div class="reservafrota-modal__body">'
          + '<p>' + msg + '</p>'
          + '<div class="reservafrota-confirm-actions">'
          + '<button type="button" class="reservafrota-back" data-x>Cancelar</button>'
          + '<button type="button" class="reservafrota-submit cb-yes" style="margin:0;background:#2f9e44;"><i class="ti ti-check"></i> Confirmar aprovação</button>'
          + '</div></div></div>';
        document.body.appendChild(overlay);
        document.body.classList.add('reservafrota-modal-open');
        function close() { overlay.remove(); document.body.classList.remove('reservafrota-modal-open'); }
        overlay.querySelectorAll('[data-x]').forEach(function (el) { el.addEventListener('click', close); });
        overlay.querySelector('.cb-yes').addEventListener('click', function () {
            form.dataset.cbConfirmed = '1';
            close();
            if (form.requestSubmit) { form.requestSubmit(); } else { form.submit(); }
        });
    });

    /* Linhas expansíveis (ex.: Retornos com observação). */
    document.addEventListener('click', function (e) {
        var row = e.target.closest && e.target.closest('tr[data-rowtoggle]');
        if (!row) { return; }
        if (e.target.closest('a, button')) { return; }
        var child = row.nextElementSibling;
        if (child && child.classList.contains('reservafrota-arow-child')) {
            child.hidden = !child.hidden;
            row.classList.toggle('is-open', !child.hidden);
        }
    });

    /* Prévia da imagem ao cadastrar/editar carro. */
    document.addEventListener('change', function (e) {
        var inp = e.target;
        if (!inp.classList || !inp.classList.contains('reservafrota-imgfile')) { return; }
        var f = inp.files && inp.files[0];
        var nameBox = document.querySelector('.reservafrota-imgname');
        var preview = document.querySelector('.reservafrota-photo-preview');
        if (nameBox) { nameBox.textContent = f ? f.name : ''; }
        if (f && preview && /^image\//.test(f.type)) {
            var url = URL.createObjectURL(f);
            preview.innerHTML = '';
            var img = document.createElement('img');
            img.alt = f.name;
            img.onload = function () { URL.revokeObjectURL(url); };
            img.src = url;
            preview.appendChild(img);
        }
    });

    /* Marcar chegada com anexo opcional (folha) + confirmação obrigatória. */
    function openArriveModal(id, bform, csrf) {
        csrf = csrf || '';
        var overlay = document.createElement('div');
        overlay.className = 'reservafrota-modal';
        overlay.innerHTML =
            '<div class="reservafrota-modal__backdrop" data-x></div>'
          + '<div class="reservafrota-modal__dialog" style="max-width:460px;">'
          + '<div class="reservafrota-modal__head"><h3>Finalizar viagem</h3>'
          + '<button type="button" class="reservafrota-modal__close" data-x><i class="ti ti-x"></i></button></div>'
          + '<div class="reservafrota-modal__body">'
          + '<p>Confirme o retorno do carro. A data e a hora vêm preenchidas com o momento atual, mas você pode ajustar.</p>'
          + '<form method="post" action="' + bform + '" enctype="multipart/form-data">'
          + '<input type="hidden" name="id" value="' + id + '">'
          + '<input type="hidden" name="arrive" value="1">'
          + '<input type="hidden" name="_glpi_csrf_token" value="' + csrf + '">'
          + '<input type="hidden" name="_redirect_anchor" value="reservafrota-arrived-section">'
          + '<label class="form-label"><b>Data e hora do retorno</b></label>'
          + '<div class="reservafrota-datetime">'
          + '<input type="date" class="form-control cb-rdate">'
          + '<input type="time" class="form-control cb-rtime">'
          + '</div>'
          + '<input type="hidden" name="returned_at" class="cb-rwhen">'
          + '<label class="form-label" style="margin-top:0.7rem;"><b>KM final do veículo</b> (opcional)</label>'
          + '<input type="number" name="km_final" class="form-control" min="0" step="1" placeholder="Ex.: 45210">'
          + '<label class="form-label" style="margin-top:0.7rem;"><b>Folha de agendamento</b> (opcional)</label>'
          + '<input type="file" name="arrival_sheet" class="form-control cb-file" accept=".pdf,.png,.jpg,.jpeg,.gif,.webp,.doc,.docx,.xls,.xlsx,.odt,.ods">'
          + '<div class="reservafrota-filepreview" hidden></div>'
          + '<label class="form-label" style="margin-top:0.7rem;"><b>Observação</b> (opcional)</label>'
          + '<textarea name="arrival_obs" class="form-control" rows="2" placeholder="Ex: Farol do carro queimou..."></textarea>'
          + '<label class="reservafrota-check"><input type="checkbox" class="cb-confirm" name="confirm_ok" value="1"> Confirmo que as informações enviadas estão corretas.</label>'
          + '<button type="submit" class="reservafrota-submit cb-go" style="margin-top:0.8rem;" disabled><i class="ti ti-flag-check"></i> Finalizar viagem</button>'
          + '</form></div></div>';
        document.body.appendChild(overlay);
        document.body.classList.add('reservafrota-modal-open');
        wireFileConfirm(overlay, false);
        wireArrivalWhen(overlay);
        function close() { overlay.remove(); document.body.classList.remove('reservafrota-modal-open'); }
        overlay.querySelectorAll('[data-x]').forEach(function (el) { el.addEventListener('click', close); });
    }

    document.addEventListener('click', function (e) {
        var btn = e.target.closest && e.target.closest('[data-cb-arrive]');
        if (!btn) { return; }
        e.preventDefault();
        openArriveModal(btn.getAttribute('data-id'), btn.getAttribute('data-bform'), btn.getAttribute('data-csrf'));
    });

    /* Preenche data/hora da chegada com o momento atual e mantém sincronizado. */
    function wireArrivalWhen(overlay) {
        var d = overlay.querySelector('.cb-rdate');
        var t = overlay.querySelector('.cb-rtime');
        var hid = overlay.querySelector('.cb-rwhen');
        if (!d || !t || !hid) { return; }
        var now = new Date();
        function p(n) { return n < 10 ? '0' + n : '' + n; }
        d.value = now.getFullYear() + '-' + p(now.getMonth() + 1) + '-' + p(now.getDate());
        t.value = p(now.getHours()) + ':' + p(now.getMinutes());
        function sync() { hid.value = (d.value && t.value) ? (d.value + 'T' + t.value) : ''; }
        sync();
        d.addEventListener('change', sync);
        t.addEventListener('change', sync);
    }

    /* Confirmar (aprovar) um agendamento pendente: o gestor escolhe o carro
       aqui. Carros com outro agendamento APROVADO no mesmo horário aparecem
       visíveis, porém bloqueados (não podem ser escolhidos). */
    function cbOpenApprove(id, bform, csrf) {
        csrf = csrf || '';
        var carslotUrl = bform.replace('front/booking.form.php', 'ajax/carslot.php');

        function esc2(s) {
            return ('' + (s == null ? '' : s)).replace(/[&<>"]/g, function (c) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
            });
        }

        var overlay = document.createElement('div');
        overlay.className = 'reservafrota-modal';
        overlay.innerHTML =
            '<div class="reservafrota-modal__backdrop" data-x></div>'
          + '<div class="reservafrota-modal__dialog" style="max-width:480px;">'
          + '<div class="reservafrota-modal__head"><h3><i class="ti ti-check"></i> Confirmar agendamento</h3>'
          + '<button type="button" class="reservafrota-modal__close" data-x><i class="ti ti-x"></i></button></div>'
          + '<div class="reservafrota-modal__body">'
          + '<p>Escolha o carro que será usado neste agendamento.</p>'
          + '<div class="reservafrota-carchoice-list"><p class="reservafrota-hint">Carregando carros…</p></div>'
          + '<label class="form-label" style="margin-top:0.6rem;">Comentário (opcional)</label>'
          + '<textarea class="form-control cb-ap-comment" rows="2"></textarea>'
          + '<div class="reservafrota-confirm-actions">'
          + '<button type="button" class="reservafrota-back" data-x>Fechar</button>'
          + '<button type="button" class="reservafrota-submit cb-ap-go" style="margin:0;" disabled><i class="ti ti-check"></i> Confirmar aprovação</button>'
          + '</div></div></div>';
        document.body.appendChild(overlay);
        document.body.classList.add('reservafrota-modal-open');
        function close() { overlay.remove(); document.body.classList.remove('reservafrota-modal-open'); }
        overlay.querySelectorAll('[data-x]').forEach(function (el) { el.addEventListener('click', close); });

        var list = overlay.querySelector('.reservafrota-carchoice-list');
        var go = overlay.querySelector('.cb-ap-go');
        var chosen = null;

        fetch(carslotUrl + '?id=' + encodeURIComponent(id), { credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (data) {
                if (!data || !data.cars || !data.cars.length) {
                    list.innerHTML = '<p class="reservafrota-hint">Nenhum carro cadastrado.</p>';
                    return;
                }
                list.innerHTML = data.cars.map(function (c) {
                    return '<label class="reservafrota-carchoice' + (c.blocked ? ' is-blocked' : '') + '">'
                        + '<input type="radio" name="cb-ap-car" value="' + c.id + '"' + (c.blocked ? ' disabled' : '') + '>'
                        + '<span>' + esc2(c.name) + (c.plate ? ' · ' + esc2(c.plate) : '') + '</span>'
                        + (c.blocked ? '<small>Já reservado neste horário</small>' : '')
                        + '</label>';
                }).join('');
                if (data.car_id) {
                    var pre = list.querySelector('input[value="' + data.car_id + '"]');
                    if (pre && !pre.disabled) { pre.checked = true; chosen = data.car_id; go.disabled = false; }
                }
                list.querySelectorAll('input[name="cb-ap-car"]').forEach(function (inp) {
                    inp.addEventListener('change', function () {
                        chosen = inp.value;
                        go.disabled = false;
                    });
                });
            })
            .catch(function () {
                list.innerHTML = '<p class="reservafrota-hint">Não foi possível carregar os carros.</p>';
            });

        go.addEventListener('click', function () {
            if (!chosen) { return; }
            var comment = overlay.querySelector('.cb-ap-comment').value;
            var form = document.createElement('form');
            form.method = 'post';
            form.action = bform;
            form.innerHTML =
                '<input type="hidden" name="id" value="' + esc2(id) + '">'
              + '<input type="hidden" name="approve" value="1">'
              + '<input type="hidden" name="plugin_reservafrota_cars_id" value="' + esc2(chosen) + '">'
              + '<input type="hidden" name="comment_validation" value="' + esc2(comment) + '">'
              + '<input type="hidden" name="_glpi_csrf_token" value="' + esc2(csrf) + '">';
            document.body.appendChild(form);
            form.submit();
        });
    }
    window.cbOpenApprove = cbOpenApprove;

    /* Adicionar folha pelo calendário: prévia do arquivo + confirmação obrigatória. */
    document.addEventListener('click', function (e) {
        var btn = e.target.closest && e.target.closest('[data-cb-attach]');
        if (!btn) { return; }
        e.preventDefault();
        var id = btn.getAttribute('data-id');
        var bform = btn.getAttribute('data-bform');
        var csrf = btn.getAttribute('data-csrf') || '';
        var overlay = document.createElement('div');
        overlay.className = 'reservafrota-modal';
        overlay.innerHTML =
            '<div class="reservafrota-modal__backdrop" data-x></div>'
          + '<div class="reservafrota-modal__dialog" style="max-width:460px;">'
          + '<div class="reservafrota-modal__head"><h3>Adicionar folha de agendamento</h3>'
          + '<button type="button" class="reservafrota-modal__close" data-x><i class="ti ti-x"></i></button></div>'
          + '<div class="reservafrota-modal__body">'
          + '<form method="post" action="' + bform + '" enctype="multipart/form-data">'
          + '<input type="hidden" name="id" value="' + id + '">'
          + '<input type="hidden" name="upload_sheet" value="1">'
          + '<input type="hidden" name="_glpi_csrf_token" value="' + csrf + '">'
          + '<label class="form-label"><b>Arquivo da folha</b></label>'
          + '<input type="file" name="arrival_sheet" class="form-control cb-file" accept=".pdf,.png,.jpg,.jpeg,.gif,.webp,.doc,.docx,.xls,.xlsx,.odt,.ods">'
          + '<div class="reservafrota-filepreview" hidden></div>'
          + '<label class="reservafrota-check"><input type="checkbox" class="cb-confirm" name="confirm_ok" value="1"> Confirmo que as informações enviadas estão corretas.</label>'
          + '<button type="submit" class="reservafrota-submit cb-go" style="margin-top:0.8rem;" disabled><i class="ti ti-upload"></i> Enviar folha</button>'
          + '</form></div></div>';
        document.body.appendChild(overlay);
        document.body.classList.add('reservafrota-modal-open');
        wireFileConfirm(overlay, true);
        function close() { overlay.remove(); document.body.classList.remove('reservafrota-modal-open'); }
        overlay.querySelectorAll('[data-x]').forEach(function (el) { el.addEventListener('click', close); });
    });

    /* Liga prévia do arquivo + checkbox obrigatório ao botão de envio.
       requireFile = true exige também que um arquivo seja escolhido. */
    function wireFileConfirm(overlay, requireFile) {
        var file = overlay.querySelector('.cb-file');
        var prev = overlay.querySelector('.reservafrota-filepreview');
        var chk = overlay.querySelector('.cb-confirm');
        var go = overlay.querySelector('.cb-go');
        function refresh() {
            var hasFile = file && file.files && file.files.length > 0;
            go.disabled = !(chk.checked && (!requireFile || hasFile));
        }
        function fmtSize(n) {
            if (n < 1024) { return n + ' B'; }
            if (n < 1048576) { return (n / 1024).toFixed(1) + ' KB'; }
            return (n / 1048576).toFixed(1) + ' MB';
        }
        if (file) {
            file.addEventListener('change', function () {
                prev.innerHTML = '';
                prev.hidden = true;
                if (file.files && file.files[0]) {
                    var f = file.files[0];
                    if (/^image\//.test(f.type)) {
                        var img = document.createElement('img');
                        img.alt = f.name;
                        img.onload = function () { URL.revokeObjectURL(img.src); };
                        img.src = URL.createObjectURL(f);
                        prev.appendChild(img);
                    }
                    var info = document.createElement('div');
                    info.className = 'reservafrota-fileinfo';
                    info.innerHTML = '<i class="ti ti-file-description"></i> <b>' + (f.name || '—') + '</b>'
                        + '<br><span>' + (f.type || 'tipo desconhecido') + ' · ' + fmtSize(f.size || 0) + '</span>';
                    prev.appendChild(info);
                    prev.hidden = false;
                }
                refresh();
            });
        }
        if (chk) { chk.addEventListener('change', refresh); }
        refresh();
    }

    /* Filtros das tabelas da Análise (data, hora a partir de, status). */
    (function tableFilters() {
        function run() {
            document.querySelectorAll('.reservafrota-tablefilter').forEach(function (box) {
                var fDate = box.querySelector('.cb-f-date');
                var fTime = box.querySelector('.cb-f-time');
                var fStatus = box.querySelector('.cb-f-status');
                var clear = box.querySelector('.cb-f-clear');
                var rows = box.querySelectorAll('table tbody tr');
                if (!rows.length) { return; }
                function apply() {
                    var d = fDate ? fDate.value : '';
                    var t = fTime ? fTime.value : '';
                    var s = fStatus ? fStatus.value : '';
                    rows.forEach(function (tr) {
                        var ok = (!d || tr.dataset.date === d)
                            && (!s || tr.dataset.status === s)
                            && (!t || (tr.dataset.time || '') >= t);
                        tr.style.display = ok ? '' : 'none';
                    });
                }
                if (fDate) { fDate.addEventListener('change', apply); }
                if (fTime) { fTime.addEventListener('change', apply); }
                if (fStatus) { fStatus.addEventListener('change', apply); }
                if (clear) {
                    clear.addEventListener('click', function () {
                        if (fDate) { fDate.value = ''; }
                        if (fTime) { fTime.value = ''; }
                        if (fStatus) { fStatus.value = ''; }
                        apply();
                    });
                }
            });
        }
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', run);
        } else {
            run();
        }
    }());

    /* Filtro do histórico (data, hora a partir de, status). */
    (function historyFilter() {
        function run() {
            var root = document.getElementById('reservafrota-history');
            if (!root) { return; }
            var fDate = document.getElementById('cb-h-date');
            var fTime = document.getElementById('cb-h-time');
            var fStatus = document.getElementById('cb-h-status');
            var fCar = document.getElementById('cb-h-car');
            var clear = document.getElementById('cb-h-clear');
            var empty = document.getElementById('cb-h-empty');
            var rows = root.querySelectorAll('#cb-h-table tbody tr');
            if (!rows.length) { return; }

            function apply() {
                var d = fDate ? fDate.value : '';
                var t = fTime ? fTime.value : '';
                var s = fStatus ? fStatus.value : '';
                var car = fCar ? fCar.value : '';
                var shown = 0;
                rows.forEach(function (tr) {
                    var ok = (!d || tr.dataset.date === d)
                        && (!s || tr.dataset.status === s)
                        && (!t || (tr.dataset.time || '') >= t)
                        && (!car || tr.dataset.car === car);
                    tr.style.display = ok ? '' : 'none';
                    if (ok) { shown++; }
                });
                if (empty) { empty.hidden = shown !== 0; }
            }
            if (fDate) { fDate.addEventListener('change', apply); }
            if (fTime) { fTime.addEventListener('change', apply); }
            if (fStatus) { fStatus.addEventListener('change', apply); }
            if (fCar) { fCar.addEventListener('change', apply); }
            if (clear) {
                clear.addEventListener('click', function () {
                    if (fDate) { fDate.value = ''; }
                    if (fTime) { fTime.value = ''; }
                    if (fStatus) { fStatus.value = ''; }
                    if (fCar) { fCar.value = ''; }
                    apply();
                });
            }
        }
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', run);
        } else {
            run();
        }
    }());

    /* Cancelamento com motivo obrigatório (popup) — funciona em qualquer
       página do plugin, tanto para usuário comum quanto para o aprovador. */
    document.addEventListener('click', function (e) {
        var btn = e.target.closest && e.target.closest('[data-cb-cancel]');
        if (!btn) { return; }
        e.preventDefault();
        openCancelModal(btn.getAttribute('data-id'), btn.getAttribute('data-bform'), btn.getAttribute('data-csrf'));
    });

    function openCancelModal(id, bform, csrf) {
        var overlay = document.createElement('div');
        overlay.className = 'reservafrota-modal';
        overlay.innerHTML =
            '<div class="reservafrota-modal__backdrop" data-x></div>'
          + '<div class="reservafrota-modal__dialog" role="dialog" aria-modal="true">'
          + '  <div class="reservafrota-modal__head"><h3>Cancelar agendamento</h3>'
          + '    <button type="button" class="reservafrota-modal__close" data-x aria-label="Fechar"><i class="ti ti-x"></i></button></div>'
          + '  <div class="reservafrota-modal__body">'
          + '    <label class="form-label" for="cb-cancel-reason"><b>Por que está cancelando?</b> (obrigatório)</label>'
          + '    <textarea id="cb-cancel-reason" class="form-control" rows="3" placeholder="Ex.: a viagem foi desmarcada"></textarea>'
          + '    <p class="reservafrota-cancel-err" hidden>Digite o motivo para continuar.</p>'
          + '    <button type="button" class="reservafrota-submit reservafrota-cancel-confirm"><i class="ti ti-ban"></i> Confirmar cancelamento</button>'
          + '  </div></div>';
        document.body.appendChild(overlay);
        document.body.classList.add('reservafrota-modal-open');
        var ta = overlay.querySelector('#cb-cancel-reason');
        ta.focus();

        function close() {
            overlay.remove();
            document.body.classList.remove('reservafrota-modal-open');
            document.removeEventListener('keydown', onKey);
        }
        function onKey(ev) { if (ev.key === 'Escape') { close(); } }
        document.addEventListener('keydown', onKey);
        overlay.querySelectorAll('[data-x]').forEach(function (el) { el.addEventListener('click', close); });

        overlay.querySelector('.reservafrota-cancel-confirm').addEventListener('click', function () {
            var reason = ta.value.trim();
            if (!reason) { overlay.querySelector('.reservafrota-cancel-err').hidden = false; ta.focus(); return; }
            var form = document.createElement('form');
            form.method = 'post';
            form.action = bform;
            form.innerHTML =
                '<input type="hidden" name="id" value="' + id + '">'
              + '<input type="hidden" name="cancel" value="1">'
              + '<input type="hidden" name="_glpi_csrf_token" value="' + (csrf || '') + '">';
            var r = document.createElement('input');
            r.type = 'hidden'; r.name = 'cancel_reason'; r.value = reason;
            form.appendChild(r);
            document.body.appendChild(form);
            form.submit();
        });
    }

    /* Kanban dos agendamentos: arrastar um card para outra coluna troca o
       status, mas sempre pelo mesmo pop-up de confirmação usado pelos botões
       "Aprovar" e "Cancelar" (não duplica lógica nem pula a confirmação).
       Delegado no documento porque a lista é recarregada via AJAX. */
    (function bookingKanban() {
        // De onde -> para onde é permitido arrastar (status: pendente=1, aprovado=2).
        var ALLOWED_TARGETS = { '1': ['approved', 'rejected'], '2': ['rejected'] };
        var dragged = null;
        var draggedStatus = null;

        document.addEventListener('dragstart', function (e) {
            var card = e.target.closest && e.target.closest('.reservafrota-blist-item[draggable="true"]');
            if (!card) { return; }
            dragged = card;
            draggedStatus = card.getAttribute('data-status');
            card.classList.add('is-dragging');
            if (e.dataTransfer) {
                e.dataTransfer.effectAllowed = 'move';
                e.dataTransfer.setData('text/plain', card.getAttribute('data-id') || '');
            }
            var targets = ALLOWED_TARGETS[draggedStatus] || [];
            document.querySelectorAll('.reservafrota-blist-col[data-status-group]').forEach(function (col) {
                if (targets.indexOf(col.getAttribute('data-status-group')) !== -1) {
                    col.classList.add('is-drop-ok');
                }
            });
        });

        document.addEventListener('dragend', function () {
            if (dragged) { dragged.classList.remove('is-dragging'); }
            document.querySelectorAll('.reservafrota-blist-col').forEach(function (col) {
                col.classList.remove('is-drop-ok', 'is-drop-over');
            });
            dragged = null;
            draggedStatus = null;
        });

        document.addEventListener('dragover', function (e) {
            if (!dragged) { return; }
            var col = e.target.closest && e.target.closest('.reservafrota-blist-col[data-status-group]');
            if (!col) { return; }
            var targets = ALLOWED_TARGETS[draggedStatus] || [];
            if (targets.indexOf(col.getAttribute('data-status-group')) === -1) { return; }
            e.preventDefault(); // só permite o drop quando o destino é válido
            if (e.dataTransfer) { e.dataTransfer.dropEffect = 'move'; }
            col.classList.add('is-drop-over');
        });

        document.addEventListener('dragleave', function (e) {
            var col = e.target.closest && e.target.closest('.reservafrota-blist-col[data-status-group]');
            if (col) { col.classList.remove('is-drop-over'); }
        });

        document.addEventListener('drop', function (e) {
            if (!dragged) { return; }
            var col = e.target.closest && e.target.closest('.reservafrota-blist-col[data-status-group]');
            if (!col) { return; }
            var targetGroup = col.getAttribute('data-status-group');
            var targets = ALLOWED_TARGETS[draggedStatus] || [];
            if (targets.indexOf(targetGroup) === -1) { return; }
            e.preventDefault();
            col.classList.remove('is-drop-over');

            // Reaproveita os mesmos pop-ups de confirmação usados ao abrir o
            // agendamento (Confirmar com escolha de carro / Cancelar).
            var id = dragged.getAttribute('data-id');
            var bform = dragged.getAttribute('data-bform');
            var csrf = dragged.getAttribute('data-csrf');
            var canApprove = dragged.getAttribute('data-can-approve') === '1';
            if (!id || !bform) { return; }

            if (targetGroup === 'approved' && canApprove) {
                cbOpenApprove(id, bform, csrf);
            } else if (targetGroup === 'rejected' && dragged.getAttribute('data-can-cancel') === '1') {
                openCancelModal(id, bform, csrf);
            }
        });
    }());

    /* Notificação de pendentes para o aprovador — usa a URL exposta no HTML
       da página (atributo data-cb-pending-url), sem adivinhar caminho. */
    (function notifyPending() {
        function run() {
            var el = document.querySelector('[data-cb-pending-url]');
            if (!el) { return; }
            var url = el.getAttribute('data-cb-pending-url');
            try {
                fetch(url, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin'
                }).then(function (r) { return r.ok ? r.json() : null; })
                  .then(function (d) { if (d && d.count) { showToast(d.count, d.url); } })
                  .catch(function () {});
            } catch (e) {}
        }

        function showToast(count, url) {
            var t = document.createElement('div');
            t.className = 'reservafrota-toast';
            var label = count === 1
                ? '1 agendamento pendente aguardando aprovação'
                : count + ' agendamentos pendentes aguardando aprovação';
            t.innerHTML = '<i class="ti ti-bell-ringing"></i>'
                + '<a href="' + url + '">' + label + '</a>'
                + '<button type="button" class="reservafrota-toast__close" aria-label="Fechar"><i class="ti ti-x"></i></button>';
            document.body.appendChild(t);
            t.querySelector('.reservafrota-toast__close').addEventListener('click', function () { t.remove(); });
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', run);
        } else {
            run();
        }
    }());


    /* Paleta automotiva curada (azul, verde, vermelho, roxo, âmbar, ciano). */
    var CAR_PALETTE = [212, 152, 8, 278, 33, 190];

    /* hsl -> hex (sem depender de CSS para colorir o SVG do carro) */
    function hslHex(h, s, l) {
        s /= 100; l /= 100;
        const k = function (n) { return (n + h / 30) % 12; };
        const a = s * Math.min(l, 1 - l);
        const f = function (n) {
            const c = l - a * Math.max(-1, Math.min(k(n) - 3, Math.min(9 - k(n), 1)));
            return Math.round(255 * c).toString(16).padStart(2, '0');
        };
        return '#' + f(0) + f(8) + f(4);
    }

    /* Ilustração vetorial do carro, colorida conforme o "hue". */
    function carSvg(hue, uid) {
        const body1 = hslHex(hue, 72, 58);
        const body2 = hslHex(hue, 78, 44);
        const dark  = hslHex(hue, 55, 30);
        const win1  = hslHex(205, 85, 90);
        const win2  = hslHex(208, 75, 72);
        return ''
            + '<svg viewBox="0 0 260 140" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Carro">'
            + '<defs>'
            + '<linearGradient id="b' + uid + '" x1="0" y1="0" x2="0" y2="1">'
            + '<stop offset="0" stop-color="' + body1 + '"/><stop offset="1" stop-color="' + body2 + '"/>'
            + '</linearGradient>'
            + '<linearGradient id="g' + uid + '" x1="0" y1="0" x2="0" y2="1">'
            + '<stop offset="0" stop-color="' + win1 + '"/><stop offset="1" stop-color="' + win2 + '"/>'
            + '</linearGradient>'
            + '<radialGradient id="s' + uid + '" cx="0.5" cy="0.5" r="0.5">'
            + '<stop offset="0" stop-color="rgba(15,23,42,0.28)"/><stop offset="1" stop-color="rgba(15,23,42,0)"/>'
            + '</radialGradient></defs>'
            + '<ellipse cx="130" cy="120" rx="98" ry="12" fill="url(#s' + uid + ')"/>'
            + '<path d="M22 100 L22 84 Q24 74 40 72 L70 70 Q80 70 86 60 L98 45 Q101 41 110 41 '
            +   'L166 41 Q177 41 183 49 L195 67 Q198 71 207 71 L224 72 Q238 74 238 86 L238 100 Z" '
            +   'fill="url(#b' + uid + ')" stroke="' + dark + '" stroke-width="1.5"/>'
            + '<path d="M40 73 L70 71 Q80 71 86 61 L98 46 Q101 42 110 42 L150 42 L150 47 L112 47 '
            +   'Q104 47 101 51 L90 66 Q84 74 72 74 L42 76 Z" fill="rgba(255,255,255,0.22)"/>'
            + '<path d="M96 58 L106 47 L131 47 L131 58 Z" fill="url(#g' + uid + ')"/>'
            + '<path d="M137 47 L163 47 Q172 47 176 54 L179 58 L137 58 Z" fill="url(#g' + uid + ')"/>'
            + '<rect x="232" y="78" width="8" height="7" rx="2" fill="#fca5a5"/>'
            + '<path d="M22 86 q-3 1 -3 5 l0 4 q0 2 4 2 l4 0 0 -4 q0 -4 -5 -11 Z" fill="#fde68a"/>'
            + '<line x1="40" y1="100" x2="238" y2="100" stroke="' + dark + '" stroke-width="2" opacity="0.5"/>'
            + '<g><circle cx="74" cy="100" r="17" fill="#1f2937"/><circle cx="74" cy="100" r="9.5" fill="#e5e7eb"/>'
            +   '<circle cx="74" cy="100" r="3.5" fill="#9ca3af"/></g>'
            + '<g><circle cx="190" cy="100" r="17" fill="#1f2937"/><circle cx="190" cy="100" r="9.5" fill="#e5e7eb"/>'
            +   '<circle cx="190" cy="100" r="3.5" fill="#9ca3af"/></g>'
            + '</svg>';
    }

    function init() {
        const root = document.getElementById('reservafrota-agenda');
        if (!root) { return; }

        const ajaxUrl = root.dataset.ajax;
        const bformUrl = root.dataset.bform;
        const isHelpdesk = root.dataset.helpdesk === '1';
        const cardsEl = document.getElementById('reservafrota-cards');
        const dateInput = document.getElementById('reservafrota-date');
        const formDate = document.getElementById('reservafrota-form-date');
        const carSelect = document.getElementById('cb-car');
        const depInput = document.getElementById('cb-dep');
        const conflictBox = document.getElementById('reservafrota-conflict');
        const boardTitle = document.getElementById('reservafrota-board-title');

        let current = [];

        function escapeHtml(str) {
            return String(str == null ? '' : str)
                .replace(/&/g, '&amp;').replace(/</g, '&lt;')
                .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }

        function fmtTime(dt) {
            if (!dt) { return ''; }
            const parts = dt.split(' ');
            const d = parts[0] ? parts[0].split('-').reverse().join('/') : '';
            const t = parts[1] ? parts[1].substring(0, 5) : '';
            return (d + ' ' + t).trim();
        }

        function statusMeta(status) {
            if (status === 'approved') { return { cls: 'is-approved', label: 'Em uso', icon: 'ti-steering-wheel' }; }
            if (status === 'pending') { return { cls: 'is-pending', label: 'Pendente', icon: 'ti-clock' }; }
            return { cls: 'is-free', label: 'Livre', icon: 'ti-circle-check' };
        }

        function renderCar(car, index) {
            const meta = statusMeta(car.status);
            const hue = CAR_PALETTE[car.id % CAR_PALETTE.length];
            const visual = car.picture
                ? '<img src="' + escapeHtml(car.picture) + '" alt="' + escapeHtml(car.name) + '">'
                : carSvg(hue, 'c' + car.id);
            const stageClass = car.picture ? 'reservafrota-card__stage has-photo' : 'reservafrota-card__stage';
            const pulse = car.status === 'pending' ? '<span class="pulse"></span>' : '';

            let detail;
            if (!car.bookings || !car.bookings.length) {
                detail = '<p class="reservafrota-card__free"><i class="ti ti-circle-check"></i>Disponível neste dia</p>';
            } else {
                detail = '<ul class="reservafrota-uses">';
                car.bookings.forEach(function (b) {
                    const period = b.arrival ? fmtTime(b.departure) + ' → ' + fmtTime(b.arrival) : fmtTime(b.departure);
                    const inner = '<span class="mini-chip s-' + b.status + '">' + escapeHtml(b.status_label) + '</span>'
                        + '<div><div class="user">' + escapeHtml(b.user) + '</div>'
                        + '<div class="time">' + escapeHtml(period)
                        + (b.destination ? ' · ' + escapeHtml(b.destination) : '') + '</div></div>';
                    if (isHelpdesk || !bformUrl) {
                        // Funcionário (interface simplificada): só exibe, sem link.
                        detail += '<li>' + inner + '</li>';
                    } else {
                        detail += '<a class="reservafrota-use" href="' + bformUrl + '?id=' + b.id + '">'
                            + inner + '<i class="ti ti-chevron-right reservafrota-use__arrow"></i></a>';
                    }
                });
                detail += '</ul>';
            }

            return ''
                + '<article class="reservafrota-card ' + meta.cls + '" style="--d:' + (index * 55) + 'ms">'
                + '  <div class="' + stageClass + '">'
                + '    <span class="reservafrota-card__status">' + pulse + '<i class="ti ' + meta.icon + '"></i>' + meta.label + '</span>'
                + '    <div class="reservafrota-card__car">' + visual + '</div>'
                + '  </div>'
                + '  <div class="reservafrota-card__body">'
                + '    <div class="reservafrota-card__head">'
                + '      <p class="reservafrota-card__name">' + escapeHtml(car.name) + '</p>'
                + (car.model_year ? '<span class="reservafrota-card__year">' + escapeHtml(car.model_year) + '</span>' : '')
                + '    </div>'
                + (car.plate ? '<span class="reservafrota-card__plate">' + escapeHtml(car.plate) + '</span>' : '')
                + detail
                + '  </div>'
                + '</article>';
        }

        function render() {
            if (!current.length) {
                cardsEl.innerHTML = '<div class="reservafrota-loading">Nenhum carro ativo cadastrado.</div>';
                return;
            }
            cardsEl.innerHTML = current.map(renderCar).join('');
            annotateSelect();
            checkConflict();
        }

        function annotateSelect() {
            if (!carSelect) { return; }
            const byId = {};
            current.forEach(function (c) { byId[c.id] = c; });
            Array.prototype.forEach.call(carSelect.options, function (opt) {
                if (!opt.value) { return; }
                const car = byId[parseInt(opt.value, 10)];
                const base = opt.textContent.replace(/\s+·\s+(Livre|Em uso|Pendente)$/, '');
                if (car && car.status !== 'free') {
                    opt.textContent = base + ' · ' + (car.status === 'approved' ? 'Em uso' : 'Pendente');
                } else {
                    opt.textContent = base;
                }
            });
        }

        function checkConflict() {
            if (!carSelect || !conflictBox) { return; }
            const id = parseInt(carSelect.value, 10);
            const car = current.find(function (c) { return c.id === id; });
            if (car && car.status !== 'free' && car.bookings.length) {
                const names = car.bookings.map(function (b) { return b.user; }).join(', ');
                conflictBox.querySelector('span').textContent =
                    'Este carro já tem agendamento neste dia (' + names + '). Você ainda pode solicitar — o administrador decide.';
                conflictBox.hidden = false;
            } else {
                conflictBox.hidden = true;
            }
        }

        function load(date) {
            cardsEl.innerHTML = '<div class="reservafrota-loading"><span class="reservafrota-spinner"></span>Carregando frota…</div>';
            fetch(ajaxUrl + '?date=' + encodeURIComponent(date), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin'
            })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    current = data.cars || [];
                    render();
                    if (boardTitle) {
                        boardTitle.textContent = 'Carros em ' + date.split('-').reverse().join('/');
                    }
                })
                .catch(function () {
                    cardsEl.innerHTML = '<div class="reservafrota-loading">Não foi possível carregar a frota. Recarregue a página.</div>';
                });
        }

        function changeDate(date) {
            if (dateInput) { dateInput.value = date; }
            if (formDate) { formDate.value = date; }
            if (depInput && depInput.value) {
                depInput.value = date + 'T' + depInput.value.substring(11);
            } else if (depInput) {
                depInput.value = date + 'T08:00';
            }
            try {
                const url = new URL(window.location.href);
                url.searchParams.set('date', date);
                window.history.replaceState({}, '', url);
            } catch (e) { /* ignore */ }
            load(date);
        }

        if (dateInput) {
            dateInput.addEventListener('change', function () {
                if (dateInput.value) { changeDate(dateInput.value); }
            });
        }
        root.querySelectorAll('.reservafrota-nav').forEach(function (btn) {
            btn.addEventListener('click', function () {
                const step = parseInt(btn.dataset.step, 10) || 0;
                const base = (dateInput && dateInput.value) || root.dataset.date;
                const d = new Date(base + 'T00:00:00');
                d.setDate(d.getDate() + step);
                changeDate(d.toISOString().substring(0, 10));
            });
        });
        const todayBtn = root.querySelector('.reservafrota-today');
        if (todayBtn) {
            todayBtn.addEventListener('click', function () {
                changeDate(new Date().toISOString().substring(0, 10));
            });
        }
        if (carSelect) { carSelect.addEventListener('change', checkConflict); }

        load(root.dataset.date);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    /* Auto-scroll para âncora após marcar chegada */
    (function scrollToAnchor() {
        function run() {
            if (window.location.hash === '#reservafrota-arrived-section') {
                var el = document.getElementById('reservafrota-arrived-section');
                if (el) {
                    setTimeout(function () { el.scrollIntoView({ behavior: 'smooth', block: 'start' }); }, 150);
                }
            }
        }
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', run);
        } else {
            run();
        }
    }());
}());
