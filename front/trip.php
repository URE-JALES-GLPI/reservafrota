<?php

use GlpiPlugin\Reservafrota\Booking;
use GlpiPlugin\Reservafrota\Car;
use GlpiPlugin\Reservafrota\Driver;
use GlpiPlugin\Reservafrota\SheetSync;
use Glpi\Application\View\TemplateRenderer;

// Histórico de uma viagem: saída, paradas e chegada. Somente gestor.
Session::checkRight(Booking::$rightname, Booking::APPROVE);

$booking = new Booking();
if (!$booking->getFromDB((int) ($_GET['id'] ?? 0))) {
    Session::addMessageAfterRedirect(__('Viagem não encontrada.', 'reservafrota'), false, ERROR);
    Html::redirect(plugin_reservafrota_webdir() . '/front/calendar.php');
}
$fields = $booking->fields;

$car = new Car();
$carLabel = '';
if ($car->getFromDB((int) ($fields['plugin_reservafrota_cars_id'] ?? 0))) {
    $plate = trim((string) ($car->fields['plate'] ?? ''));
    $carLabel = trim((string) ($car->fields['name'] ?? '')) . ($plate !== '' ? ' - ' . $plate : '');
}

$driverName = trim((string) ($fields['driver'] ?? ''));
$driver = new Driver();
if ($driver->getFromDB((int) ($fields['plugin_reservafrota_drivers_id'] ?? 0))) {
    $driverName = (string) ($driver->fields['name'] ?? $driverName);
}

$changes = [];
try {
    foreach (\Log::getHistoryData($booking, 0, 50) as $h) {
        $changes[] = [
            'date'   => (string) ($h['date_mod'] ?? ''),
            'user'   => (string) ($h['user_name'] ?? ''),
            'change' => (string) ($h['change'] ?? ''),
        ];
    }
} catch (\Throwable $e) {
    $changes = [];
}

$used_help = false;
if (Session::getCurrentInterface() === 'helpdesk' && method_exists(Html::class, 'helpHeader')) {
    Html::helpHeader(__('Histórico da viagem', 'reservafrota'));
    $used_help = true;
} else {
    Html::header(
        __('Histórico da viagem', 'reservafrota'),
        $_SERVER['PHP_SELF'],
        'tools',
        Booking::class,
        'calendar'
    );
}

TemplateRenderer::getInstance()->display('@reservafrota/trip.html.twig', [
    'web_dir'      => plugin_reservafrota_webdir(),
    'b'            => $fields,
    'code'         => trim((string) ($fields['request_code'] ?? '')) ?: (string) $fields['id'],
    'status_label' => Booking::getStatusName((int) ($fields['status'] ?? 0)),
    'requester'    => \getUserName((int) ($fields['users_id'] ?? 0)),
    'car_label'    => $carLabel,
    'driver_name'  => $driverName,
    'trip'         => SheetSync::getTripTimeline($fields),
    'rejected'     => SheetSync::getRejectedRows((int) $fields['id']),
    'changes'      => $changes,
]);

if ($used_help) {
    Html::helpFooter();
} else {
    Html::footer();
}
