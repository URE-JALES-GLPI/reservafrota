<?php

/**
 * hook.php: instalação e desinstalação do plugin.
 * Cria as tabelas, registra os direitos de perfil e as colunas padrão de busca.
 */

use GlpiPlugin\Reservafrota\Booking;
use GlpiPlugin\Reservafrota\Car;
use GlpiPlugin\Reservafrota\Driver;
use GlpiPlugin\Reservafrota\Maintenance;
use GlpiPlugin\Reservafrota\MaintenancePlan;
use GlpiPlugin\Reservafrota\Profile as ReservafrotaProfile;

/**
 * Instalação: cria as tabelas e configura permissões.
 */
function plugin_reservafrota_install()
{
    /** @var DBmysql $DB */
    global $DB;

    $charset   = DBConnection::getDefaultCharset();
    $collation = DBConnection::getDefaultCollation();
    $migration = new Migration(PLUGIN_RESERVAFROTA_VERSION);

    // ---- Tabela de carros (frota) ----
    $cars = Car::getTable();
    if (!$DB->tableExists($cars)) {
        $DB->doQuery("CREATE TABLE `$cars` (
            `id`            int unsigned NOT NULL AUTO_INCREMENT,
            `name`          varchar(255) NOT NULL DEFAULT '',
            `plate`         varchar(20)  NOT NULL DEFAULT '',
            `model_year`    int          NOT NULL DEFAULT 0,
            `picture`       varchar(255) DEFAULT NULL,
            `is_active`     tinyint      NOT NULL DEFAULT 1,
            `comment`       text         DEFAULT NULL,
            `is_deleted`    tinyint      NOT NULL DEFAULT 0,
            `date_creation` timestamp    NULL DEFAULT NULL,
            `date_mod`      timestamp    NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `plate` (`plate`),
            KEY `is_active` (`is_active`),
            KEY `is_deleted` (`is_deleted`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}");
    }

    // ---- Tabela de motoristas ----
    $drivers = Driver::getTable();
    if (!$DB->tableExists($drivers)) {
        $DB->doQuery("CREATE TABLE `$drivers` (
            `id`            int unsigned NOT NULL AUTO_INCREMENT,
            `name`          varchar(255) NOT NULL DEFAULT '',
            `cnh`           varchar(20)  NOT NULL DEFAULT '',
            `phone`         varchar(50)  NOT NULL DEFAULT '',
            `users_id`      int unsigned NOT NULL DEFAULT 0,
            `picture`       varchar(255) DEFAULT NULL,
            `is_active`     tinyint      NOT NULL DEFAULT 1,
            `comment`       text         DEFAULT NULL,
            `is_deleted`    tinyint      NOT NULL DEFAULT 0,
            `date_creation` timestamp    NULL DEFAULT NULL,
            `date_mod`      timestamp    NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `is_active` (`is_active`),
            KEY `is_deleted` (`is_deleted`),
            KEY `users_id` (`users_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}");
    }

    // Migração: vínculo do motorista com o usuário GLPI (pré-seleção na reserva).
    if ($DB->tableExists($drivers) && !$DB->fieldExists($drivers, 'users_id')) {
        $DB->doQuery("ALTER TABLE `$drivers`
            ADD COLUMN `users_id` int unsigned NOT NULL DEFAULT 0 AFTER `phone`,
            ADD KEY `users_id` (`users_id`)");
    }
    // Backfill: liga motoristas sem vínculo cujo nome é exatamente igual ao
    // nome do usuário GLPI ("Nome Sobrenome") ou ao login.
    if ($DB->tableExists($drivers) && $DB->fieldExists($drivers, 'users_id')) {
        try {
            $DB->doQuery("UPDATE `$drivers` d
                INNER JOIN `glpi_users` u
                    ON (TRIM(CONCAT_WS(' ', u.`firstname`, u.`realname`)) = TRIM(d.`name`)
                        OR u.`name` = TRIM(d.`name`))
                SET d.`users_id` = u.`id`
                WHERE d.`users_id` = 0 AND d.`is_deleted` = 0
                    AND u.`is_deleted` = 0 AND TRIM(d.`name`) <> ''");
        } catch (\Throwable $e) {}
    }

    // ---- Tabela de agendamentos ----
    $bookings = Booking::getTable();
    if (!$DB->tableExists($bookings)) {
        $DB->doQuery("CREATE TABLE `$bookings` (
            `id`                         int unsigned NOT NULL AUTO_INCREMENT,
            `name`                       varchar(255) NOT NULL DEFAULT '',
            `plugin_reservafrota_cars_id`  int unsigned NOT NULL DEFAULT 0,
            `users_id`                   int unsigned NOT NULL DEFAULT 0,
            `groups_id`                  int unsigned NOT NULL DEFAULT 0,
            `driver`                     varchar(255) DEFAULT NULL,
            `has_companion`              tinyint(1)   NOT NULL DEFAULT 0,
            `companion`                  varchar(255) DEFAULT NULL,
            `date_departure`             datetime     DEFAULT NULL,
            `date_arrival`               datetime     DEFAULT NULL,
            `destination`                varchar(255) DEFAULT NULL,
            `reason`                     text         DEFAULT NULL,
            `status`                     int          NOT NULL DEFAULT 1,
            `users_id_approver`          int unsigned NOT NULL DEFAULT 0,
            `date_validation`            datetime     DEFAULT NULL,
            `date_returned`              datetime     DEFAULT NULL,
            `arrival_sheet`              varchar(255) DEFAULT NULL,
            `arrival_obs`                text         DEFAULT NULL,
            `km_initial`                 int unsigned DEFAULT NULL,
            `km_final`                   int unsigned DEFAULT NULL,
            `comment_validation`         text         DEFAULT NULL,
            `request_code`               varchar(32)  NOT NULL DEFAULT '',
            `is_deleted`                 tinyint      NOT NULL DEFAULT 0,
            `date_creation`              timestamp    NULL DEFAULT NULL,
            `date_mod`                   timestamp    NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `plugin_reservafrota_cars_id` (`plugin_reservafrota_cars_id`),
            KEY `users_id` (`users_id`),
            KEY `groups_id` (`groups_id`),
            KEY `users_id_approver` (`users_id_approver`),
            KEY `status` (`status`),
            KEY `date_departure` (`date_departure`),
            KEY `request_code` (`request_code`),
            KEY `is_deleted` (`is_deleted`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}");
    }

    // Migração: adiciona o campo Setor (groups_id) em instalações que já
    // existiam antes desta versão.
    if ($DB->tableExists($bookings) && !$DB->fieldExists($bookings, 'groups_id')) {
        $DB->doQuery("ALTER TABLE `$bookings`
            ADD COLUMN `groups_id` int unsigned NOT NULL DEFAULT 0 AFTER `users_id`,
            ADD KEY `groups_id` (`groups_id`)");
    }

    // Migração: data de chegada/retorno do carro (controle de quem já voltou).
    if ($DB->tableExists($bookings) && !$DB->fieldExists($bookings, 'date_returned')) {
        $DB->doQuery("ALTER TABLE `$bookings`
            ADD COLUMN `date_returned` datetime DEFAULT NULL AFTER `date_validation`");
    }

    // Migração: folha de agendamento anexada na chegada.
    if ($DB->tableExists($bookings) && !$DB->fieldExists($bookings, 'arrival_sheet')) {
        $DB->doQuery("ALTER TABLE `$bookings`
            ADD COLUMN `arrival_sheet` varchar(255) DEFAULT NULL AFTER `date_returned`");
    }

    if ($DB->tableExists($bookings) && !$DB->fieldExists($bookings, 'arrival_obs')) {
        $DB->doQuery("ALTER TABLE `$bookings`
            ADD COLUMN `arrival_obs` text DEFAULT NULL AFTER `arrival_sheet`");
    }

    if ($DB->tableExists($bookings) && !$DB->fieldExists($bookings, 'driver')) {
        $DB->doQuery("ALTER TABLE `$bookings`
            ADD COLUMN `driver` varchar(255) DEFAULT NULL AFTER `groups_id`,
            ADD COLUMN `has_companion` tinyint(1) NOT NULL DEFAULT 0 AFTER `driver`,
            ADD COLUMN `companion` varchar(255) DEFAULT NULL AFTER `has_companion`");
    }

    // Migração: KM final do veículo, informado ao finalizar a viagem.
    if ($DB->tableExists($bookings) && !$DB->fieldExists($bookings, 'km_final')) {
        $DB->doQuery("ALTER TABLE `$bookings`
            ADD COLUMN `km_final` int unsigned DEFAULT NULL AFTER `arrival_obs`");
    }

    // Migração: KM inicial do veículo, informado na saída (Forms/Sheets).
    if ($DB->tableExists($bookings) && !$DB->fieldExists($bookings, 'km_initial')) {
        $DB->doQuery("ALTER TABLE `$bookings`
            ADD COLUMN `km_initial` int unsigned DEFAULT NULL AFTER `arrival_obs`");
    }

    // Migração: motorista cadastrado (drivers_id) — novos agendamentos usam
    // FK para a tabela de motoristas; mantém `driver` legado para compatibilidade.
    if ($DB->tableExists($bookings) && !$DB->fieldExists($bookings, 'plugin_reservafrota_drivers_id')) {
        $DB->doQuery("ALTER TABLE `$bookings`
            ADD COLUMN `plugin_reservafrota_drivers_id` int unsigned NOT NULL DEFAULT 0 AFTER `driver`,
            ADD KEY `plugin_reservafrota_drivers_id` (`plugin_reservafrota_drivers_id`)");
    }

    // Migração: código da solicitação (agrupa repetições da mesma viagem).
    if ($DB->tableExists($bookings) && !$DB->fieldExists($bookings, 'request_code')) {
        $DB->doQuery("ALTER TABLE `$bookings`
            ADD COLUMN `request_code` varchar(32) NOT NULL DEFAULT '' AFTER `comment_validation`,
            ADD KEY `request_code` (`request_code`)");
    }
    if ($DB->tableExists($bookings) && $DB->fieldExists($bookings, 'request_code')) {
        try {
            // Código numérico crescente: o próprio id (base das repetições).
            $DB->doQuery("UPDATE `$bookings`
                SET `request_code` = `id`
                WHERE `request_code` = '' OR `request_code` LIKE 'RF-%'");
        } catch (\Throwable $e) {}
    }

    // ---- Sincronização com Google Sheets (Forms de saída/chegada) ----
    $sheetCfg = 'glpi_plugin_reservafrota_sheetcfg';
    if (!$DB->tableExists($sheetCfg)) {
        $DB->doQuery("CREATE TABLE `$sheetCfg` (
            `id`             int unsigned NOT NULL AUTO_INCREMENT,
            `spreadsheet_id` varchar(128) NOT NULL DEFAULT '',
            `api_key`        varchar(255) NOT NULL DEFAULT '',
            `sheet_range`    varchar(64)  NOT NULL DEFAULT 'Respostas!A2:F',
            `last_row`       int unsigned NOT NULL DEFAULT 1,
            `last_sync`      datetime     DEFAULT NULL,
            `enabled`        tinyint      NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}");
    }
    $sheetLog = 'glpi_plugin_reservafrota_sheetlog';
    if (!$DB->tableExists($sheetLog)) {
        $DB->doQuery("CREATE TABLE `$sheetLog` (
            `id`            int unsigned NOT NULL AUTO_INCREMENT,
            `row_num`       int unsigned NOT NULL DEFAULT 0,
            `received_at`   datetime     DEFAULT NULL,
            `request_code`  varchar(32)  NOT NULL DEFAULT '',
            `bookings_id`   int unsigned NOT NULL DEFAULT 0,
            `event`         varchar(16)  NOT NULL DEFAULT '',
            `km`            int          NOT NULL DEFAULT 0,
            `driver_name`   varchar(255) NOT NULL DEFAULT '',
            `obs`           text         DEFAULT NULL,
            `status`        varchar(16)  NOT NULL DEFAULT '',
            `message`       text         DEFAULT NULL,
            `source`        varchar(16)  NOT NULL DEFAULT 'sheet',
            `date_creation` timestamp    NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `request_code` (`request_code`),
            KEY `status` (`status`),
            KEY `row_num` (`row_num`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}");
    }
    if ($DB->tableExists($sheetLog) && !$DB->fieldExists($sheetLog, 'obs')) {
        $DB->doQuery("ALTER TABLE `$sheetLog`
            ADD COLUMN `obs` text DEFAULT NULL AFTER `driver_name`");
    }

    // ---- KM atual do carro ----
    if ($DB->tableExists($cars) && !$DB->fieldExists($cars, 'km_current')) {
        $DB->doQuery("ALTER TABLE `$cars`
            ADD COLUMN `km_current` int unsigned NOT NULL DEFAULT 0 AFTER `model_year`,
            ADD COLUMN `km_updated` datetime DEFAULT NULL AFTER `km_current`");
    }
    if ($DB->tableExists($cars) && !$DB->fieldExists($cars, 'km_updated')) {
        $DB->doQuery("ALTER TABLE `$cars`
            ADD COLUMN `km_updated` datetime DEFAULT NULL AFTER `km_current`");
    }

    // ---- Tabela de manutenções programadas (por carro, por KM) ----
    $plans = MaintenancePlan::getTable();
    if (!$DB->tableExists($plans)) {
        $DB->doQuery("CREATE TABLE `$plans` (
            `id`                                    int unsigned NOT NULL AUTO_INCREMENT,
            `plugin_reservafrota_cars_id`           int unsigned NOT NULL DEFAULT 0,
            `name`                                  varchar(255) NOT NULL DEFAULT '',
            `due_km`                                int unsigned NOT NULL DEFAULT 0,
            `warn_km`                               int unsigned NOT NULL DEFAULT 1000,
            `comment`                               text         DEFAULT NULL,
            `is_done`                               tinyint      NOT NULL DEFAULT 0,
            `date_done`                             datetime     DEFAULT NULL,
            `last_notify`                           datetime     DEFAULT NULL,
            `is_deleted`                            tinyint      NOT NULL DEFAULT 0,
            `date_creation`                         timestamp    NULL DEFAULT NULL,
            `date_mod`                              timestamp    NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `plugin_reservafrota_cars_id` (`plugin_reservafrota_cars_id`),
            KEY `is_done` (`is_done`),
            KEY `due_km` (`due_km`),
            KEY `is_deleted` (`is_deleted`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}");
    }
    if ($DB->tableExists($plans) && !$DB->fieldExists($plans, 'warn_km')) {
        $DB->doQuery("ALTER TABLE `$plans` ADD COLUMN `warn_km` int unsigned NOT NULL DEFAULT 1000 AFTER `due_km`");
    }
    if ($DB->tableExists($plans) && !$DB->fieldExists($plans, 'last_notify')) {
        $DB->doQuery("ALTER TABLE `$plans` ADD COLUMN `last_notify` datetime DEFAULT NULL AFTER `date_done`");
    }

    // ---- Tabela de manutenções realizadas (histórico: o quê, quando, KM) ----
    $maint = Maintenance::getTable();
    if (!$DB->tableExists($maint)) {
        $DB->doQuery("CREATE TABLE `$maint` (
            `id`                                    int unsigned NOT NULL AUTO_INCREMENT,
            `plugin_reservafrota_cars_id`           int unsigned NOT NULL DEFAULT 0,
            `plugin_reservafrota_maintenanceplans_id` int unsigned NOT NULL DEFAULT 0,
            `maintenance_date`                      date         DEFAULT NULL,
            `km`                                    int unsigned NOT NULL DEFAULT 0,
            `description`                           text         DEFAULT NULL,
            `users_id`                              int unsigned NOT NULL DEFAULT 0,
            `is_deleted`                            tinyint      NOT NULL DEFAULT 0,
            `date_creation`                         timestamp    NULL DEFAULT NULL,
            `date_mod`                              timestamp    NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `plugin_reservafrota_cars_id` (`plugin_reservafrota_cars_id`),
            KEY `plugin_reservafrota_maintenanceplans_id` (`plugin_reservafrota_maintenanceplans_id`),
            KEY `maintenance_date` (`maintenance_date`),
            KEY `is_deleted` (`is_deleted`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}");
    }

    $migration->executeMigration();

    // ---- Permissões ----
    // Limpa resíduos de uma instalação anterior (caso o plugin tenha sido
    // removido apagando a pasta, sem desinstalar pelo GLPI). Sem isto, o
    // ProfileRight::addProfileRights abaixo falharia com "Duplicate entry".
    foreach (ReservafrotaProfile::getAllRights() as $right) {
        $DB->delete('glpi_profilerights', ['name' => $right['field']]);
    }

    // Registra os direitos (valor 0) para todos os perfis.
    foreach (ReservafrotaProfile::getAllRights() as $right) {
        ProfileRight::addProfileRights([$right['field']]);
    }

    // Concede todos os direitos ao perfil atual (normalmente o super-admin
    // que está instalando), incluindo o direito de aprovar agendamentos.
    $current_profile = (int) ($_SESSION['glpiactiveprofile']['id'] ?? 0);
    if ($current_profile > 0) {
        $DB->update('glpi_profilerights', [
            'rights' => ALLSTANDARDRIGHT,
        ], [
            'profiles_id' => $current_profile,
            'name'        => 'reservafrota::car',
        ]);
        $DB->update('glpi_profilerights', [
            'rights' => ALLSTANDARDRIGHT,
        ], [
            'profiles_id' => $current_profile,
            'name'        => 'reservafrota::driver',
        ]);
        $DB->update('glpi_profilerights', [
            'rights' => ALLSTANDARDRIGHT | Booking::APPROVE,
        ], [
            'profiles_id' => $current_profile,
            'name'        => 'reservafrota::booking',
        ]);
    }

    // ---- Ação automática: leitura do Sheets (Forms de saída/chegada) ----
    // A cada 15 minutos. O método executado é SheetSync::cronSheetsync().
    try {
        if (class_exists('CronTask') && method_exists('CronTask', 'register')) {
            \CronTask::register(
                'GlpiPlugin\\Reservafrota\\SheetSync',
                'sheetsync',
                15 * MINUTE_TIMESTAMP,
                [
                    'comment' => 'Reserva de Frota: importa eventos de saída/chegada do Google Sheets',
                    'mode'    => \CronTask::MODE_INTERNAL,
                ]
            );
        }
    } catch (\Throwable $e) {}

    // ---- Colunas padrão exibidas na listagem ----
    $prefs = [
        Car::class    => [2, 3, 4],     // placa, ano, ativo
        Driver::class => [2, 3, 4],     // CNH, telefone, ativo
        Booking::class => [2, 3, 5, 8], // carro, solicitante, saída, status
    ];
    foreach ($prefs as $itemtype => $columns) {
        $rank = 1;
        foreach ($columns as $num) {
            $exists = countElementsInTable('glpi_displaypreferences', [
                'itemtype' => $itemtype,
                'num'      => $num,
                'users_id' => 0,
            ]);
            if (!$exists) {
                $DB->insert('glpi_displaypreferences', [
                    'itemtype' => $itemtype,
                    'num'      => $num,
                    'rank'     => $rank++,
                    'users_id' => 0,
                ]);
            }
        }
    }

    return true;
}

/**
 * Desinstalação: remove tabelas, direitos e preferências de exibição.
 */
function plugin_reservafrota_uninstall()
{
    /** @var DBmysql $DB */
    global $DB;

    // Remove os direitos de perfil.
    foreach (ReservafrotaProfile::getAllRights() as $right) {
        ProfileRight::deleteProfileRights([$right['field']]);
    }

    // Remove preferências de exibição.
    foreach ([Car::class, Driver::class, Booking::class] as $itemtype) {
        $DB->delete('glpi_displaypreferences', ['itemtype' => $itemtype]);
    }

    // Remove as tabelas.
    foreach ([Booking::getTable(), Car::getTable(), Driver::getTable(), MaintenancePlan::getTable(), Maintenance::getTable(), 'glpi_plugin_reservafrota_sheetcfg', 'glpi_plugin_reservafrota_sheetlog'] as $table) {
        if ($DB->tableExists($table)) {
            $DB->doQuery("DROP TABLE `$table`");
        }
    }

    // Remove a ação automática do Sheets.
    try {
        if (class_exists('CronTask') && method_exists('CronTask', 'unregister')) {
            \CronTask::unregister('GlpiPlugin\\Reservafrota\\SheetSync');
        } else {
            $DB->delete('glpi_crontasks', ['itemtype' => 'GlpiPlugin\\Reservafrota\\SheetSync']);
        }
    } catch (\Throwable $e) {}

    return true;
}
