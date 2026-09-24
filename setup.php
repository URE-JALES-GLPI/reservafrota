<?php

/**
 * Reservafrota — Agendamento de carros para GLPI 11
 *
 * setup.php: ponto de entrada do plugin. Declara versão, requisitos,
 * hooks (CSS/JS, menu, abas de perfil) e a checagem de pré-requisitos.
 */

use Glpi\Plugin\Hooks;
use GlpiPlugin\Reservafrota\Booking;
use GlpiPlugin\Reservafrota\Car;
use GlpiPlugin\Reservafrota\Driver;
use GlpiPlugin\Reservafrota\Profile as ReservafrotaProfile;

define('PLUGIN_RESERVAFROTA_VERSION', '1.0.0');

// Faixa de versões do GLPI suportadas
define('PLUGIN_RESERVAFROTA_MIN_GLPI_VERSION', '11.0.0');
define('PLUGIN_RESERVAFROTA_MAX_GLPI_VERSION', '11.0.99');

/**
 * Inicialização do plugin — chamada em todas as páginas do GLPI.
 */
function plugin_init_reservafrota()
{
    global $PLUGIN_HOOKS;

    // O plugin segue a proteção CSRF do GLPI (formulários enviam o token).
    $PLUGIN_HOOKS['csrf_compliant']['reservafrota'] = true;

    // Folha de estilo e script. Os arquivos ficam em public/ (exigência do
    // GLPI 11), mas o caminho registrado NÃO inclui "public/" — o GLPI resolve
    // /plugins/reservafrota/css/reservafrota.css -> public/css/reservafrota.css.
    $PLUGIN_HOOKS[Hooks::ADD_CSS]['reservafrota']        = 'css/reservafrota.css';
    $PLUGIN_HOOKS[Hooks::ADD_JAVASCRIPT]['reservafrota'] = ['js/agenda.js', 'js/analytics.js', 'js/calendar.js'];

    // Aba de permissões dentro de Administração > Perfis.
    Plugin::registerClass(ReservafrotaProfile::class, [
        'addtabon' => Profile::class,
    ]);

    // Registra as classes do plugin para que o GLPI reconheça seus direitos
    // (reservafrota::booking e reservafrota::car) e os carregue na sessão de
    // qualquer usuário no login — sem isso, perfis não-admin não recebem o
    // direito na sessão mesmo tendo o valor gravado em glpi_profilerights.
    Plugin::registerClass(Booking::class);
    Plugin::registerClass(Car::class);
    Plugin::registerClass(Driver::class);

    // Auto-migração silenciosa: se o plugin foi atualizado por cópia de arquivos
    // (sem passar por Configurar > Plugins > Atualizar), cria as tabelas/colunas
    // novas na primeira página carregada — evita o erro "Table doesn't exist".
    // Não usa Migration aqui para não interferir no fluxo normal de instalação.
    try {
        if (isset($GLOBALS['DB']) && $GLOBALS['DB'] instanceof \DBmysql) {
            $db = $GLOBALS['DB'];
            $charset   = \DBConnection::getDefaultCharset();
            $collation = \DBConnection::getDefaultCollation();
            $drvTable  = Driver::getTable();
            if (!$db->tableExists($drvTable)) {
                $db->doQuery("CREATE TABLE `$drvTable` (
                    `id`            int unsigned NOT NULL AUTO_INCREMENT,
                    `name`          varchar(255) NOT NULL DEFAULT '',
                    `cnh`           varchar(20)  NOT NULL DEFAULT '',
                    `phone`         varchar(50)  NOT NULL DEFAULT '',
                    `picture`       varchar(255) DEFAULT NULL,
                    `is_active`     tinyint      NOT NULL DEFAULT 1,
                    `comment`       text         DEFAULT NULL,
                    `is_deleted`    tinyint      NOT NULL DEFAULT 0,
                    `date_creation` timestamp    NULL DEFAULT NULL,
                    `date_mod`      timestamp    NULL DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    KEY `is_active` (`is_active`),
                    KEY `is_deleted` (`is_deleted`)
                ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}");
            }
            $bkTable = Booking::getTable();
            if ($db->tableExists($bkTable) && !$db->fieldExists($bkTable, 'plugin_reservafrota_drivers_id')) {
                $db->doQuery("ALTER TABLE `$bkTable`
                    ADD COLUMN `plugin_reservafrota_drivers_id` int unsigned NOT NULL DEFAULT 0 AFTER `driver`,
                    ADD KEY `plugin_reservafrota_drivers_id` (`plugin_reservafrota_drivers_id`)");
            }
            // Garante que o direito do motorista exista (para instalações antigas)
            $exists = $db->request([
                'FROM'  => \ProfileRight::getTable(),
                'WHERE' => ['name' => 'reservafrota::driver'],
                'LIMIT' => 1,
            ])->current();
            if (!$exists) {
                \ProfileRight::addProfileRights(['reservafrota::driver']);
                $current = (int) ($_SESSION['glpiactiveprofile']['id'] ?? 0);
                if ($current > 0) {
                    $db->update(\ProfileRight::getTable(), ['rights' => ALLSTANDARDRIGHT], [
                        'profiles_id' => $current,
                        'name'        => 'reservafrota::driver',
                    ]);
                    \GlpiPlugin\Reservafrota\Profile::changeProfile();
                }
            }
        }
    } catch (\Throwable $e) {
        // Silencioso: instalação/migração completa será feita em Configurar > Plugins > Instalar/Atualizar
    }

    // ESSENCIAL: a cada login/troca de perfil, o GLPI dispara este hook.
    // Ele carrega os direitos do plugin (reservafrota::booking / reservafrota::car)
    // do banco para a sessão ativa. Sem isso, Session::haveRight() não enxerga
    // os direitos do plugin para usuários que apenas logam (ex: self-service),
    // mesmo com o valor gravado em glpi_profilerights.
    $PLUGIN_HOOKS['change_profile']['reservafrota'] = [ReservafrotaProfile::class, 'changeProfile'];

    // Entrada de menu em "Ferramentas".
    $PLUGIN_HOOKS[Hooks::MENU_TOADD]['reservafrota'] = [
        'tools' => Booking::class,
    ];

    // Link na interface simplificada (Helpdesk), para usuários self-service.
    $PLUGIN_HOOKS['helpdesk_menu_entry']['reservafrota']      = '/front/calendar.php';
    $PLUGIN_HOOKS['helpdesk_menu_entry_icon']['reservafrota'] = 'ti ti-car';

    // GLPI 11: por padrão os scripts de plugin exigem a interface central.
    // Liberamos as páginas usadas pelo funcionário (interface simplificada)
    // para qualquer usuário autenticado — a permissão real continua sendo
    // verificada por Session::checkRight() dentro de cada script.
    if (class_exists(\Glpi\Http\Firewall::class)) {
        $auth = \Glpi\Http\Firewall::STRATEGY_AUTHENTICATED;
        \Glpi\Http\Firewall::addPluginStrategyForLegacyScripts('reservafrota', '#^/front/agenda\.php$#', $auth);
        \Glpi\Http\Firewall::addPluginStrategyForLegacyScripts('reservafrota', '#^/front/calendar\.php$#', $auth);
        \Glpi\Http\Firewall::addPluginStrategyForLegacyScripts('reservafrota', '#^/front/booking\.php$#', $auth);
        \Glpi\Http\Firewall::addPluginStrategyForLegacyScripts('reservafrota', '#^/front/car\.php$#', $auth);
        \Glpi\Http\Firewall::addPluginStrategyForLegacyScripts('reservafrota', '#^/front/analytics\.php$#', $auth);
        \Glpi\Http\Firewall::addPluginStrategyForLegacyScripts('reservafrota', '#^/ajax/carsstatus\.php$#', $auth);
        \Glpi\Http\Firewall::addPluginStrategyForLegacyScripts('reservafrota', '#^/ajax/month\.php$#', $auth);
        \Glpi\Http\Firewall::addPluginStrategyForLegacyScripts('reservafrota', '#^/ajax/conflict\.php$#', $auth);
        \Glpi\Http\Firewall::addPluginStrategyForLegacyScripts('reservafrota', '#^/ajax/pending\.php$#', $auth);
        \Glpi\Http\Firewall::addPluginStrategyForLegacyScripts('reservafrota', '#^/front/sheet\.php$#', $auth);
        \Glpi\Http\Firewall::addPluginStrategyForLegacyScripts('reservafrota', '#^/front/booking\.form\.php$#', $auth);
        \Glpi\Http\Firewall::addPluginStrategyForLegacyScripts('reservafrota', '#^/front/car\.picture\.php$#', $auth);
        \Glpi\Http\Firewall::addPluginStrategyForLegacyScripts('reservafrota', '#^/front/driver\.php$#', $auth);
        \Glpi\Http\Firewall::addPluginStrategyForLegacyScripts('reservafrota', '#^/front/driver\.form\.php$#', $auth);
        \Glpi\Http\Firewall::addPluginStrategyForLegacyScripts('reservafrota', '#^/front/driver\.picture\.php$#', $auth);
        \Glpi\Http\Firewall::addPluginStrategyForLegacyScripts('reservafrota', '#^/front/profile\.form\.php$#', $auth);
        \Glpi\Http\Firewall::addPluginStrategyForLegacyScripts('reservafrota', '#^/front/debug\.php$#', $auth);
        \Glpi\Http\Firewall::addPluginStrategyForLegacyScripts('reservafrota', '#^/ajax/bookinglist\.php$#', $auth);
        \Glpi\Http\Firewall::addPluginStrategyForLegacyScripts('reservafrota', '#^/ajax/carslot\.php$#', $auth);
        \Glpi\Http\Firewall::addPluginStrategyForLegacyScripts('reservafrota', '#^/ajax/availability\.php$#', $auth);
        \Glpi\Http\Firewall::addPluginStrategyForLegacyScripts('reservafrota', '#^/front/export\.php$#', $auth);
        \Glpi\Http\Firewall::addPluginStrategyForLegacyScripts('reservafrota', '#^/front/history\.php$#', $auth);
    }
}

/**
 * Metadados exibidos em Configurar > Plugins.
 */
function plugin_version_reservafrota()
{
    return [
        'name'         => 'Reserva de Frota',
        'version'      => PLUGIN_RESERVAFROTA_VERSION,
        'author'       => 'Fox',
        'license'      => 'MIT',
        'homepage'     => '',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_RESERVAFROTA_MIN_GLPI_VERSION,
                'max' => PLUGIN_RESERVAFROTA_MAX_GLPI_VERSION,
            ],
        ],
    ];
}

/**
 * Checagem de pré-requisitos antes da instalação.
 */
function plugin_reservafrota_check_prerequisites()
{
    // A faixa de versões já é validada pelo GLPI a partir de plugin_version.
    return true;
}

/**
 * Checagem de configuração — chamada em todas as páginas.
 * Retornar false desativa o plugin automaticamente.
 */
function plugin_reservafrota_check_config($verbose = false)
{
    return true;
}