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

        TemplateRenderer::getInstance()->display('@reservafrota/car.form.html.twig', [
            'item'        => $this,
            'params'      => $options,
            'can_edit'    => Session::haveRight(self::$rightname, UPDATE),
            'picture_url' => $this->getPictureUrl(),
            'web_dir'     => Plugin::getWebDir('reservafrota'),
            'history'     => $history,
        ]);

        return true;
    }
}
