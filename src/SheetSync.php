<?php

namespace GlpiPlugin\Reservafrota;

use CommonDBTM;
use Session;

/**
 * Integração com Google Sheets (Forms de saída/chegada).
 *
 * Fluxo: o motorista preenche o Forms (código da viagem + saída/chegada +
 * KM + motorista). As respostas caem no Sheets. O GLPI lê as linhas novas
 * via Sheets API (polling por cron ou botão "Sincronizar agora") e aplica
 * cada linha como evento na reserva correspondente.
 *
 * A chave da ligação é o código numérico da solicitação (= id da reserva
 * base; repetições da semana compartilham o código — ver Booking).
 */
class SheetSync extends CommonDBTM
{
    public static $rightname = 'reservafrota::booking';

    public const CFG_TABLE = 'glpi_plugin_reservafrota_sheetcfg';
    public const LOG_TABLE = 'glpi_plugin_reservafrota_sheetlog';

    public const EVENT_DEPARTURE = 'departure';
    public const EVENT_ARRIVAL   = 'arrival';

    public static function getTypeName($nb = 0)
    {
        return __('Integração Sheets', 'reservafrota');
    }

    // ================= Config =================

    /**
     * Lê a configuração (linha única id=1). Devolve sempre um array com
     * chaves garantidas, mesmo sem nada salvo.
     *
     * @return array{spreadsheet_id:string,api_key:string,sheet_range:string,last_row:int,last_sync:?string,enabled:int}
     */
    public static function getConfig(): array
    {
        $defaults = [
            'spreadsheet_id' => '',
            'api_key'        => '',
            'sheet_range'    => 'Respostas!A2:F',
            'last_row'       => 1,
            'last_sync'      => null,
            'enabled'        => 0,
        ];
        try {
            /** @var \DBmysql $DB */
            global $DB;
            if (!$DB->tableExists(self::CFG_TABLE)) {
                return $defaults;
            }
            $row = $DB->request([
                'FROM'  => self::CFG_TABLE,
                'WHERE' => ['id' => 1],
                'LIMIT' => 1,
            ])->current();
            if (!is_array($row)) {
                return $defaults;
            }
            return [
                'spreadsheet_id' => (string) ($row['spreadsheet_id'] ?? ''),
                'api_key'        => (string) ($row['api_key'] ?? ''),
                'sheet_range'    => (string) ($row['sheet_range'] ?? '') ?: 'Respostas!A2:F',
                'last_row'       => (int) ($row['last_row'] ?? 1),
                'last_sync'      => $row['last_sync'] ?? null,
                'enabled'        => (int) ($row['enabled'] ?? 0),
            ];
        } catch (\Throwable $e) {
            return $defaults;
        }
    }

