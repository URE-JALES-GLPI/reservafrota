<?php

use GlpiPlugin\Reservafrota\Booking;

Session::checkRight(Booking::$rightname, READ);

header('Content-Type: application/json; charset=utf-8');

$departure = $_GET['departure'] ?? ($_GET['date_departure'] ?? '');
$arrival   = $_GET['arrival'] ?? ($_GET['date_arrival'] ?? '');

if (empty($departure)) {
    echo json_encode(['cars' => [], 'error' => 'departure_required']);
    exit;
}

// Normaliza formato T -> espaço
$departure = str_replace('T', ' ', trim($departure));
if (strlen($departure) === 16) {
    $departure .= ':00';
}
if (!empty($arrival)) {
    $arrival = str_replace('T', ' ', trim($arrival));
    if (strlen($arrival) === 16) {
        $arrival .= ':00';
    }
} else {
    $arrival = null;
}

// Valida datas
if (!strtotime($departure)) {
    echo json_encode(['cars' => [], 'error' => 'invalid_departure']);
    exit;
}
if ($arrival !== null && !strtotime($arrival)) {
    $arrival = null;
}

$exclude = (int) ($_GET['exclude'] ?? 0);
$cars = Booking::getCarAvailabilityForSlot($departure, $arrival, $exclude);

echo json_encode([
    'departure' => $departure,
    'arrival'   => $arrival,
    'cars'      => $cars,
]);
