<?php

use GlpiPlugin\Reservafrota\Booking;

// Endpoint de leitura: exige permissão de leitura em agendamentos.
Session::checkRight(Booking::$rightname, READ);

header('Content-Type: application/json; charset=UTF-8');

$date = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $date = date('Y-m-d');
}

echo json_encode([
    'date' => $date,
    'cars' => Booking::getCarsStatusForDate($date),
], JSON_UNESCAPED_UNICODE);
