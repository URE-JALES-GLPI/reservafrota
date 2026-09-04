<?php

use GlpiPlugin\Reservafrota\Booking;

Session::checkRight(Booking::$rightname, READ);

// A lista de Agendamentos foi unificada ao Calendário (aparece abaixo dele).
// Mantido apenas como redirecionamento para não quebrar links antigos.
$anchor = (isset($_GET['anchor']) && preg_match('/^[a-z0-9_-]+$/i', $_GET['anchor']))
    ? ('#' . $_GET['anchor'])
    : '#reservafrota-booking-list';

Html::redirect(Plugin::getWebDir('reservafrota') . '/front/calendar.php' . $anchor);
