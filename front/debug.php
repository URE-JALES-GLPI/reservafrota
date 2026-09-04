<?php

use GlpiPlugin\Reservafrota\Booking;
use GlpiPlugin\Reservafrota\Car;

Session::checkLoginUser();

header('Content-Type: text/plain; charset=utf-8');

echo "==== DEBUG reservafrota ====\n\n";
echo "Usuário......: " . ($_SESSION['glpiname'] ?? '?') . "\n";
echo "Perfil (id)..: " . ($_SESSION['glpiactiveprofile']['id'] ?? '?') . "\n";
echo "Perfil (nome): " . ($_SESSION['glpiactiveprofile']['name'] ?? '?') . "\n";
echo "Interface....: " . Session::getCurrentInterface() . "\n\n";

echo "---- CONSTANTES ----\n";
echo "READ=" . READ . " CREATE=" . CREATE . " UPDATE=" . UPDATE . " DELETE=" . DELETE . " PURGE=" . PURGE . " APPROVE=" . Booking::APPROVE . "\n\n";

echo "---- NA SESSÃO ----\n";
$sb = $_SESSION['glpiactiveprofile']['reservafrota::booking'] ?? null;
$sc = $_SESSION['glpiactiveprofile']['reservafrota::car'] ?? null;
echo "reservafrota::booking = " . var_export($sb, true) . "\n";
echo "reservafrota::car     = " . var_export($sc, true) . "\n\n";

echo "---- NO BANCO (perfil ativo) ----\n";
global $DB;
$pid = (int) ($_SESSION['glpiactiveprofile']['id'] ?? 0);
foreach ($DB->request([
    'SELECT' => ['name', 'rights'],
    'FROM'   => 'glpi_profilerights',
    'WHERE'  => ['profiles_id' => $pid, 'name' => ['LIKE', 'reservafrota::%']],
]) as $row) {
    echo $row['name'] . " = " . $row['rights'] . "\n";
}
echo "\n";

echo "---- haveRight (booking) ----\n";
echo "READ    = " . var_export(Session::haveRight(Booking::$rightname, READ), true) . "\n";
echo "CREATE  = " . var_export(Session::haveRight(Booking::$rightname, CREATE), true) . "\n";
echo "APPROVE = " . var_export(Session::haveRight(Booking::$rightname, Booking::APPROVE), true) . "\n\n";

echo "---- haveRight (car) ----\n";
echo "READ    = " . var_export(Session::haveRight(Car::$rightname, READ), true) . "\n\n";

echo "---- canView / canApprove ----\n";
echo "Booking::canView()    = " . var_export(Booking::canView(), true) . "\n";
echo "Booking::canApprove() = " . var_export(Booking::canApprove(), true) . "\n";
echo "Car::canView()        = " . var_export(Car::canView(), true) . "\n";

echo "\n==== FIM ====\n";