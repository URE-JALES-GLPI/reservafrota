<?php

use GlpiPlugin\Reservafrota\Booking;
use GlpiPlugin\Reservafrota\CalendarFeed;

Session::checkRight(Booking::$rightname, READ);

header('Content-Type: application/json; charset=utf-8');

$uid = (int)Session::getLoginUserID();
if ($uid <= 0) {
    echo json_encode(['success' => false, 'error' => 'login']);
    exit;
}

$action = (string)($_GET['action'] ?? $_POST['action'] ?? 'get');
if ($action === 'rotate' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'method']);
    exit;
}
if ($action === 'rotate') {
    // Invalida o link antigo (caso tenha vazado) e gera um novo.
    CalendarFeed::revokeToken($uid);
}
$token = CalendarFeed::getOrCreateToken($uid);
if ($token === '') {
    echo json_encode(['success' => false, 'error' => 'notable']);
    exit;
}

echo json_encode([
    'success' => true,
    'path'    => plugin_reservafrota_webdir() . '/front/ical.php?token=' . $token,
]);
