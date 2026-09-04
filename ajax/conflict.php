<?php

use GlpiPlugin\Reservafrota\Booking;

Session::checkRight(Booking::$rightname, READ);

header('Content-Type: application/json; charset=utf-8');

$car  = (int) ($_GET['car'] ?? 0);
$date = (string) ($_GET['date'] ?? '');

if ($car <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    echo json_encode(['items' => []]);
    exit;
}

$items = [];
foreach (Booking::getBookingsForCarOnDate($car, $date) as $row) {
    $items[] = [
        'start'  => substr((string) $row['date_departure'], 11, 5),
        'end'    => !empty($row['date_arrival']) ? substr((string) $row['date_arrival'], 11, 5) : '',
        'user'   => $row['users_id'] ? \getUserName((int) $row['users_id']) : '',
        'status' => (int) $row['status'],
        'start_full' => (string) $row['date_departure'],
        'end_full'   => (string) ($row['date_arrival'] ?? ''),
    ];
}

echo json_encode(['items' => $items]);
