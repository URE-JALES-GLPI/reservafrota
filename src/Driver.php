<?php

namespace GlpiPlugin\Reservafrota;

use CommonDBTM;
use CommonGLPI;
use Glpi\Application\View\TemplateRenderer;
use Plugin;
use Session;

/**
 * Motorista da frota — cadastrado pelo gestor com nome, CNH, telefone, foto.
 */
class Driver extends CommonDBTM
{
    public static $rightname = 'reservafrota::driver';

    public $dohistory = true;

    public static function getTypeName($nb = 0)
    {
        return _n('Motorista', 'Motoristas', $nb, 'reservafrota');
    }

    public static function getIcon()
    {
        return 'ti ti-steering-wheel';
    }

    public static function getPictureDir()
    {
        $base = defined('GLPI_PLUGIN_DOC_DIR')
            ? GLPI_PLUGIN_DOC_DIR
            : (GLPI_DOC_DIR . '/_plugins');
        return $base . '/reservafrota';
    }

    public function getPictureUrl(): ?string
    {
        if (empty($this->fields['picture'])) {
            return null;
        }
        return Plugin::getWebDir('reservafrota')
            . '/front/driver.picture.php?id=' . (int) $this->fields['id'];
    }

    public static function storePicture(array $file): ?string
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return null;
        }
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
        $filename = uniqid('driver_', true) . '.' . $allowed[$mime];
        $target   = $dir . '/' . $filename;
        if (!move_uploaded_file($file['tmp_name'], $target)) {
            return null;
        }
        return $filename;
    }

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

    public static function getActiveDrivers(): array
    {
        /** @var \DBmysql $DB */
        global $DB;
        if (!$DB->tableExists(self::getTable())) {
            return [];
        }
        $drivers = [];
        try {
            $iterator = $DB->request([
                'FROM'  => self::getTable(),
                'WHERE' => [
                    'is_active'  => 1,
                    'is_deleted' => 0,
                ],
                'ORDER' => 'name ASC',
            ]);
        } catch (\Throwable $e) {
            return [];
        }
        foreach ($iterator as $row) {
            $row['picture_url'] = !empty($row['picture'])
                ? (Plugin::getWebDir('reservafrota') . '/front/driver.picture.php?id=' . (int) $row['id'])
                : null;
            $drivers[(int) $row['id']] = $row;
        }
        return $drivers;
    }

    public static function getAllForList(): array
    {
        /** @var \DBmysql $DB */
        global $DB;
        if (!$DB->tableExists(self::getTable())) {
            return [];
        }
        $drivers = [];
        try {
            $iterator = $DB->request([
                'FROM'  => self::getTable(),
                'WHERE' => ['is_deleted' => 0],
                'ORDER' => ['is_active DESC', 'name ASC'],
            ]);
        } catch (\Throwable $e) {
            return [];
        }
        foreach ($iterator as $row) {
            $row['picture_url'] = !empty($row['picture'])
                ? (Plugin::getWebDir('reservafrota') . '/front/driver.picture.php?id=' . (int) $row['id'])
                : null;
            $drivers[] = $row;
        }
        return $drivers;
    }

    /**
     * Motorista (ativo) vinculado ao usuário GLPI — usado para pré-selecionar
     * o motorista no momento da reserva. Retorna 0 se não houver vínculo.
     */
    public static function getDriverIdForUser(int $users_id): int
    {
        /** @var \DBmysql $DB */
        global $DB;
        if ($users_id <= 0) {
            return 0;
        }
        try {
            if (!$DB->tableExists(self::getTable())
                || !$DB->fieldExists(self::getTable(), 'users_id')) {
                return 0;
            }
            $row = $DB->request([
                'SELECT' => ['id'],
                'FROM'   => self::getTable(),
                'WHERE'  => [
                    'users_id'   => $users_id,
                    'is_active'  => 1,
                    'is_deleted' => 0,
                ],
                'LIMIT'  => 1,
            ])->current();
        } catch (\Throwable $e) {
            return 0;
        }
        return (int) ($row['id'] ?? 0);
    }

    /**
     * Usuários GLPI ativos para o select de vínculo: [id => "Nome (login)"].
     *
     * @return array<int, string>
     */
    public static function getUsersForSelect(): array
    {
        /** @var \DBmysql $DB */
        global $DB;
        $out = [];
        try {
            $iterator = $DB->request([
                'SELECT' => ['id', 'name', 'realname', 'firstname'],
                'FROM'   => 'glpi_users',
                'WHERE'  => ['is_active' => 1, 'is_deleted' => 0],
                'ORDER'  => ['realname ASC', 'firstname ASC', 'name ASC'],
            ]);
        } catch (\Throwable $e) {
            return [];
        }
        foreach ($iterator as $u) {
            $label = trim(($u['firstname'] ?? '') . ' ' . ($u['realname'] ?? ''));
            if ($label === '') {
                $label = $u['name'] ?? '';
            }
            if ($label !== '') {
                $out[(int) $u['id']] = $label . ' (' . ($u['name'] ?? '') . ')';
            }
        }
        return $out;
    }

    /**
     * Lista para selects: [id => "Nome — CNH — telefone"]
     */
    public static function getDriversForSelect(): array
    {
        $out = [];
        foreach (self::getActiveDrivers() as $id => $d) {
            $label = $d['name'];
            if (!empty($d['phone'])) {
                $label .= ' — ' . $d['phone'];
            }
            // CNH não precisa aparecer no label curto, só tooltip
            $out[(int) $id] = $label;
        }
        return $out;
    }

    public function rawSearchOptions()
    {
        $options = [];
        $options[] = ['id' => 'common', 'name' => __('Características')];
        $options[] = [
            'id'       => 1,
            'table'    => self::getTable(),
            'field'    => 'name',
            'name'     => __('Nome', 'reservafrota'),
            'datatype' => 'itemlink',
            'massiveaction' => false,
        ];
        $options[] = [
            'id'       => 2,
            'table'    => self::getTable(),
            'field'    => 'cnh',
            'name'     => __('CNH', 'reservafrota'),
            'datatype' => 'string',
        ];
        $options[] = [
            'id'       => 3,
            'table'    => self::getTable(),
            'field'    => 'phone',
            'name'     => __('Telefone', 'reservafrota'),
            'datatype' => 'string',
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
            'id'        => 6,
            'table'     => 'glpi_users',
            'field'     => 'name',
            'linkfield' => 'users_id',
            'name'      => __('Usuário GLPI vinculado', 'reservafrota'),
            'datatype'  => 'dropdown',
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
        TemplateRenderer::getInstance()->display('@reservafrota/driver.form.html.twig', [
            'item'        => $this,
            'params'      => $options,
            'can_edit'    => $can_edit,
            'picture_url' => $this->getPictureUrl(),
            'web_dir'     => Plugin::getWebDir('reservafrota'),
            'history'     => $history,
            'users'       => self::getUsersForSelect(),
        ]);
        return true;
    }
}
