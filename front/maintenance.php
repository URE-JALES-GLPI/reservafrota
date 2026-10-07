<?php

use GlpiPlugin\Reservafrota\Booking;
use GlpiPlugin\Reservafrota\Car;
use GlpiPlugin\Reservafrota\Maintenance;
use GlpiPlugin\Reservafrota\MaintenancePlan;
use Glpi\Application\View\TemplateRenderer;

Session::checkRight(Car::$rightname, READ);

$carFilter = isset($_GET['cars_id']) ? (int) $_GET['cars_id'] : 0;

$used_help = false;
if (Session::getCurrentInterface() === 'helpdesk' && method_exists(Html::class, 'helpHeader')) {
    Html::helpHeader(__('Manutenções', 'reservafrota'));
    $used_help = true;
} else {
    Html::header(
        __('Manutenções', 'reservafrota'),
        $_SERVER['PHP_SELF'],
        'tools',
        Booking::class,
        'maintenance'
    );
}

$cars = Car::getAllForFleet();
$pending = MaintenancePlan::getAllPending();
$alerts = MaintenancePlan::getAlerts();
$recent = Maintenance::getRecentAll(50);

// Planos disponíveis para vincular no formulário de "o que foi feito"
$plansByCar = [];
foreach ($pending as $p) {
    $cid = (int) ($p['plugin_reservafrota_cars_id'] ?? 0);
    $plansByCar[$cid][] = $p;
}

if ($carFilter > 0) {
    $pending = array_values(array_filter($pending, function ($p) use ($carFilter) {
        return (int) ($p['plugin_reservafrota_cars_id'] ?? 0) === $carFilter;
    }));
    $recent = array_values(array_filter($recent, function ($m) use ($carFilter) {
        return (int) ($m['plugin_reservafrota_cars_id'] ?? 0) === $carFilter;
    }));
}

TemplateRenderer::getInstance()->display('@reservafrota/maintenance.list.html.twig', [
    'web_dir'            => Plugin::getWebDir('reservafrota'),
    'cars'               => $cars,
    'car_filter'         => $carFilter,
    'pending'            => $pending,
    'alerts'             => $alerts,
    'recent'             => $recent,
    'plans_by_car'       => $plansByCar,
    'can_edit'           => Session::haveRight(Car::$rightname, UPDATE),
    'can_manage_cars'    => Session::haveRight(Car::$rightname, READ),
    'can_manage_drivers' => Session::haveRight(\GlpiPlugin\Reservafrota\Driver::$rightname, READ),
    'can_view_analytics' => Booking::canApprove(),
    'is_manager'         => Booking::canApprove(),
    'csrf'               => Session::getNewCSRFToken(),
    'today'              => date('Y-m-d'),
]);

if ($used_help) {
    Html::helpFooter();
} else {
    Html::footer();
}
