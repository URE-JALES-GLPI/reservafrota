<?php

namespace GlpiPlugin\Reservafrota;

use CommonDBTM;
use Session;

/**
 * Manutenção programada de um carro (por KM).
 *
 * Ex.: "Troca de óleo" prevista para 50.000 km, com aviso prévio de 1.000 km.
 * Cada plano pertence a UM carro (decisão do gestor).
 *
 * Usa o direito de Carros (reservafrota::car) para não exigir novo perfil:
 * - READ  = visualizar manutenções
 * - UPDATE = cadastrar/editar/concluir manutenções
 */
class MaintenancePlan extends CommonDBTM
{
    public static $rightname = 'reservafrota::car';

    public $dohistory = true;

    public static function getTypeName($nb = 0)
    {
        return _n('Manutenção programada', 'Manutenções programadas', $nb, 'reservafrota');
    }

    public static function getIcon()
    {
        return 'ti ti-tool';
    }

    /**
     * Estado do plano em relação ao KM atual.
     * @return 'done'|'overdue'|'warning'|'ok'
     */
    public static function calcStatus(int $currentKm, int $dueKm, int $warnKm, int $isDone): string
    {
        if ($isDone) {
            return 'done';
        }
        if ($dueKm <= 0) {
            return 'ok';
        }
        if ($currentKm >= $dueKm) {
            return 'overdue';
        }
        $warnKm = max(0, $warnKm);
        if ($warnKm > 0 && $currentKm >= ($dueKm - $warnKm)) {
            return 'warning';
        }
        return 'ok';
    }

    public static function statusLabel(string $status): string
    {
        switch ($status) {
            case 'overdue':
                return __('Atrasada', 'reservafrota');
            case 'warning':
                return __('Próxima', 'reservafrota');
            case 'done':
                return __('Concluída', 'reservafrota');
            default:
                return __('Em dia', 'reservafrota');
        }
    }

    public function prepareInputForAdd($input)
    {
        $input['plugin_reservafrota_cars_id'] = (int) ($input['plugin_reservafrota_cars_id'] ?? 0);
        if ($input['plugin_reservafrota_cars_id'] <= 0) {
            Session::addMessageAfterRedirect(__('Escolha o carro da manutenção.', 'reservafrota'), false, ERROR);
            return false;
        }
        if (empty(trim((string) ($input['name'] ?? '')))) {
            Session::addMessageAfterRedirect(__('Informe o nome da manutenção (ex.: Troca de óleo).', 'reservafrota'), false, ERROR);
            return false;
        }
        $input['name'] = trim((string) $input['name']);
        $input['due_km'] = max(0, (int) ($input['due_km'] ?? 0));
        if ($input['due_km'] <= 0) {
            Session::addMessageAfterRedirect(__('Informe a quilometragem prevista (KM).', 'reservafrota'), false, ERROR);
            return false;
        }
        $input['warn_km'] = max(0, (int) ($input['warn_km'] ?? 1000));
        $input['is_done'] = 0;
        $input['is_deleted'] = 0;
        return $input;
    }

    public function prepareInputForUpdate($input)
    {
        if (isset($input['name'])) {
            $input['name'] = trim((string) $input['name']);
            if ($input['name'] === '') {
                Session::addMessageAfterRedirect(__('Informe o nome da manutenção.', 'reservafrota'), false, ERROR);
                return false;
            }
        }
        if (isset($input['due_km'])) {
            $input['due_km'] = max(0, (int) $input['due_km']);
        }
        if (isset($input['warn_km'])) {
            $input['warn_km'] = max(0, (int) $input['warn_km']);
        }
        return $input;
    }

    /**
     * Planos de um carro com status calculado.
     * @return list<array<string,mixed>>
     */
    public static function getPlansForCar(int $cars_id, bool $includeDone = true): array
    {
        /** @var \DBmysql $DB */
        global $DB;
        if (!$DB->tableExists(self::getTable())) {
            return [];
        }
        $carKm = Car::getCurrentKm($cars_id);
        $where = [
            'plugin_reservafrota_cars_id' => $cars_id,
            'is_deleted' => 0,
        ];
        if (!$includeDone) {
            $where['is_done'] = 0;
        }
        $out = [];
        try {
            $it = $DB->request([
                'FROM'  => self::getTable(),
                'WHERE' => $where,
                'ORDER' => ['is_done ASC', 'due_km ASC'],
            ]);
        } catch (\Throwable $e) {
            return [];
        }
        foreach ($it as $row) {
            $status = self::calcStatus($carKm, (int) $row['due_km'], (int) ($row['warn_km'] ?? 1000), (int) ($row['is_done'] ?? 0));
            $row['current_km'] = $carKm;
            $row['remaining'] = (int) $row['due_km'] - $carKm;
            $row['status_key'] = $status;
            $row['status_label'] = self::statusLabel($status);
            $out[] = $row;
        }
        return $out;
    }

