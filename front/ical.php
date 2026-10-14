<?php

use GlpiPlugin\Reservafrota\CalendarFeed;

// Feed ICS para assinatura no Google Agenda / Outlook / Apple.
// Acesso público via firewall NO_CHECK: o token na URL é a credencial.
$token = trim((string)($_GET['token'] ?? ''));
$uid = $token !== '' ? CalendarFeed::findUserByToken($token) : 0;
if ($uid <= 0 && (int)Session::getLoginUserID() > 0) {
    // Usuário logado sem token: serve o próprio feed e cria o token.
    $uid = (int)Session::getLoginUserID();
}
if ($uid <= 0) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Acesso negado.';
    exit;
}

$events = CalendarFeed::getEventsForUser($uid);
$ics = CalendarFeed::buildIcs($events, __('Reserva de Frota', 'reservafrota'));

while (ob_get_level() > 0) {
    ob_end_clean();
}
header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: inline; filename="reservafrota.ics"');
header('Cache-Control: no-cache, must-revalidate');
header('Content-Length: ' . strlen($ics));
echo $ics;
exit;
