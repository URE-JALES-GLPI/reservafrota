<?php

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Reservafrota\Booking;

Session::checkRight(Booking::$rightname, READ);

header('Content-Type: text/html; charset=utf-8');

TemplateRenderer::getInstance()->display('@reservafrota/booking.lists.html.twig', [
    'web_dir'       => Plugin::getWebDir('reservafrota'),
    'blist'         => Booking::getGroupedByStatus(),
    'pending_count' => Booking::countPending(),
    'can_approve'   => Booking::canApprove(),
    'csrf'          => Session::getNewCSRFToken(),
]);