    /**
     * Todos os planos pendentes com dados do carro + status.
     * @return list<array<string,mixed>>
     */
    public static function getAllPending(): array
    {
        /** @var \DBmysql $DB */
        global $DB;
        if (!$DB->tableExists(self::getTable())) {
            return [];
        }
        try {
            $it = $DB->request([
                'SELECT' => ['p.*', 'c.name AS car_name', 'c.plate AS car_plate', 'c.km_current AS car_km'],
                'FROM' => self::getTable() . ' AS p',
                'LEFT JOIN' => [
                    Car::getTable() . ' AS c' => ['ON' => ['p' => 'plugin_reservafrota_cars_id', 'c' => 'id']],
                ],
                'WHERE' => ['p.is_deleted' => 0, 'p.is_done' => 0],
                'ORDER' => 'p.due_km ASC',
            ]);
        } catch (\Throwable $e) {
            return [];
        }
        $out = [];
        foreach ($it as $row) {
            $current = (int) ($row['car_km'] ?? 0);
            // Se o carro foi excluído, ignora
            if (empty($row['car_name']) && (int) ($row['plugin_reservafrota_cars_id'] ?? 0) > 0) {
                // mantém mesmo assim, mas marca sem carro
            }
            $status = self::calcStatus($current, (int) $row['due_km'], (int) ($row['warn_km'] ?? 1000), 0);
            $row['current_km'] = $current;
            $row['remaining'] = (int) $row['due_km'] - $current;
            $row['status_key'] = $status;
            $row['status_label'] = self::statusLabel($status);
            $out[] = $row;
        }
        // Atrasadas primeiro, depois próximas, depois em dia
        usort($out, function ($a, $b) {
            $order = ['overdue' => 0, 'warning' => 1, 'ok' => 2];
            $oa = $order[$a['status_key']] ?? 3;
            $ob = $order[$b['status_key']] ?? 3;
            if ($oa !== $ob) {
                return $oa <=> $ob;
            }
            return ($a['remaining'] ?? 0) <=> ($b['remaining'] ?? 0);
        });
        return $out;
    }

    /**
     * Alertas para o gestor: planos atrasados ou próximos do vencimento.
     * @return list<array<string,mixed>>
     */
    public static function getAlerts(): array
    {
        $all = self::getAllPending();
        return array_values(array_filter($all, function ($p) {
            return in_array($p['status_key'], ['overdue', 'warning'], true);
        }));
    }

    public static function countAlerts(): int
    {
        return count(self::getAlerts());
    }

    /**
     * Mapa carro_id => pior status (para badges na Frota).
     * @return array<int,string>
     */
    public static function getStatusMap(): array
    {
        $map = [];
        foreach (self::getAllPending() as $p) {
            $cid = (int) ($p['plugin_reservafrota_cars_id'] ?? 0);
            if ($cid <= 0) {
                continue;
            }
            $rank = ['ok' => 0, 'warning' => 1, 'overdue' => 2];
            $cur = $map[$cid] ?? 'ok';
            if (($rank[$p['status_key']] ?? 0) > ($rank[$cur] ?? 0)) {
                $map[$cid] = $p['status_key'];
            }
        }
        return $map;
    }

    /**
     * Marca plano como concluído (gera registro no histórico se km informado).
     */
    public function markDone(int $km = 0, string $description = ''): bool
    {
        $update = [
            'id' => $this->fields['id'],
            'is_done' => 1,
            'date_done' => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
        ];
        $ok = $this->update($update);
        if ($ok && $km > 0) {
            $m = new Maintenance();
            $m->add([
                'plugin_reservafrota_cars_id' => (int) ($this->fields['plugin_reservafrota_cars_id'] ?? 0),
                'plugin_reservafrota_maintenanceplans_id' => (int) $this->fields['id'],
                'maintenance_date' => date('Y-m-d'),
                'km' => $km,
                'description' => $description !== '' ? $description : ($this->fields['name'] ?? ''),
            ]);
        }
        return $ok;
    }

