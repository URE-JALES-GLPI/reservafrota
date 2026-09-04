<?php

use GlpiPlugin\Reservafrota\Booking;
use GlpiPlugin\Reservafrota\Car;
use Glpi\Application\View\TemplateRenderer;

Session::checkRight(Car::$rightname, READ);

$used_help = false;
if (Session::getCurrentInterface() === 'helpdesk' && method_exists(Html::class, 'helpHeader')) {
    Html::helpHeader(Car::getTypeName(2));
    $used_help = true;
} else {
    Html::header(Car::getTypeName(2), $_SERVER['PHP_SELF'], 'tools', Booking::class, 'car');
}

TemplateRenderer::getInstance()->display('@reservafrota/car.list.html.twig', [
    'web_dir'            => Plugin::getWebDir('reservafrota'),
    'cars'               => Car::getAllForFleet(),
    'can_edit'           => Session::haveRight(Car::$rightname, UPDATE),
    'can_manage_cars'    => true,
    'can_view_analytics' => Booking::canApprove(),
]);

if ($used_help) {
    Html::helpFooter();
} else {
    Html::footer();
}
