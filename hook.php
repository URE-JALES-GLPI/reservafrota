<?php

/**
 * hook.php: instalação e desinstalação do plugin.
 * Cria as tabelas, registra os direitos de perfil e as colunas padrão de busca.
 */

use GlpiPlugin\Reservafrota\Booking;
use GlpiPlugin\Reservafrota\Car;
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
            `km_final`                   int unsigned DEFAULT NULL,
            `comment_validation`         text         DEFAULT NULL,
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
            'rights' => ALLSTANDARDRIGHT | Booking::APPROVE,
        ], [
            'profiles_id' => $current_profile,
            'name'        => 'reservafrota::booking',
        ]);
    }

    // ---- Colunas padrão exibidas na listagem ----
    $prefs = [
        Car::class => [2, 3, 4],     // placa, ano, ativo
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
    foreach ([Car::class, Booking::class] as $itemtype) {
        $DB->delete('glpi_displaypreferences', ['itemtype' => $itemtype]);
    }

    // Remove as tabelas.
    foreach ([Booking::getTable(), Car::getTable()] as $table) {
        if ($DB->tableExists($table)) {
            $DB->doQuery("DROP TABLE `$table`");
        }
    }

    return true;
}
