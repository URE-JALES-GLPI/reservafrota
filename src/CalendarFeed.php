<?php

namespace GlpiPlugin\Reservafrota;

/**
 * Feed ICS ("Assinar calendário") + link "Adicionar ao Google Agenda".
 *
 * Cada usuário tem um token persistido em `glpi_plugin_reservafrota_feeds`.
 * O feed (`front/ical.php?token=...`) é público (firewall NO_CHECK) e o
 * token é a credencial — quem tem o link enxerga as reservas do dono.
 */
class CalendarFeed
{
    public const FEED_TABLE = 'glpi_plugin_reservafrota_feeds';

    /**
     * Retorna o token do usuário, criando um novo se ainda não existir.
     */
    public static function getOrCreateToken(int $users_id): string
    {
        global $DB;

        if ($users_id <= 0) {
            return '';
        }
        if (!$DB->tableExists(self::FEED_TABLE)) {
            return '';
        }
        $row = $DB->request([
            'SELECT' => ['token'],
            'FROM'   => self::FEED_TABLE,
            'WHERE'  => ['users_id' => $users_id],
        ])->current();
        if (is_array($row) && ($row['token'] ?? '') !== '') {
            return (string)$row['token'];
        }
        try {
            $token = bin2hex(random_bytes(24));
        } catch (\Throwable $e) {
            $token = sha1(uniqid((string)$users_id . microtime(true), true));
        }
        $DB->insert(self::FEED_TABLE, [
            'users_id'      => $users_id,
            'token'         => $token,
            'date_creation' => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
        ]);
        return $token;
    }

    /**
     * Resolve um token para o users_id dono. Retorna 0 se inválido.
     */
    public static function findUserByToken(string $token): int
    {
        global $DB;

        if ($token === '' || !$DB->tableExists(self::FEED_TABLE)) {
            return 0;
        }
        $row = $DB->request([
            'SELECT' => ['users_id'],
            'FROM'   => self::FEED_TABLE,
            'WHERE'  => ['token' => $token],
        ])->current();
        return (int)($row['users_id'] ?? 0);
    }

    /**
     * Invalida o token atual do usuário (o próximo acesso gera um novo).
     */
    public static function revokeToken(int $users_id): void
    {
        global $DB;

        if ($users_id > 0 && $DB->tableExists(self::FEED_TABLE)) {
            $DB->delete(self::FEED_TABLE, ['users_id' => $users_id]);
        }
    }

    /**
     * Eventos do usuário no formato canônico para ICS / Google Agenda.
     * Query própria (não reaproveita getHistory, que usa a sessão ativa):
     * gestor logado vê tudo; acesso via token (anônimo) vê só as próprias.
     *
     * @return array[] cada item: id, code, start, end, summary, location, description, status
     */
    public static function getEventsForUser(
        int $users_id,
        int $pastDays = 30,
        int $futureDays = 180,
        int $limit = 500
    ): array {
        global $DB;

        if ($users_id <= 0) {
            return [];
        }
        try {
            $bkTable = Booking::getTable();
            $hasDriver = $DB->tableExists(Driver::getTable())
                && $DB->tableExists($bkTable)
                && $DB->fieldExists($bkTable, 'plugin_reservafrota_drivers_id');
            $hasCode = $DB->tableExists($bkTable)
                && $DB->fieldExists($bkTable, 'request_code');
        } catch (\Throwable $e) {
            return [];
        }
        $select = [
            'b.id', 'b.date_departure', 'b.date_arrival', 'b.status',
            'b.driver', 'b.destination',
            'c.name AS car',
            'u.name AS user_login', 'u.realname AS realname', 'u.firstname AS firstname',
            'g.name AS sector',
        ];
        $joins = [
            Car::getTable() . ' AS c' => ['ON' => ['b' => 'plugin_reservafrota_cars_id', 'c' => 'id']],
            'glpi_users AS u'         => ['ON' => ['b' => 'users_id', 'u' => 'id']],
            'glpi_groups AS g'        => ['ON' => ['b' => 'groups_id', 'g' => 'id']],
        ];
        if ($hasDriver) {
            $select[] = 'd.name AS driver_name';
            $joins[Driver::getTable() . ' AS d'] = ['ON' => ['b' => 'plugin_reservafrota_drivers_id', 'd' => 'id']];
        }
        if ($hasCode) {
            $select[] = 'b.request_code AS request_code';
        }
        $where = ['b.is_deleted' => 0];
        try {
            $managerView = ((int)\Session::getLoginUserID() === $users_id) && Booking::canApprove();
        } catch (\Throwable $e) {
            $managerView = false;
        }
        if (!$managerView) {
            $where['b.users_id'] = $users_id;
        }
        try {
            $iterator = $DB->request([
                'SELECT'    => $select,
                'FROM'      => $bkTable . ' AS b',
                'LEFT JOIN' => $joins,
                'WHERE'     => $where,
                'ORDER'     => 'b.date_departure DESC',
            ]);
        } catch (\Throwable $e) {
            return [];
        }
        $now   = time();
        $from  = $now - max(0, $pastDays) * 86400;
        $to    = $now + max(1, $futureDays) * 86400;
        $events = [];
        foreach ($iterator as $r) {
            if (!is_array($r)) {
                continue;
            }
            $start = strtotime((string)($r['date_departure'] ?? ''));
            if ($start === false || $start < $from || $start > $to) {
                continue;
            }
            $end = strtotime((string)($r['date_arrival'] ?? ''));
            if ($end === false || $end <= $start) {
                $end = $start + 3600;
            }
            $status = (int)($r['status'] ?? 1);
            $code   = trim((string)($r['request_code'] ?? ''));
            if ($code === '') {
                $code = (string)(int)($r['id'] ?? 0);
            }
            $car = trim((string)($r['car'] ?? ''));
            if ($car === '') {
                $car = __('A designar', 'reservafrota');
            }
            $desc = [];
            $req = trim(trim((string)($r['firstname'] ?? '')) . ' ' . trim((string)($r['realname'] ?? '')));
            if ($req === '') {
                $req = trim((string)($r['user_login'] ?? ''));
            }
            if ($req !== '') {
                $desc[] = __('Solicitante', 'reservafrota') . ': ' . $req;
            }
            $drv = trim((string)($r['driver_name'] ?? $r['driver'] ?? ''));
            if ($drv !== '') {
                $desc[] = __('Motorista', 'reservafrota') . ': ' . $drv;
            }
            $desc[] = __('Status', 'reservafrota') . ': ' . self::statusLabel($status);
            $desc[] = __('Código', 'reservafrota') . ': ' . $code;

            global $CFG_GLPI;
            $base = rtrim((string)($CFG_GLPI['url_base'] ?? ''), '/');
            $webdir = '';
            if (function_exists('plugin_reservafrota_webdir')) {
                $webdir = (string)plugin_reservafrota_webdir();
            }
            if ($base !== '' && $webdir !== '') {
                $desc[] = $base . $webdir . '/front/booking.form.php?id=' . (int)($r['id'] ?? 0);
            }

            $events[] = [
                'id'          => (int)($r['id'] ?? 0),
                'code'        => $code,
                'start'       => $start,
                'end'         => $end,
                'summary'     => sprintf(__('Reserva #%s — %s', 'reservafrota'), $code, $car),
                'location'    => (string)($r['destination'] ?? ''),
                'description' => implode("\n", $desc),
                'status'      => $status,
            ];
            if (count($events) >= $limit) {
                break;
            }
        }
        return $events;
    }

