<?php

use GlpiPlugin\Reservafrota\Booking;
use GlpiPlugin\Reservafrota\Driver;
use Glpi\Application\View\TemplateRenderer;

Session::checkRight(Driver::$rightname, READ);

$used_help = false;
if (Session::getCurrentInterface() === 'helpdesk' && method_exists(Html::class, 'helpHeader')) {
    Html::helpHeader(Driver::getTypeName(2));
    $used_help = true;
} else {
    Html::header(Driver::getTypeName(2), $_SERVER['PHP_SELF'], 'tools', Booking::class, 'driver');
}

TemplateRenderer::getInstance()->display('@reservafrota/driver.list.html.twig', [
    'web_dir'            => Plugin::getWebDir('reservafrota'),
    'drivers'            => Driver::getAllForList(),
    'can_edit'           => Session::haveRight(Driver::$rightname, UPDATE),
    'can_view_analytics' => Booking::canApprove(),
]);

if ($used_help) {
    Html::helpFooter();
} else {
    Html::footer();
}