    /**
     * Salva a configuração (cria a linha id=1 se não existir).
     */
    public static function setConfig(string $spreadsheetId, string $apiKey, string $range, int $enabled): bool
    {
        try {
            /** @var \DBmysql $DB */
            global $DB;
            if (!$DB->tableExists(self::CFG_TABLE)) {
                return false;
            }
            $data = [
                'spreadsheet_id' => substr(trim($spreadsheetId), 0, 128),
                'api_key'        => trim($apiKey),
                'sheet_range'    => substr(trim($range) ?: 'Respostas!A2:F', 0, 64),
                'enabled'        => $enabled ? 1 : 0,
            ];
            $exists = $DB->request([
                'SELECT' => ['id'],
                'FROM'   => self::CFG_TABLE,
                'WHERE'  => ['id' => 1],
                'LIMIT'  => 1,
            ])->current();
            if (is_array($exists)) {
                return (bool) $DB->update(self::CFG_TABLE, $data, ['id' => 1]);
            }
            $data['id'] = 1;
            $data['last_row'] = 1;
            return (bool) $DB->insert(self::CFG_TABLE, $data);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Atualiza o cursor da sincronização (última linha lida + data).
     */
    public static function setCursor(int $lastRow): void
    {
        try {
            /** @var \DBmysql $DB */
            global $DB;
            if (!$DB->tableExists(self::CFG_TABLE)) {
                return;
            }
            $now = date('Y-m-d H:i:s');
            $exists = $DB->request([
                'SELECT' => ['id'],
                'FROM'   => self::CFG_TABLE,
                'WHERE'  => ['id' => 1],
                'LIMIT'  => 1,
            ])->current();
            if (is_array($exists)) {
                $DB->update(self::CFG_TABLE, ['last_row' => $lastRow, 'last_sync' => $now], ['id' => 1]);
            } else {
                $DB->insert(self::CFG_TABLE, ['id' => 1, 'last_row' => $lastRow, 'last_sync' => $now]);
            }
        } catch (\Throwable $e) {}
    }

    // ================= Normalização =================

    /**
     * Normaliza texto para comparação (minúscula, sem acento, espaços únicos).
     */
    public static function norm(string $s): string
    {
        $s = trim(mb_strtolower($s, 'UTF-8'));
        $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        if (is_string($t) && $t !== '') {
            $s = $t;
        }
        $s = preg_replace('/[^a-z0-9 ]+/', ' ', $s) ?? '';
        $s = preg_replace('/\s+/', ' ', $s) ?? '';
        return trim($s);
    }

    /**
     * Normaliza o código digitado no Forms (aceita "#12", "12", " 12 ").
     * Devolve '' se inválido (códigos são numéricos = id da base).
     */
    public static function normCode(string $raw): string
    {
        $raw = trim((string) $raw);
        $raw = trim(ltrim($raw, "# \t"));
        $raw = preg_replace('/\s+/', '', $raw) ?? '';
        if (!preg_match('/^\d+$/', $raw)) {
            return '';
        }
        $raw = ltrim($raw, '0');
        return $raw === '' ? '0' : $raw;
    }

    /**
     * Normaliza o tipo de evento (Saída/Chegada e variações).
     * Devolve 'departure', 'arrival' ou ''.
     */
    public static function normEvent(string $raw): string
    {
        $n = self::norm($raw);
        if ($n === '') {
            return '';
        }
        if (str_contains($n, 'cheg') || str_contains($n, 'arriv') || str_contains($n, 'volta') || str_contains($n, 'retorno')) {
            return self::EVENT_ARRIVAL;
        }
        if (str_contains($n, 'sai') || str_contains($n, 'saida') || str_contains($n, 'depart') || $n === 'ida') {
            return self::EVENT_DEPARTURE;
        }
        return '';
    }

    /**
     * Converte o carimbo do Forms (pt-BR "dd/mm/aaaa hh:mm:ss") para
     * datetime do banco. Cai para "agora" se ilegível.
     */
    public static function parseSheetDatetime(string $raw): string
    {
        $raw = trim($raw);
        if ($raw !== '') {
            foreach (['d/m/Y H:i:s', 'd/m/Y H:i', 'd-m-Y H:i:s', 'd-m-Y H:i'] as $fmt) {
                $dt = \DateTime::createFromFormat($fmt, $raw);
                if ($dt instanceof \DateTime) {
                    return $dt->format('Y-m-d H:i:s');
                }
            }
            $ts = strtotime(str_replace('/', '-', $raw));
            if ($ts !== false) {
                return date('Y-m-d H:i:s', $ts);
            }
        }
        return date('Y-m-d H:i:s');
    }

    // ================= Leitura do Sheets =================

    /**
     * Faz GET HTTPS com timeout (curl preferido, file_get_contents de fallback).
     *
     * @return array{ok:bool,body:string,error:string}
     */
    private static function httpsGet(string $url): array
    {
        if (function_exists('curl_init')) {
            try {
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => 20,
                    CURLOPT_CONNECTTIMEOUT => 10,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_MAXREDIRS      => 3,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
                    CURLOPT_USERAGENT      => 'GLPI-Reservafrota-SheetSync/1.0',
                ]);
                $body = curl_exec($ch);
                $err  = curl_error($ch);
                $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                if ($body === false) {
                    return ['ok' => false, 'body' => '', 'error' => $err !== '' ? $err : 'curl failed'];
                }
                if ($http < 200 || $http >= 300) {
                    return ['ok' => false, 'body' => (string) $body, 'error' => 'HTTP ' . $http];
                }
                return ['ok' => true, 'body' => (string) $body, 'error' => ''];
            } catch (\Throwable $e) {
                return ['ok' => false, 'body' => '', 'error' => $e->getMessage()];
            }
        }
        try {
            $ctx = stream_context_create(['http' => ['timeout' => 20, 'user_agent' => 'GLPI-Reservafrota-SheetSync/1.0']]);
            $body = @file_get_contents($url, false, $ctx);
            if ($body === false) {
                return ['ok' => false, 'body' => '', 'error' => 'HTTP fetch failed'];
            }
            return ['ok' => true, 'body' => (string) $body, 'error' => ''];
        } catch (\Throwable $e) {
            return ['ok' => false, 'body' => '', 'error' => $e->getMessage()];
        }
    }

