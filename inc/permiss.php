<?php
// Wrapper for backward compatibility - legacy inc/permiss.php
// Use GlpiPlugin\Reservafrota\Permiss instead
require_once __DIR__ . '/../src/Permiss.php';

use GlpiPlugin\Reservafrota\Permiss;

if (!class_exists('GlpiPlugin\Reservafrota\inc\Permiss', false)) {
    class_alias(Permiss::class, 'GlpiPlugin\Reservafrota\inc\Permiss');
}
if (!class_exists('GlpiPlugin\Reservafrota\inc\permissionCheck', false)) {
    class_alias(Permiss::class, 'GlpiPlugin\Reservafrota\inc\permissionCheck');
}
