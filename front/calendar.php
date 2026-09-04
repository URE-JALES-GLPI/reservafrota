<?php

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Reservafrota\Booking;
use GlpiPlugin\Reservafrota\Car;


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

TemplateRenderer::getInstance()->display('@reservafrota/calendar.html.twig', [
    'web_dir'            => Plugin::getWebDir('reservafrota'),
    'month'              => $month,
    'is_helpdesk'        => $used_help,
    'can_manage_cars'    => Session::haveRight(Car::$rightname, READ),
    'can_view_analytics' => Booking::canApprove(),
    'can_create'         => Session::haveRight(Booking::$rightname, CREATE),
    'can_delete'         => Session::haveRight(Booking::$rightname, PURGE),
    'cars'               => Car::getActiveCars(),
    'groups'             => Booking::getGroupsList(),
    'users'              => Booking::getUsersList(),
    'current_user_label' => Booking::getCurrentUserLabel(),
    'blist'              => Booking::getGroupedByStatus(),
    'pending_count'      => Booking::countPending(),
    'can_approve'        => Booking::canApprove(),
    'agenda_url'         => Plugin::getWebDir('reservafrota') . '/front/agenda.php',
    'blist_url'          => Plugin::getWebDir('reservafrota') . '/ajax/bookinglist.php',
    'csrf'               => Session::getNewCSRFToken(),
]);

if ($used_help) {
    Html::helpFooter();
} else {
    Html::footer();
}