    /**
     * Extrai a linha inicial (1-based) de um range tipo "Respostas!A2:F".
     */
    private static function rangeStartRow(string $range): int
    {
        if (preg_match('/[A-Za-z]+(\d+)/', $range, $m)) {
            return max(1, (int) $m[1]);
        }
        return 1;
    }

    /**
     * Mapeia o cabeçalho para índices de coluna.
     *
     * @param list<string> $header
     * @return array{code:int,event:int,km:int,driver:int,obs:int,ts:int}
     */
    private static function mapHeader(array $header): array
    {
        $map = ['code' => -1, 'event' => -1, 'km' => -1, 'driver' => -1, 'obs' => -1, 'ts' => 0];
        foreach ($header as $i => $col) {
            $n = self::norm((string) $col);
            if ($n === '') {
                continue;
            }
            if ($map['ts'] === 0 && ($n === 'carimbo de data hora' || $n === 'carimbo' || $n === 'timestamp' || $n === 'data hora' || $n === 'enviado em')) {
                $map['ts'] = $i;
                continue;
            }
            if ($map['code'] < 0 && ($n === 'codigo da viagem' || $n === 'codigo' || $n === 'cod' || $n === 'id' || $n === 'viagem')) {
                $map['code'] = $i;
                continue;
            }
            if ($map['event'] < 0 && ($n === 'tipo de registro' || $n === 'tipo' || $n === 'evento' || $n === 'event' || $n === 'tipo de evento')) {
                $map['event'] = $i;
                continue;
            }
            if ($map['driver'] < 0 && ($n === 'motorista' || $n === 'driver' || $n === 'condutor' || $n === 'nome do motorista')) {
                $map['driver'] = $i;
                continue;
            }
            if ($map['km'] < 0 && ($n === 'km do hodometro' || $n === 'km' || $n === 'hodometro' || $n === 'quilometragem' || $n === 'km atual')) {
                $map['km'] = $i;
                continue;
            }
            if ($map['obs'] < 0 && ($n === 'observacao' || $n === 'obs' || $n === 'comentario' || $n === 'observacoes')) {
                $map['obs'] = $i;
            }
        }
        return $map;
    }

