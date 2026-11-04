<?php

namespace GlpiPlugin\Reservafrota;

use Html;
use Notification;
use NotificationTarget;
use User;

/**
 * Destino das notificações de reservas.
 *
 * O GLPI descobre esta classe pela convenção:
 * GlpiPlugin\Reservafrota\Booking -> GlpiPlugin\Reservafrota\NotificationTargetBooking.
 */
class NotificationTargetBooking extends NotificationTarget
{
    /** Alvo customizado: usuários com o direito de aprovar reservas. */
    public const MANAGERS = 1100;

    /** Alvo customizado: usuário vinculado ao motorista da reserva. */
    public const DRIVER = 1101;

    /**
     * Eventos disponíveis em Configurar > Notificações.
     *
     * @return array<string, string>
     */
    public function getEvents()
    {
        return array_merge(parent::getEvents(), [
            'new'       => __('Nova reserva', 'reservafrota'),
            'approved'  => __('Reserva aprovada', 'reservafrota'),
            'rejected'  => __('Reserva recusada', 'reservafrota'),
            'cancelled' => __('Reserva cancelada', 'reservafrota'),
            'returned'  => __('Veículo devolvido', 'reservafrota'),
        ]);
    }

    /**
     * Destinatários que podem ser escolhidos na configuração da notificação.
     */
    public function addNotificationTargets($entity)
    {
        parent::addNotificationTargets($entity);

        $this->addTarget(Notification::AUTHOR, __('Solicitante', 'reservafrota'));
        $this->addTarget(self::MANAGERS, __('Gestores da frota (quem aprova)', 'reservafrota'));
        $this->addTarget(self::DRIVER, __('Motorista vinculado', 'reservafrota'));
    }

    /**
     * Resolve os alvos customizados em usuários concretos.
     */
    public function addSpecificTargets($data, $options)
    {
        $target = (int) ($data['items_id'] ?? 0);

        if ($target === self::MANAGERS) {
            foreach ($this->getManagerUserIds() as $users_id) {
                $this->addToRecipientsList(['users_id' => $users_id]);
            }
            return;
        }

        if ($target === self::DRIVER) {
            if (!$this->obj instanceof Booking) {
                return;
            }

            $driverId = (int) ($this->obj->fields['plugin_reservafrota_drivers_id'] ?? 0);
            if ($driverId <= 0) {
                return;
            }

            $driver = new Driver();
            if ($driver->getFromDB($driverId) && (int) ($driver->fields['users_id'] ?? 0) > 0) {
                $this->addToRecipientsList([
                    'users_id' => (int) $driver->fields['users_id'],
                ]);
            }
        }
    }

    /**
     * Dados substituídos nas tags ##booking.*## dos templates.
     */
    public function addDataForTemplate($event, $options = [])
    {
        if (!$this->obj instanceof Booking) {
            return;
        }

        $booking = $this->obj;
        $fields  = $booking->fields;

        $id       = (int) ($fields['id'] ?? 0);
        $users_id = (int) ($fields['users_id'] ?? 0);
        $code     = trim((string) ($fields['request_code'] ?? ''));
        if ($code === '') {
            $code = (string) $id;
        }

        $userName = '-';
        if ($users_id > 0) {
            $user = new User();
            if ($user->getFromDB($users_id)) {
                $userName = $user->getFriendlyName();
            }
        }

        $carName = '-';
        $carId   = (int) ($fields['plugin_reservafrota_cars_id'] ?? 0);
        if ($carId > 0) {
            $car = new Car();
            if ($car->getFromDB($carId)) {
                $carName = (string) $car->fields['name'];
            }
        }

        $driverName = trim((string) ($fields['driver'] ?? ''));
        $driverId   = (int) ($fields['plugin_reservafrota_drivers_id'] ?? 0);
        if ($driverId > 0) {
            $driver = new Driver();
            if ($driver->getFromDB($driverId)) {
                $driverName = (string) $driver->fields['name'];
            }
        }
        if ($driverName === '') {
            $driverName = '-';
        }

        $approverName = '-';
        $approverId   = (int) ($fields['users_id_approver'] ?? 0);
        if ($approverId > 0) {
            $approver = new User();
            if ($approver->getFromDB($approverId)) {
                $approverName = $approver->getFriendlyName();
            }
        }

        $companion = '-';
        if (!empty($fields['has_companion']) && trim((string) ($fields['companion'] ?? '')) !== '') {
            $companion = (string) $fields['companion'];
        }

        $comment = '';
        if ($event === 'returned') {
            $comment = (string) ($fields['arrival_obs'] ?? '');
        }
        if ($comment === '') {
            $comment = (string) ($fields['comment_validation'] ?? '');
        }
        if ($comment === '') {
            $comment = (string) ($fields['reason'] ?? '');
        }

        $url = '';
        if ($id > 0) {
            $url = $this->formatURL(
                $options['additionnaloption']['usertype'] ?? self::GLPI_USER,
                'plugins/reservafrota/front/booking.form.php?id=' . $id
            );
        }

        $events = $this->getAllEvents();

        $this->data['##booking.id##']                 = $id;
        $this->data['##booking.name##']               = (string) ($fields['name'] ?? '');
        $this->data['##booking.code##']               = $code;
        $this->data['##booking.user##']               = $userName;
        $this->data['##booking.status##']             = Booking::getStatusName((int) ($fields['status'] ?? 0));
        $this->data['##booking.departure##']          = $this->formatBookingDate($fields['date_departure'] ?? null);
        $this->data['##booking.arrival##']            = $this->formatBookingDate($fields['date_arrival'] ?? null);
        $this->data['##booking.returned_at##']        = $this->formatBookingDate($fields['date_returned'] ?? null);
        $this->data['##booking.validation_date##']    = $this->formatBookingDate($fields['date_validation'] ?? null);
        $this->data['##booking.destination##']        = (string) ($fields['destination'] ?? '');
        $this->data['##booking.reason##']             = (string) ($fields['reason'] ?? '');
        $this->data['##booking.car##']                = $carName;
        $this->data['##booking.driver##']             = $driverName;
        $this->data['##booking.companion##']          = $companion;
        $this->data['##booking.comment##']            = $comment;
        $this->data['##booking.validation_comment##'] = (string) ($fields['comment_validation'] ?? '');
        $this->data['##booking.arrival_obs##']        = (string) ($fields['arrival_obs'] ?? '');
        $this->data['##booking.approver##']           = $approverName;
        $this->data['##booking.km_initial##']         = $fields['km_initial'] ?? '';
        $this->data['##booking.km_final##']           = $fields['km_final'] ?? '';
        $this->data['##booking.action##']             = $events[$event] ?? $event;
        $this->data['##booking.url##']                = $url;
        // Só na aprovação: o modelo é único e usa ##IFbooking.form_url##.
        $this->data['##booking.form_url##']           = $event === 'approved' ? SheetSync::getDriverFormLink($code) : '';
    }

