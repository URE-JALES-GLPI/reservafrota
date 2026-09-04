<?php

use GlpiPlugin\Reservafrota\Booking;
use GlpiPlugin\Reservafrota\Car;
use Glpi\Application\View\TemplateRenderer;

// Quem pode ver os agendamentos pode ver as fotos da frota (inclui o
// funcionário da interface simplificada, que não tem direito sobre Carros).
Session::checkRight(Booking::$rightname, READ);

$id  = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$car = new Car();

if (!$car->getFromDB($id) || empty($car->fields['picture'])) {
    Html::displayErrorAndDie(__('Foto não encontrada.', 'reservafrota'), true);
}

// basename() evita path traversal.
$path = Car::getPictureDir() . '/' . basename($car->fields['picture']);
if (!is_file($path)) {
    Html::displayErrorAndDie(__('Foto não encontrada.', 'reservafrota'), true);
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime  = $finfo->file($path) ?: 'application/octet-stream';

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, max-age=86400');
readfile($path);
