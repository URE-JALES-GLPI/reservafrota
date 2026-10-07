<?php

namespace GlpiPlugin\Reservafrota;

use CommonDBTM;
use CommonGLPI;
use Glpi\Application\View\TemplateRenderer;
use Plugin;
use Session;

/**
 * Carro da frota da SEDUC.
 * Cadastrado pelo administrador com modelo (name), placa, ano e foto.
 */
class Car extends CommonDBTM
{
    public static $rightname = 'reservafrota::car';

    // Mantém histórico de alterações na aba "Histórico".
    public $dohistory = true;

    public static function getTypeName($nb = 0)
    {
        return _n('Carro', 'Carros', $nb, 'reservafrota');
    }

    public static function getIcon()
    {
        return 'ti ti-car';
    }

    /**
     * Diretório onde as fotos dos carros são armazenadas (fora da raiz web).
     */
    public static function getPictureDir()
    {
        $base = defined('GLPI_PLUGIN_DOC_DIR')
            ? GLPI_PLUGIN_DOC_DIR
            : (GLPI_DOC_DIR . '/_plugins');
        return $base . '/reservafrota';
    }

    /**
     * URL para servir a foto do carro (passa por checagem de permissão).
     */
    public function getPictureUrl(): ?string
    {
        if (empty($this->fields['picture'])) {
            return null;
        }
        return Plugin::getWebDir('reservafrota')
            . '/front/car.picture.php?id=' . (int) $this->fields['id'];
    }

