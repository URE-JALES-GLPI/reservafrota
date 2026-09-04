<?php

use GlpiPlugin\Reservafrota\Booking;

Session::checkRight(Booking::$rightname, READ);

$id = (int) ($_GET['id'] ?? 0);
$booking = new Booking();
if ($id <= 0 || !$booking->getFromDB($id)) {
    Html::displayErrorAndDie(__('Agendamento não encontrado.', 'reservafrota'));
}

// Dono do pedido ou aprovador podem baixar a folha.
if (!Booking::canApprove() && (int) $booking->fields['users_id'] !== (int) Session::getLoginUserID()) {
    Html::displayRightError();
}

$sheet = (string) ($booking->fields['arrival_sheet'] ?? '');
$path  = GLPI_DOC_DIR . '/_plugins/reservafrota/' . basename($sheet);

if ($sheet === '' || !is_file($path)) {
    Html::displayErrorAndDie(__('Nenhuma folha anexada para este agendamento.', 'reservafrota'));
}

$type = function_exists('mime_content_type') ? (mime_content_type($path) ?: 'application/octet-stream') : 'application/octet-stream';

$disposition = !empty($_GET['inline']) ? 'inline' : 'attachment';

while (ob_get_level() > 0) {
    ob_end_clean();
}
header('Content-Type: ' . $type);
header('Content-Disposition: ' . $disposition . '; filename="folha_agendamento_' . $id . '.' . pathinfo($path, PATHINFO_EXTENSION) . '"');
header('Content-Length: ' . filesize($path));
header('Cache-Control: no-cache, must-revalidate');
readfile($path);
exit;