    /**
     * Lê as linhas novas da planilha via Sheets API v4.
     *
     * @return array{ok:bool,message:string,rows:list<array{row:int,ts:string,code:string,event:string,km:int,driver:string,obs:string}>,last_row:int}
     */
    public static function fetchRows(array $cfg, int $maxRows = 200): array
    {
        $spreadsheet = trim((string) ($cfg['spreadsheet_id'] ?? ''));
        $key = trim((string) ($cfg['api_key'] ?? ''));
        $range = trim((string) ($cfg['sheet_range'] ?? '')) ?: 'Respostas!A2:F';
        if ($spreadsheet === '' || $key === '') {
            return ['ok' => false, 'message' => __('Planilha não configurada (ID e chave de API).', 'reservafrota'), 'rows' => [], 'last_row' => (int) ($cfg['last_row'] ?? 1)];
        }
        $url = 'https://sheets.googleapis.com/v4/spreadsheets/' . rawurlencode($spreadsheet)
            . '/values/' . rawurlencode($range) . '?key=' . rawurlencode($key);
        $res = self::httpsGet($url);
        if (!$res['ok']) {
            return ['ok' => false, 'message' => sprintf(__('Falha ao ler a planilha: %s', 'reservafrota'), $res['error']), 'rows' => [], 'last_row' => (int) ($cfg['last_row'] ?? 1)];
        }
        try {
            $json = json_decode($res['body'], true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => __('Resposta inválida da API do Google.', 'reservafrota'), 'rows' => [], 'last_row' => (int) ($cfg['last_row'] ?? 1)];
        }
        if (isset($json['error'])) {
            $msg = (string) ($json['error']['message'] ?? 'API error');
            return ['ok' => false, 'message' => sprintf(__('Erro da API do Google: %s', 'reservafrota'), $msg), 'rows' => [], 'last_row' => (int) ($cfg['last_row'] ?? 1)];
        }
        $values = $json['values'] ?? [];
        if (!is_array($values) || count($values) === 0) {
            return ['ok' => true, 'message' => '', 'rows' => [], 'last_row' => (int) ($cfg['last_row'] ?? 1)];
        }
        // Primeira linha pode ser cabeçalho (texto) ou dado. Detecta: se a
        // coluna do código não é numérica, trata como cabeçalho.
        $startRow = self::rangeStartRow($range);
        $header = array_map('strval', (array) $values[0]);
        $map = self::mapHeader($header);
        // Sem nenhum cabeçalho reconhecido, a primeira linha já é dado
        // (caso padrão: cabeçalho na linha 1, range começando em A2).
        // Usa a ordem documentada em vez do mapa vazio.
        if ($map['code'] < 0 && $map['event'] < 0 && $map['km'] < 0 && $map['driver'] < 0) {
            $map = ['code' => 1, 'event' => 2, 'km' => 4, 'driver' => 3, 'obs' => 5, 'ts' => 0];
        }
        $dataStart = 0;
        if ($map['code'] >= 0) {
            $probe = trim((string) ($header[$map['code']] ?? ''));
            if ($probe !== '' && self::normCode($probe) === '') {
                $dataStart = 1; // cabeçalho confirmado
            }
        } elseif ($map['event'] >= 0) {
            $probe = trim((string) ($header[$map['event']] ?? ''));
            if ($probe !== '' && self::normEvent($probe) === '' && self::norm($probe) !== '') {
                // Pode ser cabeçalho ("Tipo de registro") ou valor inválido; assume cabeçalho só se parecer rótulo
                if (str_contains(self::norm($probe), 'tipo') || str_contains(self::norm($probe), 'registro')) {
                    $dataStart = 1;
                }
            }
        }
        $lastRow = (int) ($cfg['last_row'] ?? 1);
        $rows = [];
        $count = count($values);
        for ($i = $dataStart; $i < $count && count($rows) < $maxRows; $i++) {
            $absRow = $startRow + $i;
            if ($absRow <= $lastRow) {
                continue; // já processada
            }
            $cells = array_map('strval', (array) $values[$i]);
            $cell = static function (int $idx) use ($cells): string {
                return trim((string) ($cells[$idx] ?? ''));
            };
            // Linha totalmente vazia: avança o cursor mas ignora
            $nonEmpty = false;
            foreach ($cells as $c) {
                if (trim((string) $c) !== '') {
                    $nonEmpty = true;
                    break;
                }
            }
            if (!$nonEmpty) {
                $lastRow = max($lastRow, $absRow);
                continue;
            }
            $kmRaw = $map['km'] >= 0 ? $cell($map['km']) : '';
            $kmDigits = preg_replace('/\D/', '', $kmRaw) ?? '';
            $rows[] = [
                'row'    => $absRow,
                'ts'     => $map['ts'] >= 0 ? $cell($map['ts']) : '',
                'code'   => $map['code'] >= 0 ? $cell($map['code']) : '',
                'event'  => $map['event'] >= 0 ? $cell($map['event']) : '',
                'km'     => $kmDigits === '' ? 0 : (int) $kmDigits,
                'driver' => $map['driver'] >= 0 ? $cell($map['driver']) : '',
                'obs'    => $map['obs'] >= 0 ? $cell($map['obs']) : '',
            ];
            $lastRow = max($lastRow, $absRow);
        }
        return ['ok' => true, 'message' => '', 'rows' => $rows, 'last_row' => $lastRow];
    }

    // ================= Aplicador =================

    /**
     * Procura o motorista pelo nome (normalizado, só ativos).
     */
    private static function findDriverId(string $name): int
    {
        $want = self::norm($name);
        if ($want === '') {
            return 0;
        }
        try {
            foreach (Driver::getActiveDrivers() as $id => $d) {
                if (self::norm((string) ($d['name'] ?? '')) === $want) {
                    return (int) $id;
                }
            }
        } catch (\Throwable $e) {}
        return 0;
    }

