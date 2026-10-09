<?php

use GlpiPlugin\Reservafrota\Booking;
use GlpiPlugin\Reservafrota\SheetSync;
use Glpi\Application\View\TemplateRenderer;

// Integração Sheets — somente gestor (quem pode aprovar).
Session::checkRight(Booking::$rightname, Booking::APPROVE);

$feedback = null;

// ---- Ações POST (redirect-after-POST para não reenviar no refresh) ----
if (isset($_POST['save_cfg'])) {
    SheetSync::setConfig(
        (string) ($_POST['spreadsheet_id'] ?? ''),
        (string) ($_POST['api_key'] ?? ''),
        (string) ($_POST['sheet_range'] ?? ''),
        !empty($_POST['enabled']) ? 1 : 0
    );
    Session::addMessageAfterRedirect(__('Configuração da integração salva.', 'reservafrota'), true);
    Html::redirect(Plugin::getWebDir('reservafrota') . '/front/sheetsync.php');
} elseif (isset($_POST['sync_now'])) {
    $res = SheetSync::runSync(true);
    if (!empty($res['ok'])) {
        Session::addMessageAfterRedirect((string) ($res['message'] ?? __('Sincronização concluída.', 'reservafrota')), true);
    } else {
        Session::addMessageAfterRedirect((string) ($res['message'] ?? __('Falha na sincronização.', 'reservafrota')), false, ERROR);
    }
    Html::redirect(Plugin::getWebDir('reservafrota') . '/front/sheetsync.php');
} elseif (isset($_POST['reprocess'])) {
    $res = SheetSync::reprocess((int) ($_POST['log_id'] ?? 0));
    if (!empty($res['ok'])) {
        Session::addMessageAfterRedirect(__('Linha reprocessada: ', 'reservafrota') . (string) ($res['message'] ?? ''), true);
    } else {
        Session::addMessageAfterRedirect((string) ($res['message'] ?? __('Falha ao reprocessar.', 'reservafrota')), false, ERROR);
    }
    Html::redirect(Plugin::getWebDir('reservafrota') . '/front/sheetsync.php');
} elseif (isset($_POST['manual_apply'])) {
    $res = SheetSync::applyEvent(
        (string) ($_POST['code'] ?? ''),
        (string) ($_POST['event'] ?? ''),
        (int) ($_POST['km'] ?? 0),
        (string) ($_POST['driver'] ?? ''),
        trim((string) ($_POST['event_date'] ?? '')) !== '' ? trim((string) $_POST['event_date']) . ' 12:00:00' : null,
        trim((string) ($_POST['obs'] ?? '')),
        'manual',
        true
    );
    try {
        SheetSync::logRow(0, date('Y-m-d H:i:s'), (string) ($_POST['code'] ?? ''), (int) ($res['bookings_id'] ?? 0), (string) ($_POST['event'] ?? ''), (int) ($_POST['km'] ?? 0), (string) ($_POST['driver'] ?? ''), (string) ($_POST['obs'] ?? ''), !empty($res['ok']) ? 'applied' : 'error', (string) ($res['message'] ?? ''), 'manual');
    } catch (\Throwable $e) {}
    if (!empty($res['ok'])) {
        Session::addMessageAfterRedirect((string) ($res['message'] ?? __('Lançamento aplicado.', 'reservafrota')), true);
    } else {
        Session::addMessageAfterRedirect((string) ($res['message'] ?? __('Falha ao aplicar.', 'reservafrota')), false, ERROR);
    }
    Html::redirect(Plugin::getWebDir('reservafrota') . '/front/sheetsync.php');
}

$used_help = false;
if (Session::getCurrentInterface() === 'helpdesk' && method_exists(Html::class, 'helpHeader')) {
    Html::helpHeader(__('Integração Sheets', 'reservafrota'));
    $used_help = true;
} else {
    Html::header(
        __('Integração Sheets', 'reservafrota'),
        $_SERVER['PHP_SELF'],
        'tools',
        Booking::class,
        'sheetsync'
    );
}

$cfg = SheetSync::getConfig();
$filter = isset($_GET['f']) && in_array($_GET['f'], ['applied', 'error'], true) ? $_GET['f'] : '';

TemplateRenderer::getInstance()->display('@reservafrota/sheetsync.html.twig', [
    'web_dir'   => Plugin::getWebDir('reservafrota'),
    'cfg'       => $cfg,
    'logs'      => SheetSync::getLogs(100, $filter),
    'filter'    => $filter,
    'can_manage_cars'    => Session::haveRight(\GlpiPlugin\Reservafrota\Car::$rightname, READ),
    'can_view_analytics' => Booking::canApprove(),
]);

if ($used_help) {
    Html::helpFooter();
} else {
    Html::footer();
}
