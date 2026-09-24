<?php

use GlpiPlugin\Reservafrota\Driver;

$driver = new Driver();

if (isset($_POST['add'])) {
    $driver->check(-1, CREATE, $_POST);
    if (isset($_FILES['picture']) && ($_FILES['picture']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        $stored = Driver::storePicture($_FILES['picture']);
        if ($stored !== null) {
            $_POST['picture'] = $stored;
        }
    }
    $driver->add($_POST);
    Html::redirect(Plugin::getWebDir('reservafrota') . '/front/driver.php');

} elseif (isset($_POST['update'])) {
    $driver->check($_POST['id'], UPDATE);
    if (isset($_FILES['picture']) && ($_FILES['picture']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        $stored = Driver::storePicture($_FILES['picture']);
        if ($stored !== null) {
            if ($driver->getFromDB((int) $_POST['id'])) {
                $driver->deletePictureFile();
            }
            $_POST['picture'] = $stored;
        }
    }
    $driver->update($_POST);
    Html::redirect(Plugin::getWebDir('reservafrota') . '/front/driver.php');

} elseif (isset($_POST['delete'])) {
    $driver->check($_POST['id'], DELETE);
    $driver->delete($_POST);
    $driver->redirectToList();

} elseif (isset($_POST['restore'])) {
    $driver->check($_POST['id'], DELETE);
    $driver->restore($_POST);
    $driver->redirectToList();

} elseif (isset($_POST['purge'])) {
    $driver->check($_POST['id'], PURGE);
    $driver->delete($_POST, 1);
    $driver->redirectToList();

} else {
    $ID = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    Session::checkRight(Driver::$rightname, READ);
    Html::header(
        Driver::getTypeName(2),
        $_SERVER['PHP_SELF'],
        'tools',
        \GlpiPlugin\Reservafrota\Booking::class,
        'driver'
    );
    $driver->showForm($ID);
    Html::footer();
}
