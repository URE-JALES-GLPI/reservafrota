<?php

use GlpiPlugin\Reservafrota\Car;
use GlpiPlugin\Reservafrota\Maintenance;
use GlpiPlugin\Reservafrota\MaintenancePlan;

$redirect = Plugin::getWebDir('reservafrota') . '/front/maintenance.php';
if (!empty($_REQUEST['cars_id'])) {
    $redirect .= '?cars_id=' . (int) $_REQUEST['cars_id'];
}

if (isset($_POST['add_plan'])) {
    Session::checkRight(Car::$rightname, UPDATE);
    $plan = new MaintenancePlan();
    $plan->check(-1, CREATE, $_POST);
    if ($plan->add($_POST)) {
        Session::addMessageAfterRedirect(__('Manutenção programada cadastrada.', 'reservafrota'), true);
    }
    Html::redirect($redirect);

} elseif (isset($_POST['update_plan'])) {
    Session::checkRight(Car::$rightname, UPDATE);
    $plan = new MaintenancePlan();
    $plan->check($_POST['id'], UPDATE);
    if ($plan->update($_POST)) {
        Session::addMessageAfterRedirect(__('Manutenção programada atualizada.', 'reservafrota'), true);
    }
    Html::redirect($redirect);

} elseif (isset($_POST['delete_plan'])) {
    Session::checkRight(Car::$rightname, UPDATE);
    $plan = new MaintenancePlan();
    $plan->check($_POST['id'], PURGE);
    $plan->delete($_POST, 1);
    Session::addMessageAfterRedirect(__('Manutenção programada excluída.', 'reservafrota'), true);
    Html::redirect($redirect);

} elseif (isset($_POST['done_plan'])) {
    Session::checkRight(Car::$rightname, UPDATE);
    $plan = new MaintenancePlan();
    if ($plan->getFromDB((int) $_POST['id'])) {
        $km = isset($_POST['done_km']) && $_POST['done_km'] !== '' ? (int) $_POST['done_km'] : 0;
        $desc = trim((string) ($_POST['done_desc'] ?? ''));
        if ($plan->markDone($km, $desc)) {
            Session::addMessageAfterRedirect(__('Manutenção marcada como concluída.', 'reservafrota'), true);
        }
    }
    Html::redirect($redirect);

} elseif (isset($_POST['reopen_plan'])) {
    Session::checkRight(Car::$rightname, UPDATE);
    $plan = new MaintenancePlan();
    $plan->check($_POST['id'], UPDATE);
    $plan->update(['id' => (int) $_POST['id'], 'is_done' => 0, 'date_done' => null]);
    Session::addMessageAfterRedirect(__('Manutenção reaberta.', 'reservafrota'), true);
    Html::redirect($redirect);

} elseif (isset($_POST['add_maintenance'])) {
    Session::checkRight(Car::$rightname, UPDATE);
    $m = new Maintenance();
    $m->check(-1, CREATE, $_POST);
    if ($m->add($_POST)) {
        Session::addMessageAfterRedirect(__('Manutenção registrada no histórico.', 'reservafrota'), true);
    }
    Html::redirect($redirect);

} elseif (isset($_POST['delete_maintenance'])) {
    Session::checkRight(Car::$rightname, UPDATE);
    $m = new Maintenance();
    $m->check($_POST['id'], PURGE);
    $m->delete($_POST, 1);
    Session::addMessageAfterRedirect(__('Registro de manutenção excluído.', 'reservafrota'), true);
    Html::redirect($redirect);

} else {
    Html::redirect($redirect);
}
