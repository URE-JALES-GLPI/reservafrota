<?php

namespace GlpiPlugin\Reservafrota;

use CommonDBTM;
use Glpi\Application\View\TemplateRenderer;
use Html;
use Plugin;
use Session;

/**
 * Agendamento de um carro da frota.
 *
 * Fluxo: o usuário comum cria (status "Pendente"); o administrador aprova
 * ou recusa. A hora de chegada é opcional.
 */
class Booking extends CommonDBTM
{
    public static $rightname = 'reservafrota::booking';

    public $dohistory = true;

    // Status do agendamento.
    public const STATUS_PENDING   = 1;
    public const STATUS_APPROVED  = 2;
    public const STATUS_REJECTED  = 3;
    public const STATUS_CANCELLED = 4;
    public const STATUS_ARRIVED   = 5;

    // Direito específico de aprovação (bit fora da faixa padrão 1..128).
    public const APPROVE = 1024;

    public static function getTypeName($nb = 0)
    {
        return _n('Reserva', 'Reservas', $nb, 'reservafrota');
    }

    public static function getIcon()
    {
        return 'ti ti-calendar-clock';
    }

    /**
     * Direitos disponíveis para o itemtype (inclui o de aprovação).
     */
    public function getRights($interface = 'central')
    {
        $rights = parent::getRights();
        $rights[self::APPROVE] = __('Aprovar agendamentos', 'reservafrota');
        return $rights;
    }

    /**
     * O usuário atual pode aprovar/recusar agendamentos?
     */
    public static function canApprove(): bool
    {
        return (bool) Session::haveRight(self::$rightname, self::APPROVE);
    }

    /**
     * Rótulos dos status.
     *
     * @return array<int, string>
     */
    public static function getStatuses(): array
    {
        return [
            self::STATUS_PENDING   => __('Pendente', 'reservafrota'),
            self::STATUS_APPROVED  => __('Aprovado', 'reservafrota'),
            self::STATUS_REJECTED  => __('Recusado', 'reservafrota'),
            self::STATUS_CANCELLED => __('Cancelado', 'reservafrota'),
            self::STATUS_ARRIVED   => __('Concluído', 'reservafrota'),
        ];
    }

    public static function getStatusName(int $status): string
    {
        return self::getStatuses()[$status] ?? (string) $status;
    }

    /**
     * Restringe a listagem: quem não pode aprovar só vê os próprios
     * agendamentos. Método consultado automaticamente pelo motor de busca.
     */
    public static function addDefaultWhere(): string
    {
        if (self::canApprove()) {
            return '';
        }
        $uid = (int) Session::getLoginUserID();
        return self::getTable() . ".users_id = $uid";
    }

    /**
     * Normaliza um valor de datetime-local (ex.: 2026-06-09T08:30) para o
     * formato do banco (Y-m-d H:i:s). Retorna null se vazio.
     */
    private static function normalizeDatetime($value): ?string
    {
        if (empty($value)) {
            return null;
        }
        $value = str_replace('T', ' ', trim($value));
        if (strlen($value) === 16) { // sem segundos
            $value .= ':00';
        }
        return $value;
    }

    public function prepareInputForAdd($input)
    {
        // Solicitante: por padrão o usuário logado.
        if (empty($input['users_id'])) {
            $input['users_id'] = (int) Session::getLoginUserID();
        }

        // Datas.
        $input['date_departure'] = self::normalizeDatetime($input['date_departure'] ?? null);
        $input['date_arrival']   = self::normalizeDatetime($input['date_arrival'] ?? null); // opcional

        // Validações obrigatórias. O carro NÃO é mais escolhido pelo
        // solicitante — quem designa o carro é o gestor, ao aprovar.
        if (empty($input['date_departure'])) {
            Session::addMessageAfterRedirect(__('Informe a data e hora de saída.', 'reservafrota'), false, ERROR);
            return false;
        }

        // Solicitante não pode agendar em data passada; o gestor pode
        // (ex.: para registrar um uso que já aconteceu).
        if (!self::canApprove() && substr($input['date_departure'], 0, 10) < date('Y-m-d')) {
            Session::addMessageAfterRedirect(__('Não é possível agendar em uma data anterior a hoje.', 'reservafrota'), false, ERROR);
            return false;
        }

        // Novo agendamento sempre começa pendente.
        $input['status']            = self::STATUS_PENDING;
        $input['users_id_approver'] = 0;
        $input['date_validation']   = null;

        // Nome automático (motorista/solicitante — data) se não informado;
        // o carro ainda não existe neste momento.
        if (empty($input['name'])) {
            $who = trim((string) ($input['driver'] ?? '')) ?: __('Agendamento', 'reservafrota');
            $input['name'] = $who . ' — ' . Html::convDateTime($input['date_departure']);
        }

        // Validação de veículo: obrigatório para usuário comum, verifica manutenção e conflito
        $carId = (int) ($input['plugin_reservafrota_cars_id'] ?? 0);
        if ($carId === 0 && !self::canApprove()) {
            Session::addMessageAfterRedirect(__('Selecione o veículo que deseja reservar.', 'reservafrota'), false, ERROR);
            return false;
        }
        if ($carId > 0) {
            $car = new Car();
            if ($car->getFromDB($carId) && !(int) $car->fields['is_active']) {
                Session::addMessageAfterRedirect(__('Este veículo está em manutenção (inativo) e não pode ser reservado.', 'reservafrota'), false, ERROR);
                return false;
            }
            $day = substr($input['date_departure'], 0, 10);
            $conflicts = self::getBookingsForCarOnDate($carId, $day);
            if (count($conflicts) > 0) {
                $newStart = strtotime($input['date_departure']);
                $newEnd   = !empty($input['date_arrival']) ? strtotime($input['date_arrival']) : ($newStart + 3600);
                $overlap  = null;
                foreach ($conflicts as $c) {
                    $cStart = strtotime((string) $c['date_departure']);
                    $cEnd   = !empty($c['date_arrival']) ? strtotime((string) $c['date_arrival']) : ($cStart + 3600);
                    if ($newStart < $cEnd && $cStart < $newEnd) {
                        $overlap = $c;
                        break;
                    }
                }
                if ($overlap !== null) {
                    if (!self::canApprove()) {
                        Session::addMessageAfterRedirect(
                            sprintf(
                                __('Conflito: este veículo já está reservado neste horário (%s). Escolha outro veículo ou horário.', 'reservafrota'),
                                substr((string) $overlap['date_departure'], 11, 5)
                                . (!empty($overlap['date_arrival']) ? ' → ' . substr((string) $overlap['date_arrival'], 11, 5) : '')
                            ),
                            false,
                            ERROR
                        );
                        return false;
                    }
                    Session::addMessageAfterRedirect(
                        sprintf(
                            __('Conflito: este carro já está agendado neste horário (%s). O pedido foi registrado mesmo assim, mas pode ser recusado.', 'reservafrota'),
                            substr((string) $overlap['date_departure'], 11, 5)
                            . (!empty($overlap['date_arrival']) ? ' → ' . substr((string) $overlap['date_arrival'], 11, 5) : '')
                        ),
                        true,
                        WARNING
                    );
                } else {
                    Session::addMessageAfterRedirect(
                        __('Atenção: este carro já possui outro agendamento neste dia. O pedido foi registrado para análise do administrador.', 'reservafrota'),
                        true,
                        WARNING
                    );
                }
            }
        }

        return $input;
    }

