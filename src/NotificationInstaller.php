<?php

namespace GlpiPlugin\Reservafrota;

use Config;
use Notification;
use Notification_NotificationTemplate;
use NotificationTarget;
use NotificationTemplate;
use NotificationTemplateTranslation;

/**
 * Cria/remove os modelos, notificações e destinatários padrão das reservas.
 *
 * A operação é idempotente: pode ser chamada na instalação e também no init,
 * para o caso de o plugin ser atualizado apenas por cópia de arquivos.
 */
class NotificationInstaller
{
    private const VERSION       = '2';
    private const PREFIX        = '[Reservafrota] ';
    private const TEMPLATE_NAME = 'Reservafrota - Notificações de reservas';

    private const FORM_LINK_TEXT = '##IFbooking.form_url##Registrar saída, chegada e KM: ##booking.form_url## ##ENDIFbooking.form_url##';
    private const FORM_LINK_HTML = '##IFbooking.form_url##<p><a href="##booking.form_url##">Registrar saída, chegada e KM</a></p>##ENDIFbooking.form_url##';

    public static function ensureIfNeeded(): void
    {
        try {
            $config = Config::getConfigurationValues('plugin:reservafrota');
            if (($config['notifications_version'] ?? '') === self::VERSION) {
                return;
            }

            if (self::ensure()) {
                Config::setConfigurationValues('plugin:reservafrota', [
                    'notifications_version' => self::VERSION,
                ]);
            }
        } catch (\Throwable $e) {
            // A instalação/atualização explícita tentará novamente.
            error_log('Reservafrota: falha ao criar notificações: ' . $e->getMessage());
        }
    }

    public static function ensure(): bool
    {
        /** @var \DBmysql $DB */
        global $DB;

        if (!isset($DB) || !($DB instanceof \DBmysql)) {
            return false;
        }

        foreach ([
            NotificationTemplate::getTable(),
            NotificationTemplateTranslation::getTable(),
            Notification::getTable(),
            Notification_NotificationTemplate::getTable(),
            NotificationTarget::getTable(),
        ] as $table) {
            if (!$DB->tableExists($table)) {
                return false;
            }
        }

        $templateId = self::ensureTemplate();
        if ($templateId <= 0) {
            return false;
        }

        $ok = true;
        foreach (self::definitions() as $event => $definition) {
            $ok = self::ensureNotification($event, $definition, $templateId) && $ok;
        }

        return $ok;
    }

    public static function remove(): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        if (!isset($DB) || !($DB instanceof \DBmysql)) {
            return;
        }

