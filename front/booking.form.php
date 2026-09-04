<?php

use GlpiPlugin\Reservafrota\Booking;
use GlpiPlugin\Reservafrota\Car;
use Glpi\Application\View\TemplateRenderer;

$booking = new Booking();

if (isset($_POST['add'])) {
    // Qualquer usuário com CREATE pode solicitar um agendamento.
    $booking->check(-1, CREATE, $_POST);
    $newID = $booking->add($_POST);

    // Repetição: cria o mesmo agendamento nos demais dias da semana marcados.
    if ($newID && !empty($_POST['_repeat_weekdays'])) {
        $wd = is_array($_POST['_repeat_weekdays'])
            ? $_POST['_repeat_weekdays']
            : explode(',', (string) $_POST['_repeat_weekdays']);
        $n = Booking::createWeekRepeats($_POST, $wd);
        if ($n > 0) {
            Session::addMessageAfterRedirect(
                sprintf(__('Mais %d agendamento(s) criados nos dias da semana selecionados.', 'reservafrota'), $n),
                true
            );
        }
    }

    // Volta para o calendário quando o pedido veio do popup do calendário.
    if (!empty($_POST['_from_calendar'])) {
        $m = $_POST['_calendar_month'] ?? '';
        $q = preg_match('/^\d{4}-\d{2}$/', (string) $m) ? ('?month=' . $m) : '';
        Html::redirect(Plugin::getWebDir('reservafrota') . '/front/calendar.php' . $q);
    }

    // Volta para a agenda quando o pedido veio de lá.
    if (!empty($_POST['_from_agenda'])) {
        Html::redirect(Plugin::getWebDir('reservafrota') . '/front/agenda.php'
            . (isset($_POST['_agenda_date']) ? '?date=' . urlencode($_POST['_agenda_date']) : ''));
    }
    if ($newID && ($_SESSION['glpibackcreated'] ?? false)) {
        Html::redirect(Booking::getFormURL() . '?id=' . $newID);
    }
    Html::back();

} elseif (isset($_POST['approve'])) {
    // Aprovação: exige o direito específico. O carro é designado agora,
    // pelo gestor (o solicitante não escolhe carro ao pedir).
    Session::checkRight(Booking::$rightname, Booking::APPROVE);
    if ($booking->getFromDB((int) $_POST['id'])) {
        $carId = !empty($_POST['plugin_reservafrota_cars_id']) ? (int) $_POST['plugin_reservafrota_cars_id'] : null;
        $booking->approve($_POST['comment_validation'] ?? '', $carId);
    }
    Html::back();

} elseif (isset($_POST['reject'])) {
    Session::checkRight(Booking::$rightname, Booking::APPROVE);
    if ($booking->getFromDB((int) $_POST['id'])) {
        $booking->reject($_POST['comment_validation'] ?? '');
    }
    Html::back();

} elseif (isset($_POST['cancel'])) {
    // Cancelamento exige motivo. O dono pode cancelar o próprio pedido;
    // o aprovador pode cancelar qualquer um (validado em canCancel()).
    if ($booking->getFromDB((int) $_POST['id'])) {
        $booking->cancel((string) ($_POST['cancel_reason'] ?? ''));
    }
    Html::back();

} elseif (isset($_POST['arrive'])) {
    // Confirmação de chegada do carro (somente aprovador). A folha de
    // agendamento é opcional, mas o checkbox de confirmação é obrigatório.
    Session::checkRight(Booking::$rightname, Booking::APPROVE);
    if (empty($_POST['confirm_ok'])) {
        Session::addMessageAfterRedirect(__('Você precisa marcar a confirmação para registrar a chegada.', 'reservafrota'), false, ERROR);
        Html::back();
    }
    if ($booking->getFromDB((int) $_POST['id'])) {
        $sheet = null;
        if (!empty($_FILES['arrival_sheet']['name'])) {
            $sheet = Booking::storeArrivalSheet((int) $_POST['id'], $_FILES['arrival_sheet']);
        }
        $when = !empty($_POST['returned_at']) ? str_replace('T', ' ', (string) $_POST['returned_at']) : null;
        $obs  = isset($_POST['arrival_obs']) ? trim((string) $_POST['arrival_obs']) : null;
        $km   = (isset($_POST['km_final']) && $_POST['km_final'] !== '') ? (int) $_POST['km_final'] : null;
        $booking->markReturned($sheet, $when, $obs, $km);
    }
    Html::redirect(Plugin::getWebDir('reservafrota') . '/front/calendar.php#reservafrota-arrived-section');

} elseif (isset($_POST['update_obs'])) {
    // Editar / remover a observação de viagem (somente aprovador).
    Session::checkRight(Booking::$rightname, Booking::APPROVE);
    if ($booking->getFromDB((int) $_POST['id'])) {
        $obs = isset($_POST['arrival_obs']) ? trim((string) $_POST['arrival_obs']) : '';
        $booking->update(['id' => (int) $_POST['id'], 'arrival_obs' => $obs]);
        Session::addMessageAfterRedirect(
            $obs === '' ? __('Observação removida.', 'reservafrota') : __('Observação atualizada.', 'reservafrota'),
            false,
            INFO
        );
    }
    Html::back();

} elseif (isset($_POST['upload_sheet'])) {
    // Adiciona folha a um agendamento já marcado como chegada (sem folha ainda).
    Session::checkRight(Booking::$rightname, Booking::APPROVE);
    if (empty($_POST['confirm_ok'])) {
        Session::addMessageAfterRedirect(__('Você precisa marcar a confirmação para enviar a folha.', 'reservafrota'), false, ERROR);
        Html::back();
    }
    if ($booking->getFromDB((int) $_POST['id'])) {
        if ((int) ($booking->fields['status'] ?? 0) !== Booking::STATUS_ARRIVED) {
            Session::addMessageAfterRedirect(__('A folha só pode ser anexada após marcar o retorno.', 'reservafrota'), false, ERROR);
            Html::back();
        }
        if (!empty($_FILES['arrival_sheet']['name'])) {
            $sheet = Booking::storeArrivalSheet((int) $_POST['id'], $_FILES['arrival_sheet']);
            if ($sheet !== null) {
                $booking->update(['id' => (int) $_POST['id'], 'arrival_sheet' => $sheet]);
            }
        }
    }
    Html::back();

} elseif (isset($_POST['update'])) {
    $booking->check($_POST['id'], UPDATE);
    $st = (int) ($booking->fields['status'] ?? 0);
    if (!in_array($st, [Booking::STATUS_PENDING, Booking::STATUS_APPROVED], true)) {
        Session::addMessageAfterRedirect(__('Só agendamentos pendentes ou aprovados podem ser editados.', 'reservafrota'), false, ERROR);
        Html::back();
    }
    // O carro nunca é alterado pela edição.
    unset($_POST['plugin_reservafrota_cars_id']);
    // Editar um agendamento aprovado faz ele voltar para pendente.
    if ($st === Booking::STATUS_APPROVED) {
        $_POST['status']            = Booking::STATUS_PENDING;
        $_POST['users_id_approver'] = 0;
        $_POST['date_validation']   = null;
        Session::addMessageAfterRedirect(__('O agendamento foi editado e voltou para Pendente.', 'reservafrota'), false, WARNING);
    }
    $booking->update($_POST);
    
    // Volta para o calendário quando o pedido veio do popup do calendário.
    if (!empty($_POST['_from_calendar'])) {
        $m = $_POST['_calendar_month'] ?? '';
        $q = preg_match('/^\d{4}-\d{2}$/', (string) $m) ? ('?month=' . $m) : '';
        Html::redirect(Plugin::getWebDir('reservafrota') . '/front/calendar.php' . $q);
    }
    Html::back();

} elseif (isset($_POST['delete'])) {
    $booking->check($_POST['id'], DELETE);
    if ((int) ($booking->fields['status'] ?? 0) !== Booking::STATUS_PENDING) {
        Session::addMessageAfterRedirect(__('Apenas agendamentos pendentes podem ser excluídos.', 'reservafrota'), false, ERROR);
        Html::back();
    }
    $booking->delete($_POST);
    $booking->redirectToList();

} elseif (isset($_POST['restore'])) {
    $booking->check($_POST['id'], DELETE);
    $booking->restore($_POST);
    $booking->redirectToList();

} elseif (isset($_POST['purge'])) {
    $booking->check($_POST['id'], PURGE);
    if ((int) ($booking->fields['status'] ?? 0) !== Booking::STATUS_PENDING) {
        Session::addMessageAfterRedirect(__('Apenas agendamentos pendentes podem ser excluídos.', 'reservafrota'), false, ERROR);
        Html::back();
    }
    $booking->delete($_POST, 1);
    $booking->redirectToList();

} else {
    $ID = isset($_GET['id']) ? (int) $_GET['id'] : 0;

    Session::checkRight(Booking::$rightname, READ);

    Html::header(
        Booking::getTypeName(2),
        $_SERVER['PHP_SELF'],
        'tools',
        Booking::class,
        'calendar'
    );

    $booking->display(['id' => $ID]);

    Html::footer();
}