    public function prepareInputForUpdate($input)
    {
        if (isset($input['date_departure'])) {
            $input['date_departure'] = self::normalizeDatetime($input['date_departure']);
        }
        if (array_key_exists('date_arrival', $input)) {
            $input['date_arrival'] = self::normalizeDatetime($input['date_arrival']);
        }

        // Apenas quem tem o direito de aprovar pode mudar o status manualmente.
        if (isset($input['status']) && !self::canApprove()) {
            unset($input['status'], $input['users_id_approver'], $input['date_validation']);
        }

        return $input;
    }

    /**
     * Aprova o agendamento (exige direito de aprovação). O carro é
     * designado neste momento pelo gestor — $carId é obrigatório quando o
     * agendamento ainda não tem um carro definido.
     */
    public function approve(string $comment = '', ?int $carId = null): bool
    {
        if (!self::canApprove()) {
            Session::addMessageAfterRedirect(__('Você não tem permissão para aprovar.', 'reservafrota'), false, ERROR);
            return false;
        }

        $carId = $carId ?: (int) ($this->fields['plugin_reservafrota_cars_id'] ?? 0);
        if ($carId <= 0) {
            Session::addMessageAfterRedirect(__('Escolha um carro para aprovar este agendamento.', 'reservafrota'), false, ERROR);
            return false;
        }

        $update = [
            'id'                         => $this->fields['id'],
            'status'                     => self::STATUS_APPROVED,
            'plugin_reservafrota_cars_id'  => $carId,
            'users_id_approver'          => (int) Session::getLoginUserID(),
            'date_validation'            => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
            'comment_validation'         => $comment,
        ];

        // Nome automático ainda não tinha carro na criação — completa agora.
        $car = new Car();
        if ($car->getFromDB($carId)) {
            $update['name'] = $car->fields['name'] . ' — ' . Html::convDateTime((string) $this->fields['date_departure']);
        }

        return $this->update($update);
    }

    /**
     * Recusa o agendamento (exige direito de aprovação).
     */
    public function reject(string $comment = ''): bool
    {
        if (!self::canApprove()) {
            Session::addMessageAfterRedirect(__('Você não tem permissão para recusar.', 'reservafrota'), false, ERROR);
            return false;
        }
        return $this->update([
            'id'                 => $this->fields['id'],
            'status'             => self::STATUS_REJECTED,
            'users_id_approver'  => (int) Session::getLoginUserID(),
            'date_validation'    => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
            'comment_validation' => $comment,
        ]);
    }

    /**
     * O usuário atual pode cancelar este agendamento?
     * (o dono do pedido, ou quem tem direito de aprovar). Não cancela o que
     * já está cancelado/recusado.
     */
    public function canCancel(): bool
    {
        $st = (int) ($this->fields['status'] ?? 0);
        if (in_array($st, [self::STATUS_REJECTED, self::STATUS_CANCELLED], true)) {
            return false;
        }
        if (self::canApprove()) {
            return true;
        }
        return (int) ($this->fields['users_id'] ?? 0) === (int) Session::getLoginUserID();
    }

    /**
     * Cancela o agendamento exigindo um motivo. O dono pode cancelar o
     * próprio pedido; o aprovador pode cancelar qualquer um.
     */
    public function cancel(string $reason): bool
    {
        if (!$this->canCancel()) {
            Session::addMessageAfterRedirect(__('Você não pode cancelar este agendamento.', 'reservafrota'), false, ERROR);
            return false;
        }
        if (trim($reason) === '') {
            Session::addMessageAfterRedirect(__('Informe o motivo do cancelamento.', 'reservafrota'), false, ERROR);
            return false;
        }
        return $this->update([
            'id'                 => $this->fields['id'],
            'status'             => self::STATUS_CANCELLED,
            'users_id_approver'  => (int) Session::getLoginUserID(),
            'date_validation'    => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
            'comment_validation' => $reason,
        ]);
    }

    /**
     * Marca que o carro voltou da viagem (chegada confirmada pelo aprovador).
     */
    public function markReturned(?string $sheet = null, ?string $when = null, ?string $obs = null, ?int $km = null): bool
    {
        if (!self::canApprove()) {
            Session::addMessageAfterRedirect(__('Você não tem permissão para confirmar a chegada.', 'reservafrota'), false, ERROR);
            return false;
        }
        if (!in_array((int) ($this->fields['status'] ?? 0), [self::STATUS_APPROVED, self::STATUS_ARRIVED], true)) {
            Session::addMessageAfterRedirect(__('Só agendamentos aprovados podem ser marcados como chegada.', 'reservafrota'), false, ERROR);
            return false;
        }
        $returned = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        if ($when !== null && $when !== '') {
            $ts = strtotime($when);
            if ($ts !== false) {
                $returned = date('Y-m-d H:i:s', $ts);
            }
        }
        $update = [
            'id'            => $this->fields['id'],
            'date_returned' => $returned,
            'status'        => self::STATUS_ARRIVED,
        ];
        if ($sheet !== null && $sheet !== '') {
            $update['arrival_sheet'] = $sheet;
        }
        if ($obs !== null) {
            $update['arrival_obs'] = $obs;
        }
        if ($km !== null) {
            $update['km_final'] = $km;
        }
        $ok = $this->update($update);

        // Se houver observação, ela é registrada no HISTÓRICO do carro
        // (não altera o campo "Observações" do carro).
        if ($ok && $obs !== null && trim($obs) !== '') {
            $carId = (int) ($this->fields['plugin_reservafrota_cars_id'] ?? 0);
            if ($carId > 0) {
                $msg = sprintf(
                    __('Observação de viagem (agendamento #%d): %s', 'reservafrota'),
                    (int) $this->fields['id'],
                    $obs
                );
                \Log::history($carId, Car::class, [0, '', $msg], '', \Log::HISTORY_LOG_SIMPLE_MESSAGE);
            }
        }
        return $ok;
    }