        try {
            $iterator = $DB->request([
                'SELECT' => ['id'],
                'FROM'   => Notification::getTable(),
                'WHERE'  => [
                    'itemtype' => Booking::class,
                    'name'     => ['LIKE', self::PREFIX . '%'],
                ],
            ]);

            foreach ($iterator as $row) {
                $notifications_id = (int) ($row['id'] ?? 0);
                if ($notifications_id <= 0) {
                    continue;
                }

                $DB->delete(NotificationTarget::getTable(), ['notifications_id' => $notifications_id]);
                $DB->delete(Notification_NotificationTemplate::getTable(), ['notifications_id' => $notifications_id]);

                $notification = new Notification();
                if ($notification->getFromDB($notifications_id)) {
                    $notification->delete(['id' => $notifications_id], true);
                }
            }

            $template = new NotificationTemplate();
            if ($template->getFromDBByCrit([
                'name'     => self::TEMPLATE_NAME,
                'itemtype' => Booking::class,
            ])) {
                $templates_id = (int) $template->getID();
                $DB->delete(NotificationTemplateTranslation::getTable(), ['notificationtemplates_id' => $templates_id]);
                $DB->delete(Notification_NotificationTemplate::getTable(), ['notificationtemplates_id' => $templates_id]);
                $template->delete(['id' => $templates_id], true);
            }

            if (method_exists(Config::class, 'deleteConfigurationValues')) {
                Config::deleteConfigurationValues('plugin:reservafrota', ['notifications_version']);
            }
        } catch (\Throwable $e) {
            // Desinstalação continua mesmo se a limpeza de notificações falhar.
        }
    }

    /**
     * @return array<string, array{name: string, targets: list<int>}>
     */
    private static function definitions(): array
    {
        return [
            'new' => [
                'name'    => 'Nova reserva',
                'targets' => [NotificationTargetBooking::MANAGERS],
            ],
            'approved' => [
                'name'    => 'Reserva aprovada',
                'targets' => [Notification::AUTHOR, NotificationTargetBooking::DRIVER],
            ],
            'rejected' => [
                'name'    => 'Reserva recusada',
                'targets' => [Notification::AUTHOR],
            ],
            'cancelled' => [
                'name'    => 'Reserva cancelada',
                'targets' => [Notification::AUTHOR, NotificationTargetBooking::MANAGERS],
            ],
            'returned' => [
                'name'    => 'Veículo devolvido',
                'targets' => [Notification::AUTHOR],
            ],
        ];
    }

    private static function ensureTemplate(): int
    {
        $template = new NotificationTemplate();
        if (!$template->getFromDBByCrit([
            'name'     => self::TEMPLATE_NAME,
            'itemtype' => Booking::class,
        ])) {
            $id = $template->add([
                'name'     => self::TEMPLATE_NAME,
                'itemtype' => Booking::class,
                'comment'  => 'Modelo padrão criado pelo plugin Reservafrota.',
            ]);
            if (!$id || !$template->getFromDB((int) $id)) {
                return 0;
            }
        }

        $templates_id = (int) $template->getID();

        // pt_BR para os usuários em português e "" como fallback para os
        // demais idiomas (GLPI consulta language, depois language="").
        foreach (['pt_BR', ''] as $language) {
            $translation = new NotificationTemplateTranslation();
            if (!$translation->getFromDBByCrit([
                'notificationtemplates_id' => $templates_id,
                'language'                 => $language,
            ])) {
                if (!$translation->add([
                    'notificationtemplates_id' => $templates_id,
                    'language'                 => $language,
                    'subject'                  => '[Reservafrota] ##booking.action## - ##booking.code##',
                    'content_text'             => self::contentText() . "\n" . self::FORM_LINK_TEXT,
                    'content_html'             => self::contentHtml() . "\n" . self::FORM_LINK_HTML,
                ])) {
                    return 0;
                }
            } else {
                self::appendFormLink($translation);
            }
        }

        return $templates_id;
    }

    /**
     * Acrescenta o link do formulário a um modelo já instalado, sem
     * sobrescrever o que o administrador tenha personalizado.
     */
    private static function appendFormLink(NotificationTemplateTranslation $translation): void
    {
        $update = [];
        foreach (['content_text' => self::FORM_LINK_TEXT, 'content_html' => self::FORM_LINK_HTML] as $field => $block) {
            $content = (string) ($translation->fields[$field] ?? '');
            if (!str_contains($content, 'booking.form_url')) {
                $update[$field] = $content . "\n" . $block;
            }
        }
        if ($update !== []) {
            $translation->update(['id' => (int) $translation->getID()] + $update);
        }
    }

    /**
     * @param array{name: string, targets: list<int>} $definition
     */
    private static function ensureNotification(string $event, array $definition, int $templates_id): bool
    {
        $notification = new Notification();
        if (!$notification->getFromDBByCrit([
            'name'     => self::PREFIX . $definition['name'],
            'itemtype' => Booking::class,
            'event'    => $event,
        ])) {
            $notifications_id = $notification->add([
                'name'           => self::PREFIX . $definition['name'],
                'itemtype'       => Booking::class,
                'event'          => $event,
                'entities_id'    => 0,
                'is_recursive'   => 0,
                'is_active'      => 1,
                'allow_response' => 0,
                'comment'        => 'Notificação automática de reservas de veículos.',
            ]);
            if (!$notifications_id || !$notification->getFromDB((int) $notifications_id)) {
                return false;
            }
        }

        $notifications_id = (int) $notification->getID();
        if ($notifications_id <= 0) {
            return false;
        }

        $link = new Notification_NotificationTemplate();
        if (!$link->getFromDBByCrit([
            'notifications_id'        => $notifications_id,
            'mode'                    => Notification_NotificationTemplate::MODE_MAIL,
            'notificationtemplates_id' => $templates_id,
        ])) {
            if (!$link->add([
                'notifications_id'         => $notifications_id,
                'mode'                     => Notification_NotificationTemplate::MODE_MAIL,
                'notificationtemplates_id' => $templates_id,
            ])) {
                return false;
            }
        }

        foreach ($definition['targets'] as $targetId) {
            $target = new NotificationTarget();
            if (!$target->getFromDBByCrit([
                'notifications_id' => $notifications_id,
                'type'             => Notification::USER_TYPE,
                'items_id'         => $targetId,
                'is_exclusion'     => 0,
            ])) {
                if (!$target->add([
                    'notifications_id' => $notifications_id,
                    'type'             => Notification::USER_TYPE,
                    'items_id'         => $targetId,
                    'is_exclusion'     => 0,
                ])) {
                    return false;
                }
            }
        }

        return true;
    }

    private static function contentText(): string
    {
        return <<<'TEXT'
Olá,

A reserva ##booking.code## foi atualizada: ##booking.action##.

Solicitante: ##booking.user##
Status: ##booking.status##
Veículo: ##booking.car##
Motorista: ##booking.driver##
Saída: ##booking.departure##
Chegada: ##booking.arrival##
Destino: ##booking.destination##
Motivo: ##booking.reason##

##booking.validation_comment##
##booking.arrival_obs##

Acesse a reserva: ##booking.url##

Esta é uma mensagem automática do Reservafrota. Não responda.
TEXT;
    }

    private static function contentHtml(): string
    {
        return <<<'HTML'
<p>Olá,</p>
<p>A reserva <strong>##booking.code##</strong> foi atualizada: <strong>##booking.action##</strong>.</p>
<table>
  <tr><td><strong>Solicitante</strong></td><td>##booking.user##</td></tr>
  <tr><td><strong>Status</strong></td><td>##booking.status##</td></tr>
  <tr><td><strong>Veículo</strong></td><td>##booking.car##</td></tr>
  <tr><td><strong>Motorista</strong></td><td>##booking.driver##</td></tr>
  <tr><td><strong>Saída</strong></td><td>##booking.departure##</td></tr>
  <tr><td><strong>Chegada</strong></td><td>##booking.arrival##</td></tr>
  <tr><td><strong>Destino</strong></td><td>##booking.destination##</td></tr>
  <tr><td><strong>Motivo</strong></td><td>##booking.reason##</td></tr>
</table>
<p>##booking.validation_comment##</p>
<p>##booking.arrival_obs##</p>
<p><a href="##booking.url##">Acessar a reserva</a></p>
<p>Esta é uma mensagem automática do Reservafrota. Não responda.</p>
HTML;
    }
}