    public static function statusLabel(int $status): string
    {
        switch ($status) {
            case 2: return __('Aprovada', 'reservafrota');
            case 3: return __('Concluída', 'reservafrota');
            case 4: return __('Recusada', 'reservafrota');
            case 5: return __('Cancelada', 'reservafrota');
            default: return __('Pendente', 'reservafrota');
        }
    }

    /**
     * Monta o conteúdo ICS (RFC 5545) a partir dos eventos canônicos.
     */
    public static function buildIcs(array $events, string $calName = ''): string
    {
        if ($calName === '') {
            $calName = __('Reserva de Frota', 'reservafrota');
        }
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//GLPI//ReservaFrota//PT-BR',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:' . self::icsEscape($calName),
        ];
        foreach ($events as $e) {
            if (!is_array($e) || (int)($e['start'] ?? 0) <= 0) {
                continue;
            }
            $status = (int)($e['status'] ?? 1);
            $icsStatus = ($status === 4 || $status === 5) ? 'CANCELLED'
                : (($status === 2 || $status === 3) ? 'CONFIRMED' : 'TENTATIVE');
            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:reservafrota-booking-' . (int)($e['id'] ?? 0) . '@glpi';
            $lines[] = 'DTSTAMP:' . gmdate('Ymd\THis\Z');
            $lines[] = 'DTSTART:' . self::fmtLocal((int)$e['start']);
            $lines[] = 'DTEND:' . self::fmtLocal((int)($e['end'] ?? 0) > 0 ? (int)$e['end'] : (int)$e['start'] + 3600);
            $lines[] = 'STATUS:' . $icsStatus;
            $lines[] = 'SUMMARY:' . self::icsEscape((string)($e['summary'] ?? ''));
            if (trim((string)($e['location'] ?? '')) !== '') {
                $lines[] = 'LOCATION:' . self::icsEscape((string)$e['location']);
            }
            if (trim((string)($e['description'] ?? '')) !== '') {
                $lines[] = 'DESCRIPTION:' . self::icsEscape((string)$e['description']);
            }
            $lines[] = 'END:VEVENT';
        }
        $lines[] = 'END:VCALENDAR';
        $out = '';
        foreach ($lines as $line) {
            $out .= self::icsFold($line) . "\r\n";
        }
        return $out;
    }

    private static function fmtLocal(int $ts): string
    {
        return date('Ymd\THis', $ts);
    }

    private static function icsEscape(string $s): string
    {
        $s = str_replace(["\r\n", "\r"], "\n", $s);
        $s = str_replace('\\', '\\\\', $s);
        $s = str_replace(';', '\\;', $s);
        $s = str_replace(',', '\\,', $s);
        $s = str_replace("\n", '\\n', $s);
        return $s;
    }

    private static function icsFold(string $line): string
    {
        // RFC 5545: linhas físicas de até 75 octetos; continuação com espaço.
        $out = '';
        $first = true;
        $rest = $line;
        while ($rest !== '') {
            $take = $first ? 75 : 74;
            $chunk = mb_strcut($rest, 0, $take, 'UTF-8');
            if ($chunk === '') {
                break;
            }
            $out .= ($first ? '' : "\r\n ") . $chunk;
            $first = false;
            $rest = mb_substr($rest, mb_strlen($chunk, 'UTF-8'), null, 'UTF-8');
        }
        return $out === '' ? $line : $out;
    }
}
