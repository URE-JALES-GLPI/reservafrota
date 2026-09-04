<?php

namespace GlpiPlugin\Reservafrota;

use CommonDBTM;
use CommonGLPI;
use Dropdown;
use Html;
use Profile as GlpiProfile;
use ProfileRight;
use Session;

/**
 * Gestão de permissões do plugin, exibida como aba dentro de
 * Administração > Perfis. Em vez da matriz de direitos padrão, usa um
 * seletor de "nível de acesso" por recurso (Carros e Agendamentos),
 * inspirado no padrão do plugin Floorplan. Ao salvar, o handler atualiza
 * a sessão ativa na hora (changeProfile) — sem precisar deslogar.
 */
class Profile extends CommonDBTM
{
    // Usamos o direito nativo 'profile' apenas para decidir quem pode EDITAR
    // esta aba. Os direitos do plugin (reservafrota::car / reservafrota::booking)
    // são gravados em glpi_profilerights.
    public static $rightname = 'profile';

    public static function getTypeName($nb = 0)
    {
        return __('Reserva de Frota', 'reservafrota');
    }

    /**
     * Define os recursos do plugin e os níveis de acesso oferecidos no
     * seletor. Cada opção é o valor (bitmask) gravado em glpi_profilerights.
     *
     * Constantes GLPI: READ=1, UPDATE=2, CREATE=4, DELETE=8, PURGE=16.
     * Bit específico do plugin: Booking::APPROVE=1024.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function getAllRights(): array
    {
        return [
            [
                'field'   => 'reservafrota::car',
                'label'   => Car::getTypeName(2),
                'icon'    => 'ti ti-car',
                'help'    => 'Controla o cadastro e a gestão da frota de carros.',
                'default' => 0,
                'levels'  => [
                    0                                       => '— Sem acesso —',
                    READ                                    => 'Visualizar a frota',
                    READ | CREATE                           => 'Visualizar e cadastrar',
                    READ | CREATE | UPDATE                  => 'Cadastrar e editar',
                    READ | CREATE | UPDATE | DELETE | PURGE => 'Acesso total (incl. excluir)',
                ],
            ],
            [
                'field'   => 'reservafrota::booking',
                'label'   => Booking::getTypeName(2),
                'icon'    => 'ti ti-calendar-event',
                'help'    => 'Controla a criação e a aprovação dos agendamentos.',
                'default' => 0,
                'levels'  => [
                    0                                                        => '— Sem acesso —',
                    READ                                                     => 'Ver os próprios agendamentos',
                    READ | CREATE                                            => 'Criar agendamentos',
                    READ | CREATE | Booking::APPROVE                         => 'Criar e aprovar (gestor)',
                    READ | CREATE | UPDATE | DELETE | PURGE | Booking::APPROVE => 'Acesso total (gestor)',
                ],
            ],
        ];
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof GlpiProfile && $item->getField('id')) {
            return "<span class='d-inline-flex align-items-center gap-1'>"
                . "<i class='ti ti-car'></i><span>" . self::getTypeName() . "</span></span>";
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof GlpiProfile && $item->getField('id')) {
            self::showForProfile((int) $item->getID());
            return true;
        }
        return false;
    }

    /**
     * Renderiza o formulário de níveis de acesso para um perfil.
     */
    public static function showForProfile(int $profiles_id): void
    {
        global $CFG_GLPI;

        $canedit = Session::haveRightsOr(self::$rightname, [CREATE, UPDATE, PURGE]);
        $action  = $CFG_GLPI['root_doc'] . '/plugins/reservafrota/front/profile.form.php';

        echo "<form name='reservafrota_profile_form' method='post' action='" . htmlspecialchars($action) . "'>";
        echo "<div class='spaced'>";
        echo "<table class='tab_cadre_fixehov'>";
        echo "<tr class='headerRow'><th colspan='2'>"
            . "<i class='ti ti-car me-1'></i> " . self::getTypeName()
            . "</th></tr>";

        foreach (self::getAllRights() as $right) {
            $current = self::getProfileRightValue($profiles_id, $right['field'], (int) $right['default']);
            $levels  = $right['levels'];

            // Se o valor atual não casa com nenhum nível pré-definido,
            // adiciona uma opção "personalizada" para nunca perder o valor.
            if (!array_key_exists($current, $levels)) {
                $levels[$current] = sprintf(__('Personalizado (%d)'), $current);
            }

            echo "<tr class='tab_bg_1'>";
            echo "<td width='45%'>"
                . "<i class='{$right['icon']} me-1'></i> <strong>" . htmlspecialchars($right['label']) . "</strong>"
                . "<br><small class='text-muted'>" . htmlspecialchars($right['help']) . "</small>"
                . "</td>";
            echo "<td>";
            if ($canedit) {
                Dropdown::showFromArray(
                    'rights[' . $right['field'] . ']',
                    $levels,
                    ['value' => $current, 'width' => '100%']
                );
            } else {
                echo htmlspecialchars($levels[$current]);
            }
            echo "</td></tr>";
        }

        if ($canedit) {
            echo "<tr class='tab_bg_2'><td colspan='2' class='center' style='padding:12px'>";
            echo Html::hidden('profiles_id', ['value' => $profiles_id]);
            echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
            echo "<button type='submit' name='update' value='1' class='btn btn-primary'>"
                . "<i class='ti ti-device-floppy me-1'></i> " . _sx('button', 'Save')
                . "</button>";
            echo "</td></tr>";
        }

        echo "</table>";
        echo "</div>";
        Html::closeForm();
    }