    // ================= E-MAIL AO GESTOR =================

    /**
     * E-mails dos gestores (quem tem o direito de aprovar reservas).
     * @return list<string>
     */
    public static function getManagerEmails(): array
    {
        /** @var \DBmysql $DB */
        global $DB;
        $emails = [];
        try {
            $it = $DB->request([
                'SELECT' => ['u.email', 'pr.rights'],
                'FROM' => 'glpi_profilerights AS pr',
                'INNER JOIN' => [
                    'glpi_profiles_users AS pu' => ['ON' => ['pu' => 'profiles_id', 'pr' => 'profiles_id']],
                    'glpi_users AS u' => ['ON' => ['u' => 'id', 'pu' => 'users_id']],
                ],
                'WHERE' => [
                    'pr.name' => 'reservafrota::booking',
                    'u.is_active' => 1,
                    'u.is_deleted' => 0,
                ],
            ]);
            foreach ($it as $row) {
                if (!((int) ($row['rights'] ?? 0) & Booking::APPROVE)) {
                    continue;
                }
                $email = trim((string) ($row['email'] ?? ''));
                if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $emails[$email] = $email;
                }
            }
        } catch (\Throwable $e) {
            return [];
        }
        return array_values($emails);
    }

    /**
     * Envia e-mail via GLPIMailer (ou mail() como fallback).
     */
    public static function sendManagerMail(string $subject, string $htmlBody, string $textBody = ''): int
    {
        $emails = self::getManagerEmails();
        if (empty($emails)) {
            return 0;
        }
        if ($textBody === '') {
            $textBody = strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</li>'], "\n", $htmlBody));
        }
        $sent = 0;
        global $CFG_GLPI;
        $from = $CFG_GLPI['admin_email'] ?? null;
        foreach ($emails as $email) {
            try {
                if (class_exists('GLPIMailer')) {
                    $mailer = new \GLPIMailer();
                    if (!empty($from)) {
                        $mailer->SetFrom((string) $from, $CFG_GLPI['admin_email_name'] ?? 'GLPI');
                    }
                    $mailer->AddAddress($email);
                    $mailer->Subject = $subject;
                    $mailer->Body = $htmlBody;
                    $mailer->AltBody = $textBody;
                    $mailer->isHTML(true);
                    if ($mailer->Send()) {
                        $sent++;
                    }
                } elseif (class_exists('Glpi\Mail\Mailer')) {
                    $mailer = new \Glpi\Mail\Mailer();
                    if (!empty($from) && method_exists($mailer, 'setFrom')) {
                        $mailer->setFrom((string) $from);
                    }
                    if (method_exists($mailer, 'addAddress')) {
                        $mailer->addAddress($email);
                    } elseif (method_exists($mailer, 'AddAddress')) {
                        $mailer->AddAddress($email);
                    }
                    if (method_exists($mailer, 'send')) {
                        // APIs variam entre versões; tenta o envio simples
                        $mailer->Subject = $subject;
                        $mailer->Body = $htmlBody;
                        if ($mailer->send()) {
                            $sent++;
                        }
                    }
                } else {
                    $headers = "MIME-Version: 1.0\r\nContent-type: text/plain; charset=UTF-8\r\n";
                    if (!empty($from)) {
                        $headers .= "From: " . (string) $from . "\r\n";
                    }
                    if (@mail($email, $subject, $textBody, $headers)) {
                        $sent++;
                    }
                }
            } catch (\Throwable $e) {
                // ignora falha individual e tenta o próximo
                continue;
            }
        }
        return $sent;
    }

    /**
     * Verifica os planos do carro após atualização de KM e avisa o gestor
     * (visual já é automático; aqui vai o e-mail, com throttle de 24h por plano).
     * @return int nº de e-mails enviados
     */
    public static function checkAndNotify(int $cars_id): int
    {
        /** @var \DBmysql $DB */
        global $DB;
        if ($cars_id <= 0 || !$DB->tableExists(self::getTable())) {
            return 0;
        }
        $car = new Car();
        if (!$car->getFromDB($cars_id)) {
            return 0;
        }
        $currentKm = (int) ($car->fields['km_current'] ?? 0);
        $plans = self::getPlansForCar($cars_id, false);
        $toNotify = [];
        foreach ($plans as $p) {
            if (!in_array($p['status_key'], ['overdue', 'warning'], true)) {
                continue;
            }
            $last = $p['last_notify'] ?? null;
            if (!empty($last) && (time() - strtotime((string) $last)) < 86400) {
                continue; // já avisado nas últimas 24h
            }
            $toNotify[] = $p;
        }
        if (empty($toNotify)) {
            return 0;
        }
        $carLabel = ($car->fields['name'] ?? __('Carro', 'reservafrota'))
            . (!empty($car->fields['plate']) ? ' (' . $car->fields['plate'] . ')' : '');
        $linesHtml = '';
        $linesText = '';
        foreach ($toNotify as $p) {
            $faltam = (int) $p['due_km'] - $currentKm;
            $estado = $p['status_key'] === 'overdue'
                ? sprintf(__('ATRASADA há %s km', 'reservafrota'), number_format(abs($faltam), 0, ',', '.'))
                : sprintf(__('faltam %s km', 'reservafrota'), number_format($faltam, 0, ',', '.'));
            $linesHtml .= '<li><b>' . htmlspecialchars((string) $p['name']) . '</b> — '
                . sprintf(__('prevista aos %s km', 'reservafrota'), number_format((int) $p['due_km'], 0, ',', '.'))
                . ' (' . $estado . ')</li>';
            $linesText .= '- ' . $p['name'] . ' — prevista aos ' . $p['due_km'] . ' km (' . $estado . ")\n";
        }
        $subject = sprintf(
            __('[Frota] Manutenção próxima/atrasada — %s (KM atual %s)', 'reservafrota'),
            $carLabel,
            number_format($currentKm, 0, ',', '.')
        );
        $html = '<p>' . sprintf(
            __('O veículo <b>%s</b> está com KM atual <b>%s</b> e possui manutenções que exigem atenção:', 'reservafrota'),
            htmlspecialchars($carLabel),
            number_format($currentKm, 0, ',', '.')
        ) . '</p><ul>' . $linesHtml . '</ul>'
            . '<p>' . __('Acesse Ferramentas > Reserva de Frota > Manutenções para concluir ou reprogramar.', 'reservafrota') . '</p>';
        $text = sprintf(
            "Veículo %s (KM atual %s) com manutenções que exigem atenção:\n%s\nAcesse Ferramentas > Reserva de Frota > Manutenções.",
            $carLabel,
            $currentKm,
            $linesText
        );
        $sent = self::sendManagerMail($subject, $html, $text);
        if ($sent > 0) {
            $now = date('Y-m-d H:i:s');
            foreach ($toNotify as $p) {
                try {
                    $DB->update(self::getTable(), ['last_notify' => $now], ['id' => (int) $p['id']]);
                } catch (\Throwable $e) {}
            }
            \Log::history(
                $cars_id,
                Car::class,
                [0, '', sprintf(__('Alerta de manutenção enviado a %d gestor(es) (KM %s).', 'reservafrota'), $sent, $currentKm)],
                '',
                \Log::HISTORY_LOG_SIMPLE_MESSAGE
            );
        }
        return $sent;
    }

    public function rawSearchOptions()
    {
        $options = [];
        $options[] = ['id' => 'common', 'name' => self::getTypeName(2)];
        $options[] = [
            'id' => 1, 'table' => self::getTable(), 'field' => 'name',
            'name' => __('Manutenção', 'reservafrota'), 'datatype' => 'itemlink',
        ];
        $options[] = [
            'id' => 2, 'table' => Car::getTable(), 'field' => 'name',
            'name' => Car::getTypeName(1), 'datatype' => 'dropdown',
        ];
        $options[] = [
            'id' => 3, 'table' => self::getTable(), 'field' => 'due_km',
            'name' => __('KM prevista', 'reservafrota'), 'datatype' => 'number',
        ];
        $options[] = [
            'id' => 4, 'table' => self::getTable(), 'field' => 'is_done',
            'name' => __('Concluída', 'reservafrota'), 'datatype' => 'bool',
        ];
        return $options;
    }
}
