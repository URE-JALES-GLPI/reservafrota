<?php

use GlpiPlugin\Reservafrota\Booking;
use GlpiPlugin\Reservafrota\Car;
use Glpi\Application\View\TemplateRenderer;

// Visão gerencial: exige o direito de aprovar agendamentos.
Session::checkRight(Booking::$rightname, Booking::APPROVE);

// Mês selecionado (YYYY-MM); padrão = mês atual.
$month = $_GET['month'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date('Y-m');
}

$used_help = false;
if (Session::getCurrentInterface() === 'helpdesk' && method_exists(Html::class, 'helpHeader')) {
    Html::helpHeader(__('Análise', 'reservafrota'));
    $used_help = true;
} else {
    Html::header(
        __('Análise', 'reservafrota'),
        $_SERVER['PHP_SELF'],
        'tools',
        Booking::class,
        'analytics'
    );
}

$data = Booking::getAnalyticsForMonth($month);
$bookings = Booking::getBookingsForMonth($month, false);

// Cancelamentos do mês (quem cancelou, quando e por quê).
$cancellations = array_values(array_filter($bookings, static function ($b) {
    return (int) $b['status'] === Booking::STATUS_CANCELLED;
}));

$year = (int) substr($month, 0, 4);
$year_bookings = Booking::getBookingsForYear($year);

$meses = [1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril',
          5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto',
          9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'];
$ts = strtotime($month . '-01');
$month_label = $meses[(int) date('n', $ts)] . ' / ' . date('Y', $ts);

TemplateRenderer::getInstance()->display('@reservafrota/analytics.html.twig', [
    'web_dir'         => Plugin::getWebDir('reservafrota'),
    'month'           => $month,
    'month_label'     => $month_label,
    'year'            => $year,
    'meses'           => $meses,
    'can_manage_cars' => Session::haveRight(Car::$rightname, READ),
    'is_helpdesk'     => $used_help,
    'data'            => $data,
    'bookings'        => $bookings,
    'cancellations'   => $cancellations,
    'year_bookings'   => $year_bookings,
]);

if ($used_help) {
    Html::helpFooter();
} else {
    Html::footer();
}