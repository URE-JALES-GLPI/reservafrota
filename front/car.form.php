<?php

use GlpiPlugin\Reservafrota\Car;
use GlpiPlugin\Reservafrota\Booking;
use Glpi\Application\View\TemplateRenderer;

$car = new Car();

if (isset($_POST['add'])) {
    $car->check(-1, CREATE, $_POST);

    // Processa o upload da foto, se houver.
    if (isset($_FILES['picture']) && ($_FILES['picture']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        $stored = Car::storePicture($_FILES['picture']);

        if ($stored !== null) {
            $_POST['picture'] = $stored;
        }
    }

    $car->add($_POST);

    Html::redirect(Plugin::getWebDir('reservafrota') . '/front/car.php');

} elseif (isset($_POST['update'])) {
    $car->check($_POST['id'], UPDATE);

    // Troca de foto.
    if (isset($_FILES['picture']) && ($_FILES['picture']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        $stored = Car::storePicture($_FILES['picture']);

        if ($stored !== null) {
            // Remove a foto anterior do disco.
            if ($car->getFromDB((int) $_POST['id'])) {
                $car->deletePictureFile();
            }

            $_POST['picture'] = $stored;
        }
    }

    $car->update($_POST);

    Html::redirect(Plugin::getWebDir('reservafrota') . '/front/car.php');

} elseif (isset($_POST['delete'])) {
    $car->check($_POST['id'], DELETE);
    $car->delete($_POST);
    $car->redirectToList();

} elseif (isset($_POST['restore'])) {
    $car->check($_POST['id'], DELETE);
    $car->restore($_POST);
    $car->redirectToList();

} elseif (isset($_POST['purge'])) {
    $car->check($_POST['id'], PURGE);
    $car->delete($_POST, 1);
    $car->redirectToList();

} else {
    $ID = isset($_GET['id']) ? (int) $_GET['id'] : 0;

    Session::checkRight(Car::$rightname, READ);

    Html::header(
        Car::getTypeName(2),
        $_SERVER['PHP_SELF'],
        'tools',
        Booking::class,
        'car'
    );

    $car->showForm($ID);

    Html::footer();
}