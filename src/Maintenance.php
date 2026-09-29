<?php

namespace GlpiPlugin\Reservafrota;

use CommonDBTM;
use Session;

/**
 * Histórico de manutenções realizadas (o que foi feito, quando e em qual KM).
 *
 * Cada registro pertence a UM carro e pode (opcionalmente) estar vinculado
 * a um plano programado (MaintenancePlan). Ao salvar, o KM atual do carro
 * é atualizado automaticamente se o KM informado for maior.
 */
class Maintenance extends CommonDBTM
{
    public static $rightname = 'reservafrota::car';

    public $dohistory = true;

    public static function getTypeName($nb = 0)
    {
        return _n('Manutenção realizada', 'Manutenções realizadas', $nb, 'reservafrota');
    }

    public static function getIcon()
    {
        return 'ti ti-history';
    }

    public function prepareInputForAdd($input)
    {
        $input['plugin_reservafrota_cars_id'] = (int) ($input['plugin_reservafrota_cars_id'] ?? 0);
        if ($input['plugin_reservafrota_cars_id'] <= 0) {
            Session::addMessageAfterRedirect(__('Escolha o carro da manutenção.', 'reservafrota'), false, ERROR);
            return false;
        }
        $input['km'] = max(0, (int) ($input['km'] ?? 0));
        if ($input['km'] <= 0) {
            Session::addMessageAfterRedirect(__('Informe a quilometragem em que a manutenção foi feita.', 'reservafrota'), false, ERROR);
            return false;
        }
        $input['description'] = trim((string) ($input['description'] ?? ''));
        if ($input['description'] === '') {
            Session::addMessageAfterRedirect(__('Descreva o que foi feito (ex.: troca de óleo + filtro).', 'reservafrota'), false, ERROR);
            return false;
        }
        if (empty($input['maintenance_date'])) {
            $input['maintenance_date'] = date('Y-m-d');
        }
        $input['plugin_reservafrota_maintenanceplans_id'] = (int) ($input['plugin_reservafrota_maintenanceplans_id'] ?? 0);
        if (empty($input['users_id'])) {
            $input['users_id'] = (int) Session::getLoginUserID();
        }
        $input['is_deleted'] = 0;
        return $input;
    }

    public function post_addItem()
    {
        parent::post_addItem();
        // Atualiza o KM atual do carro (dispara alerta de planos, se houver)
        $cars_id = (int) ($this->fields['plugin_reservafrota_cars_id'] ?? 0);
        $km = (int) ($this->fields['km'] ?? 0);
        if ($cars_id > 0 && $km > 0) {
            Car::updateKm($cars_id, $km, 'maintenance');
        }
        // Se vinculada a um plano, oferece a conclusão automática do plano
        // quando o KM registrado já atingiu o previsto.
        try {
            $planId = (int) ($this->fields['plugin_reservafrota_maintenanceplans_id'] ?? 0);
            if ($planId > 0) {
                $plan = new MaintenancePlan();
                if ($plan->getFromDB($planId) && !(int) ($plan->fields['is_done'] ?? 0)) {
                    if ($km >= (int) ($plan->fields['due_km'] ?? 0)) {
                        $plan->update([
                            'id' => $planId,
                            'is_done' => 1,
                            'date_done' => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
                        ]);
                    }
                }
            }
        } catch (\Throwable $e) {}
        // Registra no histórico do carro
        try {
            if ($cars_id > 0) {
                \Log::history(
                    $cars_id,
                    Car::class,
                    [0, '', sprintf(
                        __('Manutenção registrada: %s (KM %s, %s).', 'reservafrota'),
                        $this->fields['description'] ?? '',
                        number_format($km, 0, ',', '.'),
                        $this->fields['maintenance_date'] ?? ''
                    )],
                    '',
                    \Log::HISTORY_LOG_SIMPLE_MESSAGE
                );
            }
        } catch (\Throwable $e) {}
    }

    /**
     * Manutenções recentes de um carro (mais recentes primeiro).
     * @return list<array<string,mixed>>
     */
    public static function getRecentForCar(int $cars_id, int $limit = 20): array
    {
        /** @var \DBmysql $DB */
        global $DB;
        if (!$DB->tableExists(self::getTable())) {
            return [];
        }
        $out = [];
        try {
            $it = $DB->request([
                'SELECT' => ['m.*', 'p.name AS plan_name'],
                'FROM' => self::getTable() . ' AS m',
                'LEFT JOIN' => [
                    MaintenancePlan::getTable() . ' AS p' => [
                        'ON' => ['m' => 'plugin_reservafrota_maintenanceplans_id', 'p' => 'id'],
                    ],
                ],
                'WHERE' => ['m.plugin_reservafrota_cars_id' => $cars_id, 'm.is_deleted' => 0],
                'ORDER' => ['m.maintenance_date DESC', 'm.id DESC'],
                'LIMIT' => max(1, $limit),
            ]);
        } catch (\Throwable $e) {
            return [];
        }
        foreach ($it as $row) {
            $out[] = $row;
        }
        return $out;
    }

    /**
     * Manutenções recentes de toda a frota (para a tela de Manutenções).
     * @return list<array<string,mixed>>
     */
    public static function getRecentAll(int $limit = 50): array
    {
        /** @var \DBmysql $DB */
        global $DB;
        if (!$DB->tableExists(self::getTable())) {
            return [];
        }
        $out = [];
        try {
            $it = $DB->request([
                'SELECT' => [
                    'm.*', 'c.name AS car_name', 'c.plate AS car_plate',
                    'p.name AS plan_name',
                    'u.firstname', 'u.realname', 'u.name AS user_login',
                ],
                'FROM' => self::getTable() . ' AS m',
                'LEFT JOIN' => [
                    Car::getTable() . ' AS c' => ['ON' => ['m' => 'plugin_reservafrota_cars_id', 'c' => 'id']],
                    MaintenancePlan::getTable() . ' AS p' => [
                        'ON' => ['m' => 'plugin_reservafrota_maintenanceplans_id', 'p' => 'id'],
                    ],
                    'glpi_users AS u' => ['ON' => ['m' => 'users_id', 'u' => 'id']],
                ],
                'WHERE' => ['m.is_deleted' => 0],
                'ORDER' => ['m.maintenance_date DESC', 'm.id DESC'],
                'LIMIT' => max(1, $limit),
            ]);
        } catch (\Throwable $e) {
            return [];
        }
        foreach ($it as $row) {
            $who = trim(($row['firstname'] ?? '') . ' ' . ($row['realname'] ?? ''));
            if ($who === '') {
                $who = $row['user_login'] ?? '';
            }
            $row['user_display'] = $who;
            $out[] = $row;
        }
        return $out;
    }

    public function rawSearchOptions()
    {
        $options = [];
        $options[] = ['id' => 'common', 'name' => self::getTypeName(2)];
        $options[] = [
            'id' => 1, 'table' => self::getTable(), 'field' => 'description',
            'name' => __('O que foi feito', 'reservafrota'), 'datatype' => 'text',
        ];
        $options[] = [
            'id' => 2, 'table' => Car::getTable(), 'field' => 'name',
            'name' => Car::getTypeName(1), 'datatype' => 'dropdown',
        ];
        $options[] = [
            'id' => 3, 'table' => self::getTable(), 'field' => 'km',
            'name' => __('KM', 'reservafrota'), 'datatype' => 'number',
        ];
        $options[] = [
            'id' => 4, 'table' => self::getTable(), 'field' => 'maintenance_date',
            'name' => __('Data', 'reservafrota'), 'datatype' => 'date',
        ];
        return $options;
    }
}
