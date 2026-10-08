<?php

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Reservafrota\Booking;
use GlpiPlugin\Reservafrota\Car;
use GlpiPlugin\Reservafrota\Driver;


Session::checkRight(Booking::$rightname, READ);



$month = $_GET['month'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date('Y-m');
}

// Cabeçalho conforme a interface (simplificada x central).
$used_help = false;
if (Session::getCurrentInterface() === 'helpdesk' && method_exists(Html::class, 'helpHeader')) {
    Html::helpHeader(__('Calendário', 'reservafrota'));
    $used_help = true;
} else {
    Html::header(
        __('Calendário', 'reservafrota'),
        $_SERVER['PHP_SELF'],
        'tools',
        Booking::class,
        'calendar'
    );
}

$maintAlerts = [];
try {
    if (class_exists(\GlpiPlugin\Reservafrota\MaintenancePlan::class) && Booking::canApprove()) {
        $maintAlerts = \GlpiPlugin\Reservafrota\MaintenancePlan::getAlerts();
    }
} catch (\Throwable $e) {
    $maintAlerts = [];
}

$monthStats = ['pending' => 0, 'approved' => 0, 'arrived' => 0, 'conflict' => 0, 'cancelled' => 0];
try {
    $mrows = Booking::getBookingsForMonth($month, false);
    $cIn = [];
    foreach ($mrows as $mr) {
        $s = strtotime((string) $mr['departure']);
        $e = !empty($mr['arrival']) ? strtotime((string) $mr['arrival']) : ($s + 3600);
        $cIn[] = [
            'id'         => (int) $mr['id'],
            'car_id'     => (int) $mr['car_id'],
            'drivers_id' => (int) ($mr['drivers_id'] ?? 0),
            'start'      => $s,
            'end'        => $e,
            'status'     => (int) $mr['status'],
        ];
    }
    $mconf = Booking::markConflicts($cIn);
    foreach ($mrows as $mr) {
        $st = (int) $mr['status'];
        if (!empty($mconf[(int) $mr['id']])) {
            $monthStats['conflict']++;
        }
        if ($st === Booking::STATUS_PENDING) {
            $monthStats['pending']++;
        } elseif ($st === Booking::STATUS_APPROVED) {
            $monthStats['approved']++;
        } elseif ($st === Booking::STATUS_ARRIVED) {
            $monthStats['arrived']++;
        } elseif ($st === Booking::STATUS_CANCELLED || $st === Booking::STATUS_REJECTED) {
            $monthStats['cancelled']++;
        }
    }
} catch (\Throwable $e) {
    $monthStats = ['pending' => 0, 'approved' => 0, 'arrived' => 0, 'conflict' => 0, 'cancelled' => 0];
}

TemplateRenderer::getInstance()->display('@reservafrota/calendar.html.twig', [
    'web_dir'            => Plugin::getWebDir('reservafrota'),
    'month'              => $month,
    'is_helpdesk'        => $used_help,
    'can_manage_cars'    => Session::haveRight(Car::$rightname, READ),
    'can_manage_drivers' => Session::haveRight(Driver::$rightname, READ),
    'can_view_analytics' => Booking::canApprove(),
    'can_create'         => Session::haveRight(Booking::$rightname, CREATE),
    'can_delete'         => Session::haveRight(Booking::$rightname, PURGE),
    'cars'               => Car::getActiveCars(),
    'drivers'            => Driver::getActiveDrivers(),
    'groups'             => Booking::getGroupsList(),
    'users'              => Booking::getUsersList(),
    'current_user_label' => Booking::getCurrentUserLabel(),
    'blist'              => Booking::getGroupedByStatus(),
    'pending_count'      => Booking::countPending(),
    'can_approve'        => Booking::canApprove(),
    'agenda_url'         => Plugin::getWebDir('reservafrota') . '/front/agenda.php',
    'blist_url'          => Plugin::getWebDir('reservafrota') . '/ajax/bookinglist.php',
    'csrf'               => Session::getNewCSRFToken(),
    'maint_alerts'       => $maintAlerts,
    'school_map'         => \GlpiPlugin\Reservafrota\Schools::getMap(),
    'current_driver_id'  => Driver::getDriverIdForUser((int) Session::getLoginUserID()),
    'month_stats'        => $monthStats,
    'month_label'        => $month,
]);

if ($used_help) {
    Html::helpFooter();
} else {
    Html::footer();
}
