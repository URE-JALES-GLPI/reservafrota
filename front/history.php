<?php

use GlpiPlugin\Reservafrota\Booking;
use GlpiPlugin\Reservafrota\Car;
use Glpi\Application\View\TemplateRenderer;

Session::checkRight(Booking::$rightname, READ);

$used_help = false;
if (Session::getCurrentInterface() === 'helpdesk' && method_exists(Html::class, 'helpHeader')) {
    Html::helpHeader(__('Histórico', 'reservafrota'));
    $used_help = true;
} else {
    Html::header(
        __('Histórico', 'reservafrota'),
        $_SERVER['PHP_SELF'],
        'tools',
        Booking::class,
        'history'
    );
}

TemplateRenderer::getInstance()->display('@reservafrota/history.html.twig', [
    'web_dir'            => Plugin::getWebDir('reservafrota'),
    'history'            => Booking::getHistory(),
    'is_manager'         => Booking::canApprove(),
    'can_manage_cars'    => Session::haveRight(Car::$rightname, READ),
    'can_view_analytics' => Booking::canApprove(),
]);

if ($used_help) {
    Html::helpFooter();
} else {
    Html::footer();
}