    /**
     * Localiza a reserva pelo código, desempatando repetições da semana
     * pela janela de data do evento (±1 dia da viagem).
     *
     * @return array{ok:bool,booking?:Booking,message?:string}
     */
    private static function findBooking(string $code, string $eventDate): array
    {
        try {
            /** @var \DBmysql $DB */
            global $DB;
            $it = $DB->request([
                'SELECT' => ['id', 'date_departure', 'date_arrival', 'status', 'is_deleted'],
                'FROM'   => Booking::getTable(),
                'WHERE'  => ['request_code' => $code, 'is_deleted' => 0],
                'ORDER'  => 'date_departure ASC',
            ]);
            $cands = [];
            foreach ($it as $r) {
                $cands[] = $r;
            }
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => __('Erro ao buscar a reserva.', 'reservafrota')];
        }
        if (count($cands) === 0) {
            return ['ok' => false, 'message' => sprintf(__('Código %s não encontrado.', 'reservafrota'), '#' . $code)];
        }
        $inWindow = [];
        $outWindow = [];
        foreach ($cands as $r) {
            $dep = substr((string) ($r['date_departure'] ?? ''), 0, 10);
            $arr = substr((string) ($r['date_arrival'] ?? ''), 0, 10) ?: $dep;
            $d0 = date('Y-m-d', strtotime($dep . ' -1 day'));
            $d1 = date('Y-m-d', strtotime($arr . ' +1 day'));
            if ($eventDate >= $d0 && $eventDate <= $d1) {
                $inWindow[] = $r;
            } else {
                $outWindow[] = $r;
            }
        }
        if (count($inWindow) === 1) {
            $b = new Booking();
            if ($b->getFromDB((int) $inWindow[0]['id'])) {
                return ['ok' => true, 'booking' => $b];
            }
            return ['ok' => false, 'message' => __('Reserva não encontrada.', 'reservafrota')];
        }
        if (count($inWindow) > 1) {
            $ids = implode(', ', array_map(static function ($r) { return '#' . $r['id']; }, $inWindow));
            return ['ok' => false, 'message' => sprintf(__('Código ambíguo na data (reservas %s). Peça ao gestor.', 'reservafrota'), $ids)];
        }
        // Existe, mas fora da janela do evento — provavelmente código errado.
        $dd = substr((string) ($outWindow[0]['date_departure'] ?? ''), 0, 10);
        return ['ok' => false, 'message' => sprintf(__('Código #%s é de outra data (%s). Confira o código.', 'reservafrota'), $code, $dd)];
    }

    /**
     * Registra uma linha no log de sincronização.
     */
    public static function logRow(int $rowNum, string $receivedAt, string $code, int $bookingsId, string $event, int $km, string $driver, string $obs, string $status, string $message, string $source): void
    {
        try {
            /** @var \DBmysql $DB */
            global $DB;
            if (!$DB->tableExists(self::LOG_TABLE)) {
                return;
            }
            $DB->insert(self::LOG_TABLE, [
                'row_num'      => $rowNum,
                'received_at'  => $receivedAt ?: null,
                'request_code' => substr($code, 0, 32),
                'bookings_id'  => $bookingsId,
                'event'        => substr($event, 0, 16),
                'km'           => $km,
                'driver_name'  => substr($driver, 0, 255),
                'obs'          => ($obs !== '' && $DB->fieldExists(self::LOG_TABLE, 'obs')) ? $obs : null,
                'status'       => substr($status, 0, 16),
                'message'      => $message !== '' ? $message : null,
                'source'       => substr($source, 0, 16),
                'date_creation' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {}
    }

    /**
     * Aplica um evento de saída/chegada numa reserva.
     * Roda em contexto de sistema (cron/botão): usa UPDATE direto, sem
     * checagem de perfil — as travas de negócio estão todas aqui dentro.
     *
     * @return array{ok:bool,message:string,bookings_id:int}
     */
    public static function applyEvent(string $codeRaw, string $eventRaw, int $km, string $driverRaw, ?string $whenRaw, string $obs = '', string $source = 'sheet', bool $force = false): array
    {
        $fail = static function (string $msg, int $bid = 0) {
            return ['ok' => false, 'message' => $msg, 'bookings_id' => $bid];
        };
        $code = self::normCode($codeRaw);
        if ($code === '') {
            return $fail(__('Código da viagem inválido (use o número).', 'reservafrota'));
        }
        $event = self::normEvent($eventRaw);
        if ($event === '') {
            return $fail(__('Tipo inválido (use Saída ou Chegada).', 'reservafrota'));
        }
        if ($km <= 0) {
            return $fail(__('KM do hodômetro inválido.', 'reservafrota'));
        }
        $driverName = trim((string) $driverRaw);
        if ($driverName === '') {
            return $fail(__('Motorista não informado.', 'reservafrota'));
        }
        $when = self::parseSheetDatetime((string) ($whenRaw ?? ''));
        $eventDate = substr($when, 0, 10);

        $found = self::findBooking($code, $eventDate);
        if (!$found['ok']) {
            /** @var string $message */
            $message = (string) ($found['message'] ?? '');
            return $fail($message);
        }
        /** @var Booking $booking */
        $booking = $found['booking'];
        $bid = (int) ($booking->fields['id'] ?? 0);
        $st = (int) ($booking->fields['status'] ?? 0);

        // Só aprovada aceita evento do Forms.
        if ($st !== Booking::STATUS_APPROVED) {
            if ($st === Booking::STATUS_ARRIVED) {
                return $fail(__('Viagem já concluída (lançamento duplicado?).', 'reservafrota'), $bid);
            }
            if ($st === Booking::STATUS_PENDING) {
                return $fail(__('Reserva ainda pendente de aprovação.', 'reservafrota'), $bid);
            }
            return $fail(sprintf(__('Reserva com status %s não aceita lançamento.', 'reservafrota'), Booking::getStatusName($st)), $bid);
        }

        // Motorista: confere com o designado; adota se a reserva está sem.
        $drvId = self::findDriverId($driverName);
        if ($drvId <= 0) {
            return $fail(sprintf(__('Motorista "%s" não cadastrado no GLPI.', 'reservafrota'), $driverName), $bid);
        }
        $bookDrvId = (int) ($booking->fields['plugin_reservafrota_drivers_id'] ?? 0);
        $adopted = false;
        if ($bookDrvId <= 0) {
            $adopted = true;
        } elseif ($bookDrvId !== $drvId) {
            $drv = new Driver();
            $other = $drv->getFromDB($bookDrvId) ? (string) ($drv->fields['name'] ?? '') : '';
            return $fail(sprintf(__('Motorista divergente: a viagem é de %s.', 'reservafrota'), $other !== '' ? $other : ('#' . $bookDrvId)), $bid);
        }

        // Janela de data: o evento deve cair na data da viagem (±1 dia).
        $depDate = substr((string) ($booking->fields['date_departure'] ?? ''), 0, 10);
        $arrDate = substr((string) ($booking->fields['date_arrival'] ?? ''), 0, 10) ?: $depDate;
        $d0 = date('Y-m-d', strtotime($depDate . ' -1 day'));
        $d1 = date('Y-m-d', strtotime($arrDate . ' +1 day'));
        if ($eventDate < $d0 || $eventDate > $d1) {
            return $fail(sprintf(__('Data do evento (%s) fora da viagem (%s).', 'reservafrota'), $eventDate, $depDate), $bid);
        }

        try {
            /** @var \DBmysql $DB */
            global $DB;
            $now = date('Y-m-d H:i:s');
            if ($event === self::EVENT_DEPARTURE) {
                $curInit = $booking->fields['km_initial'] ?? null;
                if ($curInit !== null && (int) $curInit > 0 && !$force) {
                    return $fail(__('Saída já registrada (lançamento duplicado?).', 'reservafrota'), $bid);
                }
                $upd = ['km_initial' => $km];
                if ($adopted) {
                    $upd['plugin_reservafrota_drivers_id'] = $drvId;
                    $upd['driver'] = $driverName;
                }
                if (!$DB->update(Booking::getTable(), $upd, ['id' => $bid])) {
                    return $fail(__('Falha ao gravar a saída.', 'reservafrota'), $bid);
                }
                $msg = sprintf(__('Saída registrada via Forms: KM %s em %s (motorista %s).', 'reservafrota'), number_format($km, 0, ',', '.'), $when, $driverName);
                \Log::history($bid, Booking::class, [0, '', $msg], '', \Log::HISTORY_LOG_SIMPLE_MESSAGE);
                return ['ok' => true, 'message' => $adopted ? $msg . ' ' . __('Motorista adotado na reserva.', 'reservafrota') : $msg, 'bookings_id' => $bid];
            }

            // Chegada: exige saída registrada (salvo força do gestor).
            $curInit = $booking->fields['km_initial'] ?? null;
            if (($curInit === null || (int) $curInit <= 0) && !$force) {
                return $fail(__('Sem saída registrada para esta viagem.', 'reservafrota'), $bid);
            }
            if ($km < (int) ($curInit ?? 0)) {
                return $fail(sprintf(__('KM final (%s) menor que o inicial (%s).', 'reservafrota'), number_format($km, 0, ',', '.'), number_format((int) ($curInit ?? 0), 0, ',', '.')), $bid);
            }
            $upd = [
                'km_final'      => $km,
                'date_returned' => $when,
                'status'        => Booking::STATUS_ARRIVED,
            ];
            if (trim($obs) !== '') {
                $upd['arrival_obs'] = trim($obs);
            }
            if ($adopted) {
                $upd['plugin_reservafrota_drivers_id'] = $drvId;
                $upd['driver'] = $driverName;
            }
            if (!$DB->update(Booking::getTable(), $upd, ['id' => $bid])) {
                return $fail(__('Falha ao gravar a chegada.', 'reservafrota'), $bid);
            }
            $carId = (int) ($booking->fields['plugin_reservafrota_cars_id'] ?? 0);
            $carNote = '';
            if ($carId > 0) {
                try {
                    if (!Car::updateKm($carId, $km, 'booking')) {
                        $carNote = ' ' . __('(KM do carro mantido.)', 'reservafrota');
                    }
                } catch (\Throwable $e) {
                    $carNote = ' ' . __('(KM do carro mantido.)', 'reservafrota');
                }
            }
            $msg = sprintf(__('Chegada registrada via Forms: KM %s em %s (motorista %s). Viagem concluída.', 'reservafrota'), number_format($km, 0, ',', '.'), $when, $driverName);
            \Log::history($bid, Booking::class, [0, '', $msg], '', \Log::HISTORY_LOG_SIMPLE_MESSAGE);
            if (trim($obs) !== '' && $carId > 0) {
                \Log::history($carId, Car::class, [0, '', sprintf(__('Observação de viagem (agendamento #%d): %s', 'reservafrota'), $bid, trim($obs))], '', \Log::HISTORY_LOG_SIMPLE_MESSAGE);
            }
            return ['ok' => true, 'message' => $msg . $carNote, 'bookings_id' => $bid];
        } catch (\Throwable $e) {
            return $fail(__('Erro interno ao aplicar o evento.', 'reservafrota'), $bid);
        }
    }

    // ================= Sincronização =================

    /**
     * Roda uma sincronização completa: lê as linhas novas e aplica.
     *
     * @return array{ok:bool,message:string,applied:int,errors:int,total:int}
     */
    public static function runSync(bool $manual = false, int $maxRows = 200): array
    {
        $cfg = self::getConfig();
        if (trim((string) ($cfg['spreadsheet_id'] ?? '')) === '' || trim((string) ($cfg['api_key'] ?? '')) === '') {
            return ['ok' => false, 'message' => __('Integração não configurada.', 'reservafrota'), 'applied' => 0, 'errors' => 0, 'total' => 0];
        }
        if (!(int) ($cfg['enabled'] ?? 0) && !$manual) {
            return ['ok' => false, 'message' => __('Integração desativada.', 'reservafrota'), 'applied' => 0, 'errors' => 0, 'total' => 0];
        }
        $fetch = self::fetchRows($cfg, $maxRows);
        if (!$fetch['ok']) {
            return ['ok' => false, 'message' => (string) $fetch['message'], 'applied' => 0, 'errors' => 0, 'total' => 0];
        }
        $applied = 0;
        $errors = 0;
        $maxRow = (int) ($cfg['last_row'] ?? 1);
        foreach ($fetch['rows'] as $r) {
            $maxRow = max($maxRow, (int) $r['row']);
            $res = self::applyEvent(
                (string) $r['code'],
                (string) $r['event'],
                (int) $r['km'],
                (string) $r['driver'],
                (string) $r['ts'] !== '' ? (string) $r['ts'] : null,
                (string) $r['obs'],
                'sheet',
                false
            );
            self::logRow(
                (int) $r['row'],
                ($r['ts'] !== '' ? self::parseSheetDatetime((string) $r['ts']) : date('Y-m-d H:i:s')),
                (string) $r['code'],
                (int) ($res['bookings_id'] ?? 0),
                (string) $r['event'],
                (int) $r['km'],
                (string) $r['driver'],
                (string) $r['obs'],
                !empty($res['ok']) ? 'applied' : 'error',
                (string) ($res['message'] ?? ''),
                'sheet'
            );
            if (!empty($res['ok'])) {
                $applied++;
            } else {
                $errors++;
            }
        }
        self::setCursor($maxRow);
        $total = $applied + $errors;
        if ($total === 0) {
            return ['ok' => true, 'message' => __('Nenhuma linha nova.', 'reservafrota'), 'applied' => 0, 'errors' => 0, 'total' => 0];
        }
        return [
            'ok' => $errors === 0,
            'message' => sprintf(__('%d aplicado(s), %d com erro.', 'reservafrota'), $applied, $errors),
            'applied' => $applied,
            'errors'  => $errors,
            'total'   => $total,
        ];
    }

    /**
     * Entrada do cron do GLPI (Configurar > Ações automáticas).
     */
    public static function cronSheetsync(\CronTask $task): int
    {
        try {
            $res = self::runSync(false);
            if (!empty($res['total'])) {
                $task->log(sprintf('SheetSync: %s', (string) ($res['message'] ?? '')));
                $task->addVolume((int) $res['total']);
            }
            return !empty($res['ok']) || (int) ($res['total'] ?? 0) === 0 ? 1 : 0;
        } catch (\Throwable $e) {
            $task->log('SheetSync: ' . $e->getMessage());
            return 0;
        }
    }

    // ================= Auditoria =================

    /**
     * Últimas linhas do log de sincronização (mais recentes primeiro).
     *
     * @return list<array<string,mixed>>
     */
    public static function getLogs(int $limit = 100, string $status = ''): array
    {
        $out = [];
        try {
            /** @var \DBmysql $DB */
            global $DB;
            if (!$DB->tableExists(self::LOG_TABLE)) {
                return [];
            }
            $where = [];
            if ($status !== '') {
                $where['status'] = $status;
            }
            $it = $DB->request([
                'FROM'  => self::LOG_TABLE,
                'WHERE' => $where,
                'ORDER' => 'id DESC',
                'LIMIT' => max(1, min(500, $limit)),
            ]);
            foreach ($it as $row) {
                $out[] = $row;
            }
        } catch (\Throwable $e) {}
        return $out;
    }

    /**
     * Reprocessa uma linha do log (força a aplicação — ação do gestor).
     *
     * @return array{ok:bool,message:string,bookings_id:int}
     */
    public static function reprocess(int $logId): array
    {
        try {
            /** @var \DBmysql $DB */
            global $DB;
            if (!$DB->tableExists(self::LOG_TABLE)) {
                return ['ok' => false, 'message' => __('Log indisponível.', 'reservafrota'), 'bookings_id' => 0];
            }
            $row = $DB->request([
                'FROM'  => self::LOG_TABLE,
                'WHERE' => ['id' => $logId],
                'LIMIT' => 1,
            ])->current();
            if (!is_array($row)) {
                return ['ok' => false, 'message' => __('Registro não encontrado.', 'reservafrota'), 'bookings_id' => 0];
            }
            $res = self::applyEvent(
                (string) ($row['request_code'] ?? ''),
                (string) ($row['event'] ?? ''),
                (int) ($row['km'] ?? 0),
                (string) ($row['driver_name'] ?? ''),
                ($row['received_at'] ?? null) ?: null,
                (string) ($row['obs'] ?? ''),
                'sheet',
                true
            );
            self::logRow(
                (int) ($row['row_num'] ?? 0),
                ($row['received_at'] ?? null) ?: date('Y-m-d H:i:s'),
                (string) ($row['request_code'] ?? ''),
                (int) ($res['bookings_id'] ?? 0),
                (string) ($row['event'] ?? ''),
                (int) ($row['km'] ?? 0),
                (string) ($row['driver_name'] ?? ''),
                (string) ($row['obs'] ?? ''),
                !empty($res['ok']) ? 'applied' : 'error',
                __('Reprocessado: ', 'reservafrota') . (string) ($res['message'] ?? ''),
                'sheet'
            );
            return $res;
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage(), 'bookings_id' => 0];
        }
    }
}
