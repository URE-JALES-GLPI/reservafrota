<?php

use GlpiPlugin\Reservafrota\Booking;
use GlpiPlugin\Reservafrota\Driver;

Session::checkRight(Booking::$rightname, Booking::APPROVE);

header('Content-Type: application/json; charset=utf-8');

$id = (int) ($_GET['id'] ?? 0);
$booking = new Booking();
if ($id <= 0 || !$booking->getFromDB($id)) {
    http_response_code(404);
    echo json_encode(['error' => 'not_found']);
    exit;
}

$departure = (string) $booking->fields['date_departure'];
$arrival   = $booking->fields['date_arrival'] ?: null;

echo json_encode([
    'id'        => $id,
    'departure' => $departure,
    'arrival'   => $arrival,
    'car_id'    => (int) $booking->fields['plugin_reservafrota_cars_id'],
    'cars'      => Booking::getCarAvailabilityForSlot($departure, $arrival, $id),
    'driver_id' => (int) ($booking->fields['plugin_reservafrota_drivers_id'] ?? 0),
    'drivers'   => array_values(array_map(function ($d) {
        return ['id' => (int) $d['id'], 'name' => $d['name'], 'phone' => $d['phone'] ?? '', 'cnh' => $d['cnh'] ?? ''];
    }, Driver::getActiveDrivers())),
]);