    /**
     * Formata data/hora para o template. O NotificationTarget do core não
     * expõe formatDate(), então usamos o helper nativo do GLPI.
     */
    private function formatBookingDate($value): string
    {
        $value = trim((string) $value);
        if ($value === '' || $value === '0000-00-00 00:00:00') {
            return '-';
        }

        return Html::convDateTime($value);
    }

    /**
     * Tags exibidas no editor de templates.
     */
    public function getTags()
    {
        foreach ([
            'booking.id'                 => __('ID da reserva', 'reservafrota'),
            'booking.name'               => __('Nome da reserva', 'reservafrota'),
            'booking.code'               => __('Código da reserva', 'reservafrota'),
            'booking.user'               => __('Solicitante', 'reservafrota'),
            'booking.status'             => __('Status', 'reservafrota'),
            'booking.departure'          => __('Saída', 'reservafrota'),
            'booking.arrival'            => __('Chegada', 'reservafrota'),
            'booking.returned_at'        => __('Retorno registrado', 'reservafrota'),
            'booking.validation_date'    => __('Data da aprovação/recusa', 'reservafrota'),
            'booking.destination'        => __('Destino', 'reservafrota'),
            'booking.reason'             => __('Motivo', 'reservafrota'),
            'booking.car'                => __('Veículo', 'reservafrota'),
            'booking.driver'             => __('Motorista', 'reservafrota'),
            'booking.companion'          => __('Acompanhante', 'reservafrota'),
            'booking.comment'            => __('Observação', 'reservafrota'),
            'booking.validation_comment' => __('Comentário da aprovação/recusa', 'reservafrota'),
            'booking.arrival_obs'        => __('Observação da chegada', 'reservafrota'),
            'booking.approver'           => __('Aprovador', 'reservafrota'),
            'booking.km_initial'         => __('KM inicial', 'reservafrota'),
            'booking.km_final'           => __('KM final', 'reservafrota'),
            'booking.action'             => __('Ação/evento', 'reservafrota'),
            'booking.url'                => __('Link da reserva', 'reservafrota'),
            'booking.form_url'           => __('Link do formulário de saída/chegada', 'reservafrota'),
        ] as $tag => $label) {
            $this->addTagToList([
                'tag'   => str_replace('##', '', $tag),
                'label' => $label,
            ]);
        }

        return parent::getTags();
    }

    /**
     * IDs dos usuários ativos com o bit Booking::APPROVE.
     *
     * @return list<int>
     */
    private function getManagerUserIds(): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $ids = [];
        if (!isset($DB) || !($DB instanceof \DBmysql)) {
            return $ids;
        }

        try {
            $iterator = $DB->request([
                'SELECT' => ['u.id', 'pr.rights'],
                'FROM'   => 'glpi_profilerights AS pr',
                'INNER JOIN' => [
                    'glpi_profiles_users AS pu' => [
                        'ON' => ['pu' => 'profiles_id', 'pr' => 'profiles_id'],
                    ],
                    'glpi_users AS u' => [
                        'ON' => ['u' => 'id', 'pu' => 'users_id'],
                    ],
                ],
                'WHERE' => [
                    'pr.name'      => 'reservafrota::booking',
                    'u.is_active'  => 1,
                    'u.is_deleted' => 0,
                ],
            ]);

            foreach ($iterator as $row) {
                if (((int) ($row['rights'] ?? 0) & Booking::APPROVE) === 0) {
                    continue;
                }
                $id = (int) ($row['id'] ?? 0);
                if ($id > 0) {
                    $ids[$id] = $id;
                }
            }
        } catch (\Throwable $e) {
            return [];
        }

        return array_values($ids);
    }

    private function formatDate($value): string
    {
        $value = trim((string) $value);
        return $value === '' ? '' : Html::convDateTime($value);
    }
}