    /**
     * Lê o valor de um direito do plugin para um perfil.
     */
    public static function getProfileRightValue(int $profiles_id, string $field, int $default = 0): int
    {
        global $DB;

        $iterator = $DB->request([
            'SELECT' => ['rights'],
            'FROM'   => ProfileRight::getTable(),
            'WHERE'  => [
                'profiles_id' => $profiles_id,
                'name'        => $field
            ],
            'LIMIT'  => 1
        ]);

        foreach ($iterator as $row) {
            return (int) ($row['rights'] ?? $default);
        }

        return $default;
    }

    public function post_updateItem($history = 1)
    {
        parent::post_updateItem($history);

        if (Session::getLoginUserID()) {
            self::changeProfile();
        }
    }

    /**
     * Grava os níveis de acesso enviados pelo formulário.
     *
     * @param array<string, int|string> $rights  mapa field => valor
     */
    public static function saveRights(int $profiles_id, array $rights): void
    {
        global $DB;

        $valid = array_column(self::getAllRights(), 'field');

        foreach ($rights as $field => $value) {
            if (!in_array($field, $valid, true)) {
                continue;
            }
            $value = (int) $value;

            $existing = $DB->request([
                'SELECT' => ['id'],
                'FROM'   => ProfileRight::getTable(),
                'WHERE'  => ['profiles_id' => $profiles_id, 'name' => $field],
            ])->current();

            if (is_array($existing) && isset($existing['id'])) {
                $DB->update(ProfileRight::getTable(), ['rights' => $value], ['id' => (int) $existing['id']]);
            } else {
                $DB->insert(ProfileRight::getTable(), [
                    'profiles_id' => $profiles_id,
                    'name'        => $field,
                    'rights'      => $value,
                ]);
            }
        }
    }

    /**
     * Atualiza, na sessão ativa, os direitos do plugin recém-salvos —
     * assim a mudança vale imediatamente, sem o usuário precisar relogar.
     */
    public static function changeProfile(): void
    {
        global $DB;

        // Limpa os direitos antigos da sessão
        unset($_SESSION['glpiactiveprofile']['reservafrota::booking']);
        unset($_SESSION['glpiactiveprofile']['reservafrota::car']);

        $active = (int) ($_SESSION['glpiactiveprofile']['id'] ?? 0);

        if ($active <= 0) {
            return;
        }

        $fields = array_column(self::getAllRights(), 'field');

        $iterator = $DB->request([
            'SELECT' => ['name', 'rights'],
            'FROM'   => ProfileRight::getTable(),
            'WHERE'  => [
                'profiles_id' => $active,
                'name'        => $fields
            ]
        ]);

        foreach ($iterator as $row) {
            $_SESSION['glpiactiveprofile'][$row['name']] = (int) $row['rights'];
        }
    }
}