<?php

use GlpiPlugin\Reservafrota\Driver;

Session::checkRight(Driver::$rightname, READ);

$id = (int) ($_GET['id'] ?? 0);
$driver = new Driver();
if ($id <= 0 || !$driver->getFromDB($id)) {
    http_response_code(404);
    exit;
}
if (empty($driver->fields['picture'])) {
    http_response_code(404);
    exit;
}
$path = Driver::getPictureDir() . '/' . basename($driver->fields['picture']);
if (!is_file($path)) {
    http_response_code(404);
    exit;
}
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime  = $finfo->file($path) ?: 'image/jpeg';
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Cache-Control: public, max-age=86400');
readfile($path);