    /**
     * Guarda a folha de agendamento enviada na chegada e devolve o nome do
     * arquivo salvo (ou null se não houver arquivo / for inválido).
     *
     * @param array $file Entrada de $_FILES.
     */
    public static function storeArrivalSheet(int $id, array $file): ?string
    {
        if (empty($file['tmp_name']) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return null;
        }
        if (!is_uploaded_file($file['tmp_name']) || ($file['size'] ?? 0) > 15 * 1024 * 1024) {
            return null;
        }
        $ext     = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        $allowed = ['pdf', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'doc', 'docx', 'xls', 'xlsx', 'odt', 'ods'];
        if (!in_array($ext, $allowed, true)) {
            Session::addMessageAfterRedirect(__('Formato de arquivo não permitido para a folha.', 'reservafrota'), false, WARNING);
            return null;
        }
        $dir = GLPI_DOC_DIR . '/_plugins/reservafrota';
        if (!is_dir($dir) && !@mkdir($dir, 0o755, true) && !is_dir($dir)) {
            return null;
        }
        try {
            $base = $id . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        } catch (\Exception $e) {
            $base = $id . '_' . date('YmdHis') . '_' . uniqid('', true) . '.' . $ext;
        }
        if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $base)) {
            return null;
        }
        return $base;
    }

    /**
     * Replica o agendamento nos demais dias da mesma semana indicados em
     * $weekdays (1=Seg … 7=Dom), mantendo carro/horário/destino. Usado quando
     * a pessoa marca mais de um dia da semana de uma vez.
     *
     * @param array $base     Dados-base do agendamento (ex.: o $_POST).
     * @param array $weekdays Dias da semana selecionados (1..7).
     * @return int Quantidade de agendamentos criados.
     */
    public static function createWeekRepeats(array $base, array $weekdays): int
    {
        $dep = self::normalizeDatetime($base['date_departure'] ?? null);
        if (!$dep) {
            return 0;
        }
        $baseDate = substr($dep, 0, 10);
        $depTime  = substr($dep, 11, 8) ?: '08:00:00';
        $arr      = self::normalizeDatetime($base['date_arrival'] ?? null);
        $arrTime  = $arr ? substr($arr, 11, 8) : null;

        $baseN  = (int) date('N', strtotime($baseDate));      // 1=Seg … 7=Dom
        $monday = date('Y-m-d', strtotime($baseDate . ' -' . ($baseN - 1) . ' days'));

        $created = 0;
        foreach (array_unique(array_map('intval', $weekdays)) as $wd) {
            if ($wd < 1 || $wd > 7) {
                continue;
            }
            $d = date('Y-m-d', strtotime($monday . ' +' . ($wd - 1) . ' days'));
            if ($d === $baseDate || $d < date('Y-m-d')) {
                continue; // o dia-base já foi criado; e não repete em dias passados
            }
            $rep = $base;
            unset($rep['id'], $rep['_repeat_weekdays'], $rep['name']);
            $rep['date_departure'] = $d . ' ' . $depTime;
            if ($arrTime !== null) {
                $rep['date_arrival'] = $d . ' ' . $arrTime;
            }
            $b = new self();
            if ($b->add($rep)) {
                $created++;
            }
        }
        return $created;
    }

    /**
     * Agendamentos (não recusados) de um carro que tocam um determinado dia.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function getBookingsForCarOnDate(int $cars_id, string $date): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $rows = [];
        $iterator = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => [
                'plugin_reservafrota_cars_id' => $cars_id,
                'is_deleted'                => 0,
                'status' => ['NOT IN', [
    self::STATUS_REJECTED,
    self::STATUS_CANCELLED,
    self::STATUS_ARRIVED
]],
                'date_departure'            => ['<=', "$date 23:59:59"],
                'OR' => [
                    // Sem chegada informada: considera ocupado no dia da saída.
                    [
                        'date_arrival'   => null,
                        'date_departure' => ['>=', "$date 00:00:00"],
                    ],
                    // Com chegada: o intervalo cruza o dia.
                    [
                        'date_arrival' => ['>=', "$date 00:00:00"],
                    ],
                ],
            ],
            'ORDER' => 'date_departure ASC',
        ]);
        foreach ($iterator as $row) {
            $rows[(int) $row['id']] = $row;
        }
        return $rows;
    }

    /**
     * Lista os carros ativos indicando quais já estão indisponíveis
     * (têm outro agendamento APROVADO com sobreposição de horário) no
     * intervalo informado. Usado na tela de escolha do carro ao aprovar.
     *
     * @return list<array{id:int,name:string,plate:string,blocked:bool}>
     */
    public static function getCarAvailabilityForSlot(string $departure, ?string $arrival, int $excludeId = 0): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $start = strtotime($departure);
        $end   = $arrival ? strtotime($arrival) : ($start + 3600);
        $day   = substr($departure, 0, 10);

        $iterator = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => [
                'is_deleted' => 0,
                'status'     => self::STATUS_APPROVED,
                'id'         => ['<>', $excludeId],
                'date_departure' => ['<=', "$day 23:59:59"],
                'OR' => [
                    ['date_arrival' => null, 'date_departure' => ['>=', "$day 00:00:00"]],
                    ['date_arrival' => ['>=', "$day 00:00:00"]],
                ],
            ],
        ]);

        $busy = [];
        foreach ($iterator as $row) {
            $cid = (int) $row['plugin_reservafrota_cars_id'];
            if ($cid <= 0) {
                continue;
            }
            $s = strtotime((string) $row['date_departure']);
            $e = !empty($row['date_arrival']) ? strtotime((string) $row['date_arrival']) : ($s + 3600);
            if ($start < $e && $s < $end) {
                $busy[$cid] = true;
            }
        }

        $out = [];
        foreach (Car::getActiveCars() as $cid => $car) {
            $out[] = [
                'id'      => (int) $cid,
                'name'    => $car['name'],
                'plate'   => $car['plate'],
                'blocked' => !empty($busy[(int) $cid]),
            ];
        }
        return $out;
    }

    /**
     * Situação de cada carro ativo em um dia: livre, pendente ou em uso,
     * com a lista de pessoas e horários. Alimenta a página de agenda e o AJAX.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function getCarsStatusForDate(string $date): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $cars = Car::getActiveCars();

        // Inicializa cada carro como livre.
        $result = [];
        foreach ($cars as $cid => $car) {
            $picture = null;
            if (!empty($car['picture'])) {
                $picture = Plugin::getWebDir('reservafrota')
                    . '/front/car.picture.php?id=' . (int) $cid;
            }
            $result[$cid] = [
                'id'         => (int) $cid,
                'name'       => $car['name'],
                'plate'      => $car['plate'],
                'model_year' => (int) $car['model_year'],
                'picture'    => $picture,
                'status'     => 'free', // free | pending | approved
                'bookings'   => [],
            ];
        }

        if (empty($cars)) {
            return [];
        }

        // Busca todos os agendamentos do dia, já com o nome do solicitante.
        $iterator = $DB->request([
            'SELECT' => [
                'b.id',
                'b.plugin_reservafrota_cars_id',
                'b.users_id',
                'b.date_departure',
                'b.date_arrival',
                'b.destination',
                'b.status',
                'u.firstname',
                'u.realname',
                'u.name AS user_login',
            ],
            'FROM'      => self::getTable() . ' AS b',
            'LEFT JOIN' => [
                'glpi_users AS u' => [
                    'ON' => ['b' => 'users_id', 'u' => 'id'],
                ],
            ],
            'WHERE' => [
                'b.is_deleted'     => 0,
                'b.status' => ['NOT IN', [
    self::STATUS_REJECTED,
    self::STATUS_CANCELLED,
    self::STATUS_ARRIVED
]],
                'b.date_departure' => ['<=', "$date 23:59:59"],
                'OR' => [
                    [
                        'b.date_arrival'   => null,
                        'b.date_departure' => ['>=', "$date 00:00:00"],
                    ],
                    [
                        'b.date_arrival' => ['>=', "$date 00:00:00"],
                    ],
                ],
            ],
            'ORDER' => 'b.date_departure ASC',
        ]);

        foreach ($iterator as $row) {
            $cid = (int) $row['plugin_reservafrota_cars_id'];
            if (!isset($result[$cid])) {
                continue; // carro inativo/excluído
            }

            // Nome de exibição do solicitante.
            $person = trim(($row['firstname'] ?? '') . ' ' . ($row['realname'] ?? ''));
            if ($person === '') {
                $person = $row['user_login'] ?? __('Usuário', 'reservafrota');
            }

            $status = (int) $row['status'];
            $result[$cid]['bookings'][] = [
                'id'          => (int) $row['id'],
                'user'        => $person,
                'departure'   => $row['date_departure'],
                'arrival'     => $row['date_arrival'],
                'destination' => $row['destination'],
                'status'      => $status,
                'status_label'=> self::getStatusName($status),
            ];

            // "Em uso" (approved) tem prioridade visual sobre "pendente".
            if ($status === self::STATUS_APPROVED) {
                $result[$cid]['status'] = 'approved';
            } elseif ($result[$cid]['status'] !== 'approved') {
                $result[$cid]['status'] = 'pending';
            }
        }

        return array_values($result);
    }

    /**
     * Intervalo [início, fim] de um mês no formato 'YYYY-MM'.
     *
     * @return array{0:string,1:string}
     */
    public static function getMonthRange(string $month): array
    {
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            $month = date('Y-m');
        }
        $start = $month . '-01 00:00:00';
        $end   = date('Y-m-t 23:59:59', strtotime($month . '-01'));
        return [$start, $end];
    }

    /**
     * Dados de análise de um mês: total de viagens, uso por setor,
     * uso por carro e contagem por status.
     *
     * @return array<string, mixed>
     */
    public static function getAnalyticsForMonth(string $month): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        [$start, $end] = self::getMonthRange($month);

        $iterator = $DB->request([
            'SELECT' => [
                'b.id', 'b.status', 'b.groups_id', 'b.plugin_reservafrota_cars_id',
                'g.name AS sector', 'c.name AS car',
            ],
            'FROM'      => self::getTable() . ' AS b',
            'LEFT JOIN' => [
                'glpi_groups AS g'        => ['ON' => ['b' => 'groups_id', 'g' => 'id']],
                Car::getTable() . ' AS c' => ['ON' => ['b' => 'plugin_reservafrota_cars_id', 'c' => 'id']],
            ],
            'WHERE' => [
                'b.is_deleted' => 0,
                ['b.date_departure' => ['>=', $start]],
                ['b.date_departure' => ['<=', $end]],
            ],
            'ORDER' => 'b.date_departure ASC',
        ]);

        $by_sector = [];
        $by_car    = [];
        $status    = [
            self::STATUS_PENDING  => 0,
            self::STATUS_APPROVED => 0,
            self::STATUS_REJECTED => 0,
        ];
        $total = 0; // viagens efetivas (não recusadas)

        foreach ($iterator as $row) {
            $st = (int) $row['status'];
            $status[$st] = ($status[$st] ?? 0) + 1;

            if ($st === self::STATUS_REJECTED) {
                continue;
            }
            $total++;

            $sector = ($row['sector'] !== null && $row['sector'] !== '')
                ? $row['sector'] : __('Sem setor', 'reservafrota');
            $car = ($row['car'] !== null && $row['car'] !== '')
                ? $row['car'] : __('Sem carro', 'reservafrota');

            $by_sector[$sector] = ($by_sector[$sector] ?? 0) + 1;
            $by_car[$car]       = ($by_car[$car] ?? 0) + 1;
        }

        arsort($by_sector);
        arsort($by_car);

        $requests = array_sum($status);

        return [
            'month'         => $month,
            'total'         => $total,
            'requests'      => $requests,
            'by_sector'     => $by_sector,
            'by_car'        => $by_car,
            'status'        => $status,
            'top_sector'    => $total > 0 ? array_key_first($by_sector) : null,
            'top_car'       => $total > 0 ? array_key_first($by_car) : null,
            'approval_rate' => $requests > 0
                ? round($status[self::STATUS_APPROVED] / $requests * 100)
                : 0,
        ];
    }

    /**
     * Agendamentos de um mês para o calendário, já prontos para exibição.
     *
     * @return list<array<string, mixed>>
     */
    public static function getBookingsForMonth(string $month, bool $expand = true): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        [$start, $end] = self::getMonthRange($month);

        $iterator = $DB->request([
            'SELECT' => [
                'b.id', 'b.date_departure', 'b.date_arrival', 'b.destination',
                'b.reason', 'b.status', 'b.driver', 'b.has_companion', 'b.companion', 'b.users_id', 'b.date_returned',
                'b.comment_validation', 'b.date_validation',
                'b.arrival_sheet', 'b.arrival_obs',
                'b.plugin_reservafrota_cars_id AS car_id',
                'b.groups_id',
                'c.name AS car',
                'u.name AS user_login', 'u.realname AS realname', 'u.firstname AS firstname',
                'au.name AS ap_login', 'au.realname AS ap_realname', 'au.firstname AS ap_firstname',
                'g.name AS sector',
            ],
            'FROM'      => self::getTable() . ' AS b',
            'LEFT JOIN' => [
                Car::getTable() . ' AS c' => ['ON' => ['b' => 'plugin_reservafrota_cars_id', 'c' => 'id']],
                'glpi_users AS u'         => ['ON' => ['b' => 'users_id', 'u' => 'id']],
                'glpi_users AS au'        => ['ON' => ['b' => 'users_id_approver', 'au' => 'id']],
                'glpi_groups AS g'        => ['ON' => ['b' => 'groups_id', 'g' => 'id']],
            ],
            'WHERE' => [
                'b.is_deleted' => 0,
                ['b.date_departure' => ['<=', $end]],
                ['OR' => [
                    ['b.date_arrival' => ['>=', $start]],
                    ['AND' => [
                        ['b.date_arrival'   => null],
                        ['b.date_departure' => ['>=', $start]],
                    ]],
                ]],
            ],
            'ORDER' => 'b.date_departure ASC',
        ]);

        $monthStartDate = substr($start, 0, 10);
        $monthEndDate   = substr($end, 0, 10);
        $uid            = (int) Session::getLoginUserID();
        $can_approve    = self::canApprove();

        // Carrega tudo e calcula conflitos de horário por carro.
        $rows = [];
        $confInput = [];
        foreach ($iterator as $row) {
            $rows[] = $row;
            $s = strtotime((string) $row['date_departure']);
            $e = !empty($row['date_arrival']) ? strtotime((string) $row['date_arrival']) : ($s + 3600);
            $confInput[] = ['id' => (int) $row['id'], 'car_id' => (int) $row['car_id'], 'start' => $s, 'end' => $e, 'status' => (int) $row['status']];
        }
        $conflicts = self::markConflicts($confInput);

        $out = [];
        foreach ($rows as $row) {
            $name = trim(($row['firstname'] ?? '') . ' ' . ($row['realname'] ?? ''));
            if ($name === '') {
                $name = $row['user_login'] ?? '';
            }
            $apName = trim(($row['ap_firstname'] ?? '') . ' ' . ($row['ap_realname'] ?? ''));
            if ($apName === '') {
                $apName = $row['ap_login'] ?? '';
            }
            $dep      = (string) $row['date_departure'];
            $st       = (int) $row['status'];
            $returned = !empty($row['date_returned']);
            $depDate  = substr($dep, 0, 10);
            $arrDate  = !empty($row['date_arrival']) ? substr((string) $row['date_arrival'], 0, 10) : $depDate;

            $base = [
                'id'           => (int) $row['id'],
                'car'          => $row['car'] ?: __('Sem carro', 'reservafrota'),
                'user'         => $name,
                'sector'       => $row['sector'] ?: __('Sem setor', 'reservafrota'),
                'driver'       => $row['driver'] ?? '',
                'has_companion'=> (int) ($row['has_companion'] ?? 0),
                'companion'    => $row['companion'] ?? '',
                'departure'    => $dep,
                'arrival'      => $row['date_arrival'],
                'destination'  => $row['destination'] ?: '',
                'reason'       => $row['reason'] ?: '',
                'note'         => $row['comment_validation'] ?: '',
                'cancelled_by' => $apName,
                'cancelled_at' => $row['date_validation'],
                'returned_at'  => $row['date_returned'],
                'has_sheet'    => !empty($row['arrival_sheet']),
                'obs'          => $row['arrival_obs'] ?: '',
                'conflict'     => !empty($conflicts[(int) $row['id']]),
                'status'       => $st,
                'status_label' => self::getStatusName($st),
                'car_id'       => (int) $row['car_id'],
                'groups_id'    => (int) $row['groups_id'],
                'can_cancel'   => ($can_approve || (int) $row['users_id'] === $uid)
                                    && !$returned
                                    && !in_array($st, [self::STATUS_REJECTED, self::STATUS_CANCELLED, self::STATUS_ARRIVED], true),
            ];

            // Sem expansão (ex.: tabela da Análise): uma linha por agendamento.
            if (!$expand) {
                if ($depDate >= $monthStartDate && $depDate <= $monthEndDate) {
                    $base['date'] = $depDate;
                    $base['day']  = (int) substr($depDate, 8, 2);
                    $base['span'] = false;
                    $out[] = $base;
                }
                continue;
            }

            // Com expansão (calendário): recusados/cancelados só no dia da saída;
            // os demais preenchem o intervalo (saída → chegada) dentro do mês.
            $multi = !in_array($st, [self::STATUS_REJECTED, self::STATUS_CANCELLED, self::STATUS_ARRIVED], true);
            $days  = [];
            if ($multi) {
                $from = $depDate > $monthStartDate ? $depDate : $monthStartDate;
                $to   = $arrDate < $monthEndDate ? $arrDate : $monthEndDate;
                for ($d = $from; $d <= $to; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
                    $days[] = $d;
                }
            } elseif ($depDate >= $monthStartDate && $depDate <= $monthEndDate) {
                $days[] = $depDate;
            }
            $span = count($days) > 1;
            foreach ($days as $d) {
                $out[] = array_merge($base, [
                    'date' => $d,
                    'day'  => (int) substr($d, 8, 2),
                    'span' => $span,
                ]);
            }
        }
        return $out;
    }

    /**
     * Agendamentos de um ano inteiro, em ordem cronológica (para o filtro anual).
     *
     * @return list<array<string, mixed>>
     */
    public static function getBookingsForYear(int $year): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $start = sprintf('%04d-01-01 00:00:00', $year);
        $end   = sprintf('%04d-12-31 23:59:59', $year);

        $iterator = $DB->request([
            'SELECT' => [
                'b.id', 'b.date_departure', 'b.date_arrival', 'b.destination',
                'b.reason', 'b.status', 'b.driver', 'b.has_companion', 'b.companion', 'b.comment_validation',
                'c.name AS car',
                'u.name AS user_login', 'u.realname AS realname', 'u.firstname AS firstname',
                'g.name AS sector',
            ],
            'FROM'      => self::getTable() . ' AS b',
            'LEFT JOIN' => [
                Car::getTable() . ' AS c' => ['ON' => ['b' => 'plugin_reservafrota_cars_id', 'c' => 'id']],
                'glpi_users AS u'         => ['ON' => ['b' => 'users_id', 'u' => 'id']],
                'glpi_groups AS g'        => ['ON' => ['b' => 'groups_id', 'g' => 'id']],
            ],
            'WHERE' => [
                'b.is_deleted' => 0,
                ['b.date_departure' => ['>=', $start]],
                ['b.date_departure' => ['<=', $end]],
            ],
            'ORDER' => 'b.date_departure ASC',
        ]);

        $out = [];
        foreach ($iterator as $row) {
            $name = trim(($row['firstname'] ?? '') . ' ' . ($row['realname'] ?? ''));
            if ($name === '') {
                $name = $row['user_login'] ?? '';
            }
            $dep = (string) $row['date_departure'];
            $out[] = [
                'id'           => (int) $row['id'],
                'date'         => substr($dep, 0, 10),
                'month'        => (int) substr($dep, 5, 2),
                'car'          => $row['car'] ?: __('Sem carro', 'reservafrota'),
                'user'         => $name,
                'sector'       => $row['sector'] ?: __('Sem setor', 'reservafrota'),
                'driver'       => $row['driver'] ?? '',
                'has_companion'=> (int) ($row['has_companion'] ?? 0),
                'companion'    => $row['companion'] ?? '',
                'departure'    => $dep,
                'arrival'      => $row['date_arrival'],
                'destination'  => $row['destination'] ?: '',
                'reason'       => $row['reason'] ?: '',
                'cancel_reason'=> (int) $row['status'] === self::STATUS_CANCELLED ? ($row['comment_validation'] ?: '') : '',
                'status'       => (int) $row['status'],
                'status_label' => self::getStatusName((int) $row['status']),
            ];
        }
        return $out;
    }

    /**
     * Todos os agendamentos agrupados por status (pendentes, aprovados,
     * recusados), cada grupo ordenado por dia. Quem não pode aprovar vê
     * apenas os próprios pedidos.
     *
     * @return array{pending:list<array>,approved:list<array>,rejected:list<array>}
     */
    public static function getGroupedByStatus(): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $where = ['b.is_deleted' => 0];
        if (!self::canApprove()) {
            $where['b.users_id'] = (int) Session::getLoginUserID();
        }

        $iterator = $DB->request([
            'SELECT' => [
                'b.id', 'b.date_departure', 'b.date_arrival', 'b.destination',
                'b.reason', 'b.status', 'b.driver', 'b.has_companion', 'b.companion', 'b.date_returned', 'b.users_id',
                'b.comment_validation', 'b.date_validation',
                'b.arrival_sheet', 'b.arrival_obs', 'b.km_final',
                'b.plugin_reservafrota_cars_id AS car_id',
                'c.name AS car',
                'u.name AS user_login', 'u.realname AS realname', 'u.firstname AS firstname',
                'au.name AS ap_login', 'au.realname AS ap_realname', 'au.firstname AS ap_firstname',
                'g.name AS sector',
            ],
            'FROM'      => self::getTable() . ' AS b',
            'LEFT JOIN' => [
                Car::getTable() . ' AS c' => ['ON' => ['b' => 'plugin_reservafrota_cars_id', 'c' => 'id']],
                'glpi_users AS u'         => ['ON' => ['b' => 'users_id', 'u' => 'id']],
                'glpi_users AS au'        => ['ON' => ['b' => 'users_id_approver', 'au' => 'id']],
                'glpi_groups AS g'        => ['ON' => ['b' => 'groups_id', 'g' => 'id']],
            ],
            'WHERE' => $where,
            'ORDER' => 'b.id DESC',
        ]);

        $uid = (int) Session::getLoginUserID();
        $can_approve = self::canApprove();

        // Primeiro carrega tudo e calcula conflitos de horário.
        $rows = [];
        $confInput = [];
        $byId = [];
        foreach ($iterator as $row) {
            $rows[] = $row;
            $st = (int) $row['status'];
            $start = strtotime((string) $row['date_departure']);
            $end   = !empty($row['date_arrival']) ? strtotime((string) $row['date_arrival']) : ($start + 3600);
            $confInput[] = ['id' => (int) $row['id'], 'car_id' => (int) $row['car_id'], 'start' => $start, 'end' => $end, 'status' => $st];

            $nm = trim(($row['firstname'] ?? '') . ' ' . ($row['realname'] ?? ''));
            if ($nm === '') { $nm = $row['user_login'] ?? ''; }
            $byId[(int) $row['id']] = [
                'id'           => (int) $row['id'],
                'car'          => $row['car'] ?: __('Sem carro', 'reservafrota'),
                'user'         => $nm,
                'driver'       => $row['driver'] ?? '',
                'has_companion'=> (int) ($row['has_companion'] ?? 0),
                'companion'    => $row['companion'] ?? '',
                'departure'    => (string) $row['date_departure'],
                'arrival'      => $row['date_arrival'],
                'destination'  => $row['destination'] ?: '',
                'reason'       => $row['reason'] ?: '',
                'status'       => $st,
                'status_label' => self::getStatusName($st),
            ];
        }
        $conflicts = self::markConflicts($confInput);

        $groups = ['pending' => [], 'approved' => [], 'rejected' => [], 'cancelled' => [], 'arrived' => []];
        foreach ($rows as $row) {
            $name = trim(($row['firstname'] ?? '') . ' ' . ($row['realname'] ?? ''));
            if ($name === '') {
                $name = $row['user_login'] ?? '';
            }
            $apName = trim(($row['ap_firstname'] ?? '') . ' ' . ($row['ap_realname'] ?? ''));
            if ($apName === '') {
                $apName = $row['ap_login'] ?? '';
            }
            $dep = (string) $row['date_departure'];
            $st  = (int) $row['status'];
            $returned = !empty($row['date_returned']);
            $item = [
                'id'           => (int) $row['id'],
                'date'         => substr($dep, 0, 10),
                'car'          => $row['car'] ?: __('Sem carro', 'reservafrota'),
                'car_id'       => (int) $row['car_id'],
                'user'         => $name,
                'sector'       => $row['sector'] ?: __('Sem setor', 'reservafrota'),
                'driver'       => $row['driver'] ?? '',
                'has_companion'=> (int) ($row['has_companion'] ?? 0),
                'companion'    => $row['companion'] ?? '',
                'departure'    => $dep,
                'arrival'      => $row['date_arrival'],
                'destination'  => $row['destination'] ?: '',
                'reason'       => $row['reason'] ?: '',
                'note'         => $row['comment_validation'] ?: '',
                'returned_at'  => $row['date_returned'],
                'has_sheet'    => !empty($row['arrival_sheet']),
                'obs'          => $row['arrival_obs'] ?: '',
                'km_final'     => $row['km_final'] !== null ? (int) $row['km_final'] : null,
                'cancelled_by' => $apName,
                'cancelled_at' => $row['date_validation'],
                'conflict'     => !empty($conflicts[(int) $row['id']]),
                'conflict_with'=> array_values(array_filter(array_map(static function ($cid) use ($byId) { return $byId[$cid] ?? null; }, $conflicts[(int) $row['id']] ?? []))),
                'status'       => $st,
                'status_label' => self::getStatusName($st),
                // Não pode cancelar o que já chegou, foi recusado ou já está cancelado.
                'can_cancel'   => ($can_approve || (int) $row['users_id'] === $uid)
                                    && !$returned
                                    && !in_array($st, [self::STATUS_REJECTED, self::STATUS_CANCELLED, self::STATUS_ARRIVED], true),
            ];
            if ($st === self::STATUS_APPROVED) {
                $groups['approved'][] = $item;
            } elseif ($st === self::STATUS_REJECTED) {
                $groups['rejected'][] = $item;
            } elseif ($st === self::STATUS_CANCELLED) {
                $groups['cancelled'][] = $item;
            } elseif ($st === self::STATUS_ARRIVED) {
                $groups['arrived'][] = $item;
            } else {
                $groups['pending'][] = $item;
            }
        }
        return $groups;
    }

    /**
     * Quantidade de agendamentos pendentes (para notificar os aprovadores).
     */
    public static function countPending(): int
    {
        /** @var \DBmysql $DB */
        global $DB;

        return (int) countElementsInTable(self::getTable(), [
            'is_deleted' => 0,
            'status'     => self::STATUS_PENDING,
        ]);
    }

    /**
     * Histórico completo de agendamentos (mais recentes primeiro), com dados
     * de aprovação, chegada e cancelamento. Gestor vê tudo; usuário vê só os
     * próprios pedidos.
     *
     * @return list<array<string,mixed>>
     */
    public static function getHistory(): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $where = ['b.is_deleted' => 0];
        if (!self::canApprove()) {
            $where['b.users_id'] = (int) Session::getLoginUserID();
        }

        $iterator = $DB->request([
            'SELECT' => [
                'b.id', 'b.date_departure', 'b.date_arrival', 'b.status',
                'b.driver', 'b.has_companion', 'b.companion',
                'b.date_returned', 'b.date_validation', 'b.comment_validation',
                'b.arrival_sheet', 'b.arrival_obs',
                'c.name AS car',
                'u.name AS user_login', 'u.realname AS realname', 'u.firstname AS firstname',
                'au.name AS ap_login', 'au.realname AS ap_realname', 'au.firstname AS ap_firstname',
                'g.name AS sector',
            ],
            'FROM'      => self::getTable() . ' AS b',
            'LEFT JOIN' => [
                Car::getTable() . ' AS c' => ['ON' => ['b' => 'plugin_reservafrota_cars_id', 'c' => 'id']],
                'glpi_users AS u'         => ['ON' => ['b' => 'users_id', 'u' => 'id']],
                'glpi_users AS au'        => ['ON' => ['b' => 'users_id_approver', 'au' => 'id']],
                'glpi_groups AS g'        => ['ON' => ['b' => 'groups_id', 'g' => 'id']],
            ],
            'WHERE' => $where,
            'ORDER' => 'b.date_departure DESC',
        ]);

        $out = [];
        foreach ($iterator as $row) {
            $name = trim(($row['firstname'] ?? '') . ' ' . ($row['realname'] ?? ''));
            if ($name === '') {
                $name = $row['user_login'] ?? '';
            }
            $apName = trim(($row['ap_firstname'] ?? '') . ' ' . ($row['ap_realname'] ?? ''));
            if ($apName === '') {
                $apName = $row['ap_login'] ?? '';
            }
            $st  = (int) $row['status'];
            $dep = (string) $row['date_departure'];
            $out[] = [
                'id'           => (int) $row['id'],
                'date'         => substr($dep, 0, 10),
                'time'         => substr($dep, 11, 5),
                'departure'    => $dep,
                'arrival'      => $row['date_arrival'],
                'user'         => $name,
                'sector'       => $row['sector'] ?: __('Sem setor', 'reservafrota'),
                'driver'       => $row['driver'] ?? '',
                'has_companion'=> (int) ($row['has_companion'] ?? 0),
                'companion'    => $row['companion'] ?? '',
                'car'          => $row['car'] ?: __('Sem carro', 'reservafrota'),
                'status'       => $st,
                'status_label' => self::getStatusName($st),
                'returned_at'  => $row['date_returned'],
                'has_sheet'    => !empty($row['arrival_sheet']),
                'obs'          => $row['arrival_obs'] ?: '',
                'acted_by'     => $apName,
                'acted_at'     => $row['date_validation'],
                'note'         => $row['comment_validation'] ?: '',
            ];
        }
        return $out;
    }

    /**
     * Marca conflitos de horário entre agendamentos do mesmo carro.
     *
     * @param list<array{id:int,car_id:int,start:int,end:int,status:int}> $rows
     * @return array<int,bool> id => tem conflito
     */
    public static function markConflicts(array $rows): array
    {
        $conf   = [];
        $active = array_values(array_filter($rows, static function ($r) {
            return !in_array($r['status'], [
                self::STATUS_REJECTED,
                self::STATUS_CANCELLED,
                self::STATUS_ARRIVED
            ], true);
        }));

        foreach ($active as $a) {
            foreach ($active as $b) {
                if ($a['id'] === $b['id'] || $a['car_id'] <= 0 || $a['car_id'] !== $b['car_id']) {
                    continue;
                }
                if ($a['start'] < $b['end'] && $b['start'] < $a['end']) {
                    $conf[$a['id']][] = (int) $b['id'];
                }
            }
        }
        return $conf;
    }

    /**
     * Lista de usuários do GLPI para o campo Motorista: [nome => nome].
     * O valor é o próprio nome (armazenado na coluna `driver`).
     *
     * @return array<string, string>
     */
    public static function getUsersList(): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $out = [];
        $iterator = $DB->request([
            'SELECT' => ['id', 'name', 'realname', 'firstname'],
            'FROM'   => 'glpi_users',
            'WHERE'  => ['is_active' => 1, 'is_deleted' => 0],
            'ORDER'  => ['realname ASC', 'firstname ASC', 'name ASC'],
        ]);
        foreach ($iterator as $u) {
            $label = trim(($u['firstname'] ?? '') . ' ' . ($u['realname'] ?? ''));
            if ($label === '') {
                $label = $u['name'] ?? '';
            }
            if ($label !== '') {
                $out[$label] = $label;
            }
        }
        return $out;
    }

    /**
     * Nome do usuário logado, no mesmo formato usado em getUsersList().
     * Usado para pré-selecionar o campo Motorista num novo agendamento.
     */
    public static function getCurrentUserLabel(): string
    {
        $uid = (int) Session::getLoginUserID();
        if ($uid <= 0) {
            return '';
        }

        /** @var \DBmysql $DB */
        global $DB;

        $u = $DB->request([
            'SELECT' => ['name', 'realname', 'firstname'],
            'FROM'   => 'glpi_users',
            'WHERE'  => ['id' => $uid],
        ])->current();

        if (!$u) {
            return '';
        }

        $label = trim(($u['firstname'] ?? '') . ' ' . ($u['realname'] ?? ''));
        return $label !== '' ? $label : ($u['name'] ?? '');
    }

    /**
     * Lista de setores (grupos do GLPI) para os selects: [id => nome].
     *
     * @return array<int, string>
     */
    public static function getGroupsList(): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $out = [];
        $iterator = $DB->request([
            'FROM'  => 'glpi_groups',
            'ORDER' => 'name ASC',
        ]);
        foreach ($iterator as $g) {
            $out[(int) $g['id']] = $g['completename'] ?: $g['name'];
        }
        return $out;
    }

    public function rawSearchOptions()
    {
        $options = [];

        $options[] = ['id' => 'common', 'name' => self::getTypeName(2)];

        $options[] = [
            'id'            => 1,
            'table'         => self::getTable(),
            'field'         => 'name',
            'name'          => __('Título', 'reservafrota'),
            'datatype'      => 'itemlink',
            'massiveaction' => false,
        ];
        $options[] = [
            'id'       => 2,
            'table'    => Car::getTable(),
            'field'    => 'name',
            'name'     => Car::getTypeName(1),
            'datatype' => 'dropdown',
        ];
        $options[] = [
            'id'       => 3,
            'table'    => 'glpi_users',
            'field'    => 'name',
            'name'     => __('Solicitante', 'reservafrota'),
            'datatype' => 'dropdown',
        ];
        $options[] = [
            'id'        => 4,
            'table'     => 'glpi_groups',
            'field'     => 'completename',
            'linkfield' => 'groups_id',
            'name'      => __('Setor', 'reservafrota'),
            'datatype'  => 'dropdown',
        ];
        $options[] = [
            'id'       => 5,
            'table'    => self::getTable(),
            'field'    => 'date_departure',
            'name'     => __('Saída', 'reservafrota'),
            'datatype' => 'datetime',
        ];
        $options[] = [
            'id'       => 6,
            'table'    => self::getTable(),
            'field'    => 'date_arrival',
            'name'     => __('Chegada', 'reservafrota'),
            'datatype' => 'datetime',
        ];
        $options[] = [
            'id'       => 7,
            'table'    => self::getTable(),
            'field'    => 'destination',
            'name'     => __('Destino', 'reservafrota'),
            'datatype' => 'string',
        ];
        $options[] = [
            'id'             => 8,
            'table'          => self::getTable(),
            'field'          => 'status',
            'name'           => __('Status', 'reservafrota'),
            'datatype'       => 'specific',
            'searchtype'     => ['equals', 'notequals'],
            'additionalfields' => ['status'],
        ];
        $options[] = [
            'id'       => 9,
            'table'    => 'glpi_users',
            'field'    => 'name',
            'linkfield'=> 'users_id_approver',
            'name'     => __('Aprovado por', 'reservafrota'),
            'datatype' => 'dropdown',
        ];
        $options[] = [
            'id'       => 10,
            'table'    => self::getTable(),
            'field'    => 'reason',
            'name'     => __('Motivo', 'reservafrota'),
            'datatype' => 'text',
        ];

        return $options;
    }

    /**
     * Renderização específica da coluna de status (chip colorido).
     */
    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }
        if ($field === 'status') {
            $status = (int) $values['status'];
            $map = [
                self::STATUS_PENDING  => 'status-pending',
                self::STATUS_APPROVED => 'status-approved',
                self::STATUS_REJECTED => 'status-rejected',
            ];
            $class = $map[$status] ?? '';
            return '<span class="reservafrota-chip ' . $class . '">'
                . self::getStatusName($status) . '</span>';
        }
        return parent::getSpecificValueToDisplay($field, $values, $options);
    }

    /**
     * Dropdown de status para os filtros do motor de busca.
     */
    public static function getSpecificValueToSelect($field, $name = '', $values = '', array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }
        if ($field === 'status') {
            $options['value']   = $values[$field] ?? '';
            $options['display'] = false;
            return \Dropdown::showFromArray($name, self::getStatuses(), $options);
        }
        return parent::getSpecificValueToSelect($field, $name, $values, $options);
    }

    public function defineTabs($options = [])
    {
        $tabs = [];
        $this->addDefaultFormTab($tabs)
             ->addStandardTab(\Log::class, $tabs, $options);
        return $tabs;
    }

    /**
     * Menu do plugin (Ferramentas > Agendamento de Carros).
     * Registrado nesta classe para que o usuário comum (com direito de
     * leitura em agendamentos, mas sem acesso à frota) veja a entrada.
     */
    public static function getMenuName($nb = 0)
    {
        return __('Reserva de Frota', 'reservafrota');
    }

    public static function getMenuContent()
    {
        // Caminho RELATIVO à raiz do GLPI (sem root_doc): o GLPI já prepõe o
        // root_doc nos links do menu. Usar o caminho completo aqui faz o prefixo
        // duplicar (ex.: /glpi/glpi/...) quando o GLPI roda num subdiretório.
        $web = Plugin::getWebDir('reservafrota', true, false);

        $menu = [
            'title'   => __('Agendamento de Carros', 'reservafrota'),
            'icon'    => 'ti ti-car',
            'page'    => $web . '/front/calendar.php',
            'options' => [],
        ];

        // Calendário — tela principal: agora também lista os agendamentos abaixo.
        $menu['options']['calendar'] = [
            'title' => __('Calendário', 'reservafrota'),
            'icon'  => 'ti ti-calendar-month',
            'page'  => $web . '/front/calendar.php',
        ];

        // Frota — somente administração (quem pode ver carros).
        if (Car::canView()) {
            $menu['options']['car'] = [
                'title' => __('Frota', 'reservafrota'),
                'icon'  => Car::getIcon(),
                'page'  => Car::getSearchURL(false),
                'links' => [
                    'search' => Car::getSearchURL(false),
                    'add'    => Car::getFormURL(false),
                ],
            ];
        }

        // Análise — visão gerencial (quem pode aprovar).
        if (self::canApprove()) {
            $menu['options']['analytics'] = [
                'title' => __('Análise', 'reservafrota'),
                'icon'  => 'ti ti-chart-pie',
                'page'  => $web . '/front/analytics.php',
            ];
        }

        // Histórico — visível para todos (usuário vê os próprios; gestor vê tudo).
        $menu['options']['history'] = [
            'title' => __('Histórico', 'reservafrota'),
            'icon'  => 'ti ti-history',
            'page'  => $web . '/front/history.php',
        ];

        return $menu;
    }

    /**
     * Formulário do agendamento (criação/edição + painel de aprovação).
     */
    public function showForm($ID, array $options = [])
    {
        $this->initForm($ID, $options);

        $requester = '';
        if ($uid = (int) $this->fields['users_id']) {
            $requester = \getUserName($uid);
        }

        TemplateRenderer::getInstance()->display('@reservafrota/booking.form.html.twig', [
            'item'           => $this,
            'params'         => $options,
            'statuses'       => self::getStatuses(),
            'can_approve'    => self::canApprove(),
            'cars'           => Car::getActiveCars(),
            'groups'         => self::getGroupsList(),
            'users'          => self::getUsersList(),
            'requester_name' => $requester,
            'web_dir'        => Plugin::getWebDir('reservafrota'),
        ]);

        return true;
    }
}