    /**
     * Valida e armazena uma foto enviada. Retorna o nome do arquivo salvo
     * ou null se o upload não for uma imagem válida.
     */
    public static function storePicture(array $file): ?string
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return null;
        }

        // Confere o tipo real do arquivo (não confia na extensão enviada).
        $allowed = [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
            'image/gif'  => 'gif',
        ];
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($file['tmp_name']);
        if (!isset($allowed[$mime])) {
            Session::addMessageAfterRedirect(
                __('Arquivo de foto inválido (use JPG, PNG, WEBP ou GIF).', 'reservafrota'),
                false,
                ERROR
            );
            return null;
        }

        $dir = self::getPictureDir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $filename = uniqid('car_', true) . '.' . $allowed[$mime];
        $target   = $dir . '/' . $filename;

        if (!move_uploaded_file($file['tmp_name'], $target)) {
            return null;
        }

        return $filename;
    }

    /**
     * Remove o arquivo de foto do disco (chamado ao excluir definitivamente).
     */
    public function deletePictureFile(): void
    {
        if (empty($this->fields['picture'])) {
            return;
        }
        $path = self::getPictureDir() . '/' . basename($this->fields['picture']);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    public function post_purgeItem()
    {
        $this->deletePictureFile();
        parent::post_purgeItem();
    }

    /**
     * Lista os carros ativos (não excluídos), ordenados por nome.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function getActiveCars(): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $cars = [];
        $iterator = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => [
                'is_active'  => 1,
                'is_deleted' => 0,
            ],
            'ORDER' => 'name ASC',
        ]);
        foreach ($iterator as $row) {
            $row['picture_url'] = !empty($row['picture'])
                ? (Plugin::getWebDir('reservafrota') . '/front/car.picture.php?id=' . (int) $row['id'])
                : null;
            $cars[(int) $row['id']] = $row;
        }
        return $cars;
    }

    /**
     * Todos os carros da frota (ativos e inativos) para a tela de gestão.
     *
     * @return list<array<string,mixed>>
     */
    public static function getAllForFleet(): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $cars = [];
        $iterator = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['is_deleted' => 0],
            'ORDER' => ['is_active DESC', 'name ASC'],
        ]);
        foreach ($iterator as $row) {
            $row['picture_url'] = !empty($row['picture'])
                ? (Plugin::getWebDir('reservafrota') . '/front/car.picture.php?id=' . (int) $row['id'])
                : null;
            $cars[] = $row;
        }
        return $cars;
    }

    /**
     * Sanitiza o KM atual (nunca negativo).
     */
    public function prepareInputForAdd($input)
    {
        if (isset($input['km_current'])) {
            $input['km_current'] = max(0, (int) $input['km_current']);
        }
        return $input;
    }

    public function prepareInputForUpdate($input)
    {
        if (isset($input['km_current'])) {
            $input['km_current'] = max(0, (int) $input['km_current']);
            $old = (int) ($this->fields['km_current'] ?? 0);
            if ($input['km_current'] !== $old) {
                $input['km_updated'] = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
            }
        }
        return $input;
    }

    public function post_updateItem($history = 1)
    {
        parent::post_updateItem($history);
        // Se o KM foi alterado manualmente, verifica alertas de manutenção.
        try {
            if (isset($this->input['km_current']) && class_exists(MaintenancePlan::class)) {
                MaintenancePlan::checkAndNotify((int) ($this->fields['id'] ?? 0));
            }
        } catch (\Throwable $e) {}
    }

    /**
     * KM atual do carro (campo próprio; 0 se nunca informado).
     */
    public static function getCurrentKm(int $cars_id): int
    {
        /** @var \DBmysql $DB */
        global $DB;
        if ($cars_id <= 0) {
            return 0;
        }
        try {
            if (!$DB->tableExists(self::getTable()) || !$DB->fieldExists(self::getTable(), 'km_current')) {
                return 0;
            }
            $row = $DB->request([
                'SELECT' => ['km_current'],
                'FROM'   => self::getTable(),
                'WHERE'  => ['id' => $cars_id],
                'LIMIT'  => 1,
            ])->current();
        } catch (\Throwable $e) {
            return 0;
        }
        return (int) ($row['km_current'] ?? 0);
    }

    /**
     * Maior KM já registrado nas chegadas (reservas concluídas) do carro.
     */
    public static function getLastBookingKm(int $cars_id): ?int
    {
        /** @var \DBmysql $DB */
        global $DB;
        if ($cars_id <= 0) {
            return null;
        }
        try {
            if (!$DB->tableExists(Booking::getTable()) || !$DB->fieldExists(Booking::getTable(), 'km_final')) {
                return null;
            }
            $row = $DB->request([
                'SELECT' => ['MAX' => 'km_final AS max_km'],
                'FROM'   => Booking::getTable(),
                'WHERE'  => [
                    'plugin_reservafrota_cars_id' => $cars_id,
                    'is_deleted' => 0,
                ],
            ])->current();
        } catch (\Throwable $e) {
            return null;
        }
        $v = $row['max_km'] ?? null;
        return $v === null ? null : (int) $v;
    }

    /**
     * Atualiza o KM atual se o novo valor for maior. Retorna true se atualizou.
     * Dispara o alerta de manutenção (visual + e-mail) quando houver.
     */
    public static function updateKm(int $cars_id, int $newKm, string $source = ''): bool
    {
        /** @var \DBmysql $DB */
        global $DB;
        if ($cars_id <= 0 || $newKm <= 0) {
            return false;
        }
        try {
            if (!$DB->tableExists(self::getTable()) || !$DB->fieldExists(self::getTable(), 'km_current')) {
                return false;
            }
            $car = new self();
            if (!$car->getFromDB($cars_id)) {
                return false;
            }
            $current = (int) ($car->fields['km_current'] ?? 0);
            if ($newKm <= $current) {
                return false;
            }
            $ok = $car->update([
                'id'         => $cars_id,
                'km_current' => $newKm,
                'km_updated' => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
            ]);
            if ($ok) {
                $msg = $source === 'maintenance'
                    ? sprintf(__('KM atualizado para %s (manutenção registrada).', 'reservafrota'), number_format($newKm, 0, ',', '.'))
                    : sprintf(__('KM atualizado para %s (chegada registrada).', 'reservafrota'), number_format($newKm, 0, ',', '.'));
                \Log::history($cars_id, self::class, [0, '', $msg], '', \Log::HISTORY_LOG_SIMPLE_MESSAGE);
                if (class_exists(MaintenancePlan::class)) {
                    MaintenancePlan::checkAndNotify($cars_id);
                }
            }
            return (bool) $ok;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function rawSearchOptions()
    {
        $options = [];

        $options[] = ['id' => 'common', 'name' => __('Características')];

        $options[] = [
            'id'       => 1,
            'table'    => self::getTable(),
            'field'    => 'name',
            'name'     => __('Modelo', 'reservafrota'),
            'datatype' => 'itemlink',
            'massiveaction' => false,
        ];
        $options[] = [
            'id'       => 2,
            'table'    => self::getTable(),
            'field'    => 'plate',
            'name'     => __('Placa', 'reservafrota'),
            'datatype' => 'string',
        ];
        $options[] = [
            'id'       => 3,
            'table'    => self::getTable(),
            'field'    => 'model_year',
            'name'     => __('Ano', 'reservafrota'),
            'datatype' => 'number',
        ];
        $options[] = [
            'id'       => 4,
            'table'    => self::getTable(),
            'field'    => 'is_active',
            'name'     => __('Ativo', 'reservafrota'),
            'datatype' => 'bool',
        ];
        $options[] = [
            'id'       => 5,
            'table'    => self::getTable(),
            'field'    => 'comment',
            'name'     => __('Observações', 'reservafrota'),
            'datatype' => 'text',
        ];
        $options[] = [
            'id'       => 7,
            'table'    => self::getTable(),
            'field'    => 'km_current',
            'name'     => __('KM atual', 'reservafrota'),
            'datatype' => 'number',
        ];
        $options[] = [
            'id'       => 6,
            'table'    => self::getTable(),
            'field'    => 'id',
            'name'     => __('ID'),
            'datatype' => 'number',
            'massiveaction' => false,
        ];

        return $options;
    }

    public function defineTabs($options = [])
    {
        $tabs = [];
        $this->addDefaultFormTab($tabs)
             ->addStandardTab(\Log::class, $tabs, $options);
        return $tabs;
    }

    /**
     * Formulário de cadastro/edição usando template Twig próprio.
     */
    public function showForm($ID, array $options = [])
    {
        $this->initForm($ID, $options);

        // Item novo: basta CREATE para cadastrar; existente: exige UPDATE para editar.
        $is_new = $this->isNewItem();
        $can_edit = $is_new
            ? Session::haveRight(self::$rightname, CREATE)
            : Session::haveRight(self::$rightname, UPDATE);

        $history = [];
        if (!$this->isNewItem()) {
            $raw = \Log::getHistoryData($this, 0, 50);
            foreach ($raw as $h) {
                $history[] = [
                    'id'     => $h['id'] ?? '',
                    'date'   => $h['date_mod'] ?? '',
                    'user'   => $h['user_name'] ?? '',
                    'field'  => $h['field'] ?? '',
                    'change' => $h['change'] ?? '',
                ];
            }
        }

        $maintPlans = [];
        $maintHistory = [];
        $lastBookingKm = null;
        if (!$this->isNewItem() && class_exists(MaintenancePlan::class)) {
            try {
                $cid = (int) $this->fields['id'];
                $maintPlans = MaintenancePlan::getPlansForCar($cid, true);
                $maintHistory = Maintenance::getRecentForCar($cid, 20);
                $lastBookingKm = self::getLastBookingKm($cid);
            } catch (\Throwable $e) {}
        }

        TemplateRenderer::getInstance()->display('@reservafrota/car.form.html.twig', [
            'item'            => $this,
            'params'          => $options,
            'can_edit'        => $can_edit,
            'picture_url'     => $this->getPictureUrl(),
            'web_dir'         => Plugin::getWebDir('reservafrota'),
            'history'         => $history,
            'maint_plans'     => $maintPlans,
            'maint_history'   => $maintHistory,
            'last_booking_km' => $lastBookingKm,
        ]);

        return true;
    }
}
