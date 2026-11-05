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
    public static string $rightname = 'reservafrota::booking';

    public const CFG_TABLE = 'glpi_plugin_reservafrota_sheetcfg';
    public const LOG_TABLE = 'glpi_plugin_reservafrota_sheetlog';

    public const EVENT_DEPARTURE = 'departure';
    public const EVENT_ARRIVAL   = 'arrival';

    /** Salto máximo de KM aceito sem conferência do gestor (0 desliga). */
    public const DEFAULT_MAX_KM_JUMP = 2000;

    /** Janela de viagens aprovadas enviadas ao formulário web. */
    private const PUSH_DAYS_BACK    = 30;
    private const PUSH_DAYS_FORWARD = 90;
    private const PUSH_CLOSED_DAYS_BACK = 180;

    private static bool $pushQueued = false;
    private static bool $pushShutdownRegistered = false;

    public static function getTypeName($nb = 0)
    {
        return __('Integração Sheets', 'reservafrota');
    }

    // ================= Config =================

    /**
     * Lê a configuração (linha única id=1). Devolve sempre um array com
     * chaves garantidas, mesmo sem nada salvo.
     *
     * @return array{spreadsheet_id:string,api_key:string,sheet_range:string,last_row:int,last_sync:?string,enabled:int,webapp_url:string,webapp_secret:string,max_km_jump:int,last_push:?string,last_push_error:string}
     */
    public static function getConfig(): array
    {
        $defaults = [
            'spreadsheet_id'  => '',
            'api_key'         => '',
            'sheet_range'     => 'Respostas!A2:F',
            'last_row'        => 1,
            'last_sync'       => null,
            'enabled'         => 0,
            'webapp_url'      => '',
            'webapp_secret'   => '',
            'max_km_jump'     => self::DEFAULT_MAX_KM_JUMP,
            'last_push'       => null,
            'last_push_error' => '',
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
                'webapp_url'      => (string) ($row['webapp_url'] ?? ''),
                'webapp_secret'   => (string) ($row['webapp_secret'] ?? ''),
                'max_km_jump'     => (int) ($row['max_km_jump'] ?? self::DEFAULT_MAX_KM_JUMP),
                'last_push'       => $row['last_push'] ?? null,
                'last_push_error' => (string) ($row['last_push_error'] ?? ''),
            ];
        } catch (\Throwable $e) {
            return $defaults;
        }
    }

    /**
     * Salva a configuração do formulário web (Apps Script).
     * Segredo em branco mantém o atual. Só aceita URL de script.google.com,
     * pois o segredo é enviado para esse endereço.
     *
     * @return array{ok:bool,message:string}
     */
    public static function setFormConfig(string $url, string $secret, int $maxJump): array
    {
        $url = trim($url);
        if ($url !== '') {
            $parts = parse_url($url);
            if (
                !is_array($parts)
                || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
                || strtolower((string) ($parts['host'] ?? '')) !== 'script.google.com'
                || !str_ends_with((string) ($parts['path'] ?? ''), '/exec')
            ) {
                return ['ok' => false, 'message' => __('A URL do formulário deve ser a do app da web: https://script.google.com/.../exec', 'reservafrota')];
            }
        }
        $data = [
            'webapp_url'  => substr($url, 0, 255),
            'max_km_jump' => max(0, $maxJump),
        ];
        $secret = trim($secret);
        if ($secret !== '') {
            $data['webapp_secret'] = substr($secret, 0, 255);
        }
        try {
            /** @var \DBmysql $DB */
            global $DB;
            if (!$DB->tableExists(self::CFG_TABLE) || !$DB->fieldExists(self::CFG_TABLE, 'webapp_url')) {
                return ['ok' => false, 'message' => __('Tabela de configuração desatualizada. Recarregue a página.', 'reservafrota')];
            }
            $exists = $DB->request([
                'SELECT' => ['id'],
                'FROM'   => self::CFG_TABLE,
                'WHERE'  => ['id' => 1],
                'LIMIT'  => 1,
            ])->current();
            if (is_array($exists)) {
                $ok = (bool) $DB->update(self::CFG_TABLE, $data, ['id' => 1]);
            } else {
                $data['id'] = 1;
                $data['last_row'] = 1;
                $ok = (bool) $DB->insert(self::CFG_TABLE, $data);
            }
            return ['ok' => $ok, 'message' => $ok ? '' : __('Falha ao salvar a configuração do formulário.', 'reservafrota')];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => __('Falha ao salvar a configuração do formulário.', 'reservafrota')];
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
            foreach (['d/m/Y H:i:s', 'd/m/Y H:i', 'd-m-Y H:i:s', 'd-m-Y H:i', 'd/m/Y', 'd-m-Y', 'Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d'] as $fmt) {
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
    /**
     * É coluna marcadora de chegada (ex.: "Chegada" com X)? Nesse caso,
     * linha marcada = chegada, linha vazia = saída.
     */
    private static function isArrivalMarker(string $headerNorm): bool
    {
        return $headerNorm === 'chegada';
    }

    private static function mapHeader(array $header): array
    {
        $map = ['code' => -1, 'event' => -1, 'km' => -1, 'driver' => -1, 'obs' => -1, 'ts' => 0, 'when' => -1, 'local' => -1, 'marker' => -1, 'datepart' => -1, 'timepart' => -1];
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
                continue;
            }
            if ($map['when'] < 0 && ($n === 'data hora' || $n === 'datahora' || $n === 'data e hora' || $n === 'quando' || $n === 'horario' || $n === 'data do evento' || $n === 'datahora do evento')) {
                $map['when'] = $i;
                continue;
            }
            if ($n === 'data' || $n === 'dia') {
                $map['datepart'] = $i;
                continue;
            }
            if ($n === 'hora' || $n === 'horario evento') {
                $map['timepart'] = $i;
                continue;
            }
            if ($map['local'] < 0 && ($n === 'local' || $n === 'lugar' || $n === 'destino' || $n === 'origem' || $n === 'parada' || $n === 'place' || $n === 'location' || $n === 'escola')) {
                $map['local'] = $i;
                continue;
            }
            if ($map['marker'] < 0 && self::isArrivalMarker($n)) {
                $map['marker'] = $i;
            }
        }
        return $map;
    }

    /**
     * Lê as linhas novas da planilha via Sheets API v4.
     *
     * @return array{ok:bool,message:string,rows:list<array{row:int,ts:string,code:string,when:string,local:string,event:string,km:int,driver:string,obs:string}>,last_row:int}
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
        // Usa a ordem documentada em vez do mapa vazio:
        // Carimbo | Código | Data/Hora | Local | KM | Tipo | Motorista | Obs.
        if ($map['code'] < 0 && $map['event'] < 0 && $map['km'] < 0 && $map['driver'] < 0 && $map['when'] < 0 && $map['local'] < 0) {
            $map = ['code' => 1, 'event' => 6, 'km' => 5, 'driver' => 7, 'obs' => 8, 'ts' => 0, 'when' => -1, 'local' => 4, 'marker' => -1, 'datepart' => 2, 'timepart' => 3];
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
            $eventRaw = $map['event'] >= 0 ? $cell($map['event']) : '';
            // Marcador de chegada (coluna "Chegada" com X): marcada = chegada,
            // vazia = saída. Só vale quando não há tipo textual.
            if ($eventRaw === '' && $map['marker'] >= 0 && trim($cell($map['marker'])) !== '') {
                $eventRaw = 'Chegada';
            } elseif ($eventRaw === '' && $map['marker'] >= 0) {
                $eventRaw = 'Saída';
            }
            $whenVal = $map['when'] >= 0 ? $cell($map['when']) : '';
            if ($whenVal === '' && $map['datepart'] >= 0) {
                $dp = $cell($map['datepart']);
                $tp = $map['timepart'] >= 0 ? $cell($map['timepart']) : '';
                if ($dp !== '') {
                    $whenVal = trim($dp . ' ' . $tp);
                }
            }
            $rows[] = [
                'row'    => $absRow,
                'ts'     => $map['ts'] >= 0 ? $cell($map['ts']) : '',
                'code'   => $map['code'] >= 0 ? $cell($map['code']) : '',
                'when'   => $whenVal,
                'local'  => $map['local'] >= 0 ? $cell($map['local']) : '',
                'event'  => $eventRaw,
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

    public const LEGS_TABLE = 'glpi_plugin_reservafrota_legs';

    /**
     * O local é a base (URE)? Compara por palavra exata ("URE Jales" vale;
     * "Pureza" não, graças à fronteira de palavra).
     */
    public static function isBaseLocal(string $local): bool
    {
        $n = ' ' . self::norm($local) . ' ';
        return str_contains($n, ' ure ');
    }

    /**
     * Maior KM já registrado na reserva (inicial + pernas).
     */
    private static function maxRecordedKm(int $bookingsId, ?int $kmInitial): int
    {
        $max = (int) ($kmInitial ?? 0);
        try {
            /** @var \DBmysql $DB */
            global $DB;
            if ($DB->tableExists(self::LEGS_TABLE)) {
                $row = $DB->request([
                    'SELECT' => ['MAX' => 'km AS max_km'],
                    'FROM'   => self::LEGS_TABLE,
                    'WHERE'  => ['bookings_id' => $bookingsId],
                ])->current();
                if (is_array($row) && $row['max_km'] !== null) {
                    $max = max($max, (int) $row['max_km']);
                }
            }
        } catch (\Throwable $e) {}
        return $max;
    }

    /**
     * Grava uma perna no diário de bordo. Devolve false se a perna exata
     * já existe (proteção contra duplo processamento).
     */
    private static function insertLeg(int $bookingsId, string $code, string $when, string $local, int $km, string $event, string $driver, int $rowNum): bool
    {
        try {
            /** @var \DBmysql $DB */
            global $DB;
            if (!$DB->tableExists(self::LEGS_TABLE)) {
                return true;
            }
            $dup = $DB->request([
                'SELECT' => ['id'],
                'FROM'   => self::LEGS_TABLE,
                'WHERE'  => [
                    'bookings_id' => $bookingsId,
                    'event_at'    => $when,
                    'km'          => $km,
                    'event'       => $event,
                ],
                'LIMIT'  => 1,
            ])->current();
            if (is_array($dup)) {
                return false;
            }
            return (bool) $DB->insert(self::LEGS_TABLE, [
                'bookings_id'   => $bookingsId,
                'request_code'  => substr($code, 0, 32),
                'event_at'      => $when,
                'local'         => substr($local, 0, 255),
                'km'            => $km,
                'event'         => substr($event, 0, 16),
                'driver_name'   => substr($driver, 0, 255),
                'row_num'       => $rowNum,
                'date_creation' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            return true;
        }
    }

    /**
     * Pernas de uma reserva (mais antigas primeiro) — para a auditoria.
     *
     * @return list<array<string,mixed>>
     */
    public static function getLegs(int $bookingsId, int $limit = 100): array
    {
        $out = [];
        try {
            /** @var \DBmysql $DB */
            global $DB;
            if (!$DB->tableExists(self::LEGS_TABLE)) {
                return [];
            }
            $it = $DB->request([
                'FROM'  => self::LEGS_TABLE,
                'WHERE' => ['bookings_id' => $bookingsId],
                'ORDER' => ['event_at ASC', 'id ASC'],
                'LIMIT' => max(1, min(500, $limit)),
            ]);
            foreach ($it as $row) {
                $out[] = $row;
            }
        } catch (\Throwable $e) {}
        return $out;
    }

    /**
     * Percurso de uma viagem para a tela do gestor: registros em ordem, com o
     * KM de cada trecho, e os totais.
     *
     * @param array<string,mixed> $booking Campos da reserva.
     * @return array{legs:list<array<string,mixed>>,stops:int,km_start:?int,km_end:?int,km_total:?int,duration:string}
     */
    public static function getTripTimeline(array $booking): array
    {
        $legs = [];
        $stops = 0;
        $previousKm = null;
        foreach (self::getLegs((int) ($booking['id'] ?? 0), 500) as $leg) {
            $km = (int) ($leg['km'] ?? 0);
            if (($leg['event'] ?? '') === self::EVENT_DEPARTURE) {
                $label = __('Saída', 'reservafrota');
                $icon = 'ti-player-play';
            } elseif (self::isBaseLocal((string) ($leg['local'] ?? ''))) {
                $label = __('Chegada na URE', 'reservafrota');
                $icon = 'ti-flag-check';
            } else {
                $label = __('Parada', 'reservafrota');
                $icon = 'ti-map-pin';
                $stops++;
            }
            $leg['label'] = $label;
            $leg['icon'] = $icon;
            // Lançamento fora de ordem (reprocessado) não tem trecho a mostrar.
            $leg['delta'] = ($previousKm !== null && $km >= $previousKm) ? $km - $previousKm : null;
            $previousKm = max($previousKm ?? 0, $km);
            $legs[] = $leg;
        }

        // Sem pernas (chegada lançada direto no GLPI), usa os campos da reserva.
        $kmStart = $legs !== [] ? min(array_map(static fn($l) => (int) $l['km'], $legs)) : null;
        $kmEnd = $legs !== [] ? max(array_map(static fn($l) => (int) $l['km'], $legs)) : null;
        if ($kmStart === null && !empty($booking['km_initial'])) {
            $kmStart = (int) $booking['km_initial'];
        }
        if (!empty($booking['km_final'])) {
            $kmEnd = max((int) $booking['km_final'], (int) $kmEnd);
        }

        $duration = '';
        if (count($legs) >= 2) {
            $seconds = (int) strtotime((string) $legs[count($legs) - 1]['event_at']) - (int) strtotime((string) $legs[0]['event_at']);
            if ($seconds > 0) {
                $hours = (int) floor($seconds / 3600);
                $minutes = (int) floor(($seconds % 3600) / 60);
                $duration = ($hours > 0 ? $hours . 'h ' : '') . $minutes . 'min';
            }
        }

        return [
            'legs'     => $legs,
            'stops'    => $stops,
            'km_start' => $kmStart,
            'km_end'   => $kmEnd,
            'km_total' => ($kmStart !== null && $kmEnd !== null && $kmEnd >= $kmStart) ? $kmEnd - $kmStart : null,
            'duration' => $duration,
        ];
    }

    /**
     * Registros recusados de uma viagem, ainda sem reprocesso aplicado.
     *
     * @return list<array<string,mixed>>
     */
    public static function getRejectedRows(int $bookingsId): array
    {
        $out = [];
        try {
            /** @var \DBmysql $DB */
            global $DB;
            if ($bookingsId <= 0 || !$DB->tableExists(self::LOG_TABLE)) {
                return [];
            }
            $applied = [];
            $it = $DB->request([
                'SELECT' => ['row_num'],
                'FROM'   => self::LOG_TABLE,
                'WHERE'  => ['bookings_id' => $bookingsId, 'status' => 'applied', 'row_num' => ['>', 0]],
            ]);
            foreach ($it as $row) {
                $applied[(int) $row['row_num']] = true;
            }
            $it = $DB->request([
                'FROM'  => self::LOG_TABLE,
                'WHERE' => ['bookings_id' => $bookingsId, 'status' => 'error'],
                'ORDER' => 'id ASC',
                'LIMIT' => 100,
            ]);
            foreach ($it as $row) {
                if (!isset($applied[(int) ($row['row_num'] ?? 0)])) {
                    $out[] = $row;
                }
            }
        } catch (\Throwable $e) {}
        return $out;
    }

    /**
     * Registra uma linha no log de sincronização.
     */
    public static function logRow(int $rowNum, string $receivedAt, string $code, int $bookingsId, string $event, int $km, string $driver, string $obs, string $local, string $status, string $message, string $source): void
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
                'local'        => ($local !== '' && $DB->fieldExists(self::LOG_TABLE, 'local')) ? substr($local, 0, 255) : '',
                'status'       => substr($status, 0, 16),
                'message'      => $message !== '' ? $message : null,
                'source'       => substr($source, 0, 16),
                'date_creation' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {}
    }

    /**
     * Aplica um evento do diário de bordo (uma parada: saída ou chegada).
     * Roda em contexto de sistema (cron/botão): usa UPDATE direto, sem
     * checagem de perfil — as travas de negócio estão todas aqui dentro.
     *
     * Regras: primeira saída define o KM inicial; toda perna atualiza o KM
     * do carro; só conclui (Concluído) a chegada com local na URE (base).
     *
     * @return array{ok:bool,message:string,bookings_id:int}
     */
    public static function applyEvent(string $codeRaw, string $eventRaw, int $km, string $driverRaw, ?string $whenRaw, string $obs = '', string $local = '', string $source = 'sheet', bool $force = false, int $rowNum = 0): array
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
            $local = trim((string) $local);
            $carId = (int) ($booking->fields['plugin_reservafrota_cars_id'] ?? 0);
            // Hodômetro nunca anda para trás (salvo força do gestor): compara
            // com os registros da reserva e com o KM atual do carro, porque a
            // primeira saída de uma viagem ainda não tem registro próprio.
            $maxKm = max(
                self::maxRecordedKm($bid, isset($booking->fields['km_initial']) && $booking->fields['km_initial'] !== null ? (int) $booking->fields['km_initial'] : null),
                $carId > 0 ? Car::getCurrentKm($carId) : 0
            );
            if (!$force) {
                if ($km < $maxKm) {
                    return $fail(sprintf(__('KM %s menor que o último registrado (%s).', 'reservafrota'), number_format($km, 0, ',', '.'), number_format($maxKm, 0, ',', '.')), $bid);
                }
                // Salto grande costuma ser erro de digitação e, se aplicado,
                // bloquearia os próximos motoristas. O gestor pode reprocessar.
                $maxJump = (int) (self::getConfig()['max_km_jump'] ?? self::DEFAULT_MAX_KM_JUMP);
                if ($maxKm > 0 && $maxJump > 0 && ($km - $maxKm) > $maxJump) {
                    return $fail(sprintf(__('KM %s está %s km acima do último registrado (%s). Confira e reprocesse se estiver correto.', 'reservafrota'), number_format($km, 0, ',', '.'), number_format($km - $maxKm, 0, ',', '.'), number_format($maxKm, 0, ',', '.')), $bid);
                }
            }
            // Grava a perna no diário de bordo (idempotente).
            self::insertLeg($bid, $code, $when, $local, $km, $event, $driverName, $rowNum);
            $upd = [];
            if ($adopted) {
                $upd['plugin_reservafrota_drivers_id'] = $drvId;
                $upd['driver'] = $driverName;
            }
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
            if ($event === self::EVENT_DEPARTURE) {
                $curInit = $booking->fields['km_initial'] ?? null;
                if ($curInit === null || (int) $curInit <= 0) {
                    $upd['km_initial'] = $km;
                }
                if (!empty($upd) && !$DB->update(Booking::getTable(), $upd, ['id' => $bid])) {
                    return $fail(__('Falha ao gravar a saída.', 'reservafrota'), $bid);
                }
                $msg = sprintf(__('Saída registrada via Forms: %s, KM %s em %s (motorista %s).', 'reservafrota'), $local !== '' ? $local : '—', number_format($km, 0, ',', '.'), $when, $driverName);
                \Log::history($bid, Booking::class, [0, '', $msg], '', \Log::HISTORY_LOG_SIMPLE_MESSAGE);
                return ['ok' => true, 'message' => $msg . $carNote, 'bookings_id' => $bid];
            }

            // Chegada fora da base: só registra a parada, a viagem continua.
            if (!self::isBaseLocal($local)) {
                if (!empty($upd) && !$DB->update(Booking::getTable(), $upd, ['id' => $bid])) {
                    return $fail(__('Falha ao gravar a parada.', 'reservafrota'), $bid);
                }
                $msg = sprintf(__('Parada registrada via Forms: %s, KM %s em %s.', 'reservafrota'), $local !== '' ? $local : '—', number_format($km, 0, ',', '.'), $when);
                \Log::history($bid, Booking::class, [0, '', $msg], '', \Log::HISTORY_LOG_SIMPLE_MESSAGE);
                return ['ok' => true, 'message' => $msg . $carNote, 'bookings_id' => $bid];
            }

            // Chegada na URE (base): conclui a viagem.
            $upd['km_final'] = $km;
            $upd['date_returned'] = $when;
            $upd['status'] = Booking::STATUS_ARRIVED;
            if (trim($obs) !== '') {
                $upd['arrival_obs'] = trim($obs);
            }
            if (!$DB->update(Booking::getTable(), $upd, ['id' => $bid])) {
                return $fail(__('Falha ao gravar a chegada.', 'reservafrota'), $bid);
            }
            Booking::notifyEvent('returned', $bid);
            $msg = sprintf(__('Chegada registrada via Forms: %s, KM %s em %s (motorista %s). Viagem concluída.', 'reservafrota'), $local, number_format($km, 0, ',', '.'), $when, $driverName);
            \Log::history($bid, Booking::class, [0, '', $msg], '', \Log::HISTORY_LOG_SIMPLE_MESSAGE);
            if (trim($obs) !== '' && $carId > 0) {
                \Log::history($carId, Car::class, [0, '', sprintf(__('Observação de viagem (agendamento #%d): %s', 'reservafrota'), $bid, trim($obs))], '', \Log::HISTORY_LOG_SIMPLE_MESSAGE);
            }
            return ['ok' => true, 'message' => $msg . $carNote, 'bookings_id' => $bid];
        } catch (\Throwable $e) {
            return $fail(__('Erro interno ao aplicar o evento.', 'reservafrota'), $bid);
        }
    }

    // ================= Formulário web (Apps Script) =================

    /**
     * Token do link do motorista. O Apps Script calcula o mesmo HMAC, então
     * o link funciona mesmo antes de a viagem chegar ao Google.
     */
    public static function formToken(string $code, string $secret): string
    {
        return substr(hash_hmac('sha256', $code, $secret), 0, 16);
    }

    /**
     * Link do formulário para uma viagem ('' se não configurado).
     */
    public static function getDriverFormLink(string $codeRaw): string
    {
        $code = self::normCode($codeRaw);
        $cfg = self::getConfig();
        $url = trim((string) ($cfg['webapp_url'] ?? ''));
        $secret = trim((string) ($cfg['webapp_secret'] ?? ''));
        if ($code === '' || $url === '' || $secret === '') {
            return '';
        }
        // Um único parâmetro: sem "&", o e-mail não tem como corromper o link
        // (o GLPI pode codificá-lo como "&#38;", e o "#" corta o token).
        return $url . '?v=' . $code . '-' . self::formToken($code, $secret);
    }

    /**
     * Monta os dados das viagens aprovadas para o formulário.
     *
     * @return array<string,mixed>
     */
    public static function buildSnapshot(array $cfg): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $range = (string) ($cfg['sheet_range'] ?? '');
        $sheet = str_contains($range, '!') ? trim(substr($range, 0, (int) strrpos($range, '!')), " '") : '';

        $drivers = [];
        foreach (Driver::getActiveDrivers() as $id => $d) {
            $drivers[(int) $id] = (string) ($d['name'] ?? '');
        }

        $trips = [];
        $carIds = [];
        $it = $DB->request([
            'SELECT' => [
                'request_code', 'date_departure', 'date_arrival', 'driver',
                'plugin_reservafrota_drivers_id', 'plugin_reservafrota_cars_id',
            ],
            'FROM'   => Booking::getTable(),
            'WHERE'  => [
                'status'     => Booking::STATUS_APPROVED,
                'is_deleted' => 0,
                ['date_departure' => ['>=', date('Y-m-d 00:00:00', strtotime('-' . self::PUSH_DAYS_BACK . ' days'))]],
                ['date_departure' => ['<=', date('Y-m-d 23:59:59', strtotime('+' . self::PUSH_DAYS_FORWARD . ' days'))]],
            ],
            'ORDER'  => 'date_departure ASC',
        ]);
        foreach ($it as $r) {
            $code = self::normCode((string) ($r['request_code'] ?? ''));
            if ($code === '') {
                continue;
            }
            $carId = (int) ($r['plugin_reservafrota_cars_id'] ?? 0);
            if ($carId > 0) {
                $carIds[$carId] = $carId;
            }
            $from = substr((string) ($r['date_departure'] ?? ''), 0, 10);
            $driverId = (int) ($r['plugin_reservafrota_drivers_id'] ?? 0);
            $trips[] = [
                'code'   => $code,
                'from'   => $from,
                'to'     => substr((string) ($r['date_arrival'] ?? ''), 0, 10) ?: $from,
                'car'    => $carId,
                // Sem motorista designado, o formulário pede para escolher.
                'driver' => $driverId > 0 ? ($drivers[$driverId] ?? trim((string) ($r['driver'] ?? ''))) : '',
            ];
        }

        $cars = [];
        if ($carIds !== []) {
            $it = $DB->request([
                'SELECT' => ['id', 'name', 'plate', 'km_current'],
                'FROM'   => Car::getTable(),
                'WHERE'  => ['id' => array_values($carIds)],
            ]);
            foreach ($it as $c) {
                $plate = trim((string) ($c['plate'] ?? ''));
                $cars[(string) $c['id']] = [
                    'label' => trim((string) ($c['name'] ?? '')) . ($plate !== '' ? ' - ' . $plate : ''),
                    'km'    => (int) ($c['km_current'] ?? 0),
                ];
            }
        }

        // Viagens encerradas: o formulário troca o link pelo aviso de cada situação.
        $closed = [];
        $it = $DB->request([
            'SELECT' => ['request_code', 'date_departure', 'date_arrival', 'date_returned', 'date_validation', 'status'],
            'FROM'   => Booking::getTable(),
            'WHERE'  => [
                'status'     => [Booking::STATUS_ARRIVED, Booking::STATUS_CANCELLED, Booking::STATUS_REJECTED],
                'is_deleted' => 0,
                ['date_departure' => ['>=', date('Y-m-d 00:00:00', strtotime('-' . self::PUSH_CLOSED_DAYS_BACK . ' days'))]],
            ],
            'ORDER'  => 'date_departure ASC',
        ]);
        foreach ($it as $r) {
            $code = self::normCode((string) ($r['request_code'] ?? ''));
            if ($code === '') {
                continue;
            }
            $from = substr((string) ($r['date_departure'] ?? ''), 0, 10);
            $status = (int) ($r['status'] ?? 0);
            $arrived = $status === Booking::STATUS_ARRIVED;
            $closed[] = [
                'code' => $code,
                'from' => $from,
                'to'   => substr((string) ($r['date_arrival'] ?? ''), 0, 10) ?: $from,
                'at'   => substr((string) ($r[$arrived ? 'date_returned' : 'date_validation'] ?? ''), 0, 16),
                'kind' => $arrived ? 'finished' : ($status === Booking::STATUS_CANCELLED ? 'cancelled' : 'rejected'),
            ];
        }

        return [
            'trips'    => $trips,
            'closed'   => $closed,
            'cars'     => (object) $cars,
            'drivers'  => array_values(array_filter($drivers, static fn($n) => $n !== '')),
            'cursor'   => (int) ($cfg['last_row'] ?? 1),
            'max_jump' => (int) ($cfg['max_km_jump'] ?? self::DEFAULT_MAX_KM_JUMP),
            'sheet'    => $sheet,
        ];
    }

    /**
     * POST JSON no app da web. O Apps Script responde com um redirecionamento
     * 302, e o curl o segue com GET por padrão, que é o que devolve o JSON.
     *
     * @return array{ok:bool,body:string,error:string}
     */
    private static function httpsPostJson(string $url, string $json, int $timeout = 15): array
    {
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'body' => '', 'error' => 'extensão curl ausente'];
        }
        try {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $json,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 5,
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

    /**
     * Envia as viagens e o KM dos carros ao formulário. Falha aqui nunca
     * derruba a sincronização nem a aprovação: fica registrada na tela da
     * integração e o próximo cron tenta de novo.
     *
     * @return array{ok:bool,message:string}
     */
    public static function pushSnapshot(): array
    {
        self::$pushQueued = false;
        $cfg = self::getConfig();
        $url = trim((string) ($cfg['webapp_url'] ?? ''));
        $secret = trim((string) ($cfg['webapp_secret'] ?? ''));
        if ($url === '' || $secret === '') {
            return ['ok' => false, 'message' => __('Formulário web não configurado.', 'reservafrota')];
        }
        $error = '';
        try {
            $payload = self::buildSnapshot($cfg);
            $payload['secret'] = $secret;
            $res = self::httpsPostJson($url, (string) json_encode($payload, JSON_UNESCAPED_UNICODE));
            if (!$res['ok']) {
                $error = $res['error'];
            } else {
                $json = json_decode($res['body'], true);
                if (!is_array($json)) {
                    $error = __('Resposta inesperada do Google. Confira se o app da web está implantado com acesso para "Qualquer pessoa".', 'reservafrota');
                } elseif (empty($json['ok'])) {
                    $error = (string) ($json['error'] ?? 'erro desconhecido');
                }
            }
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }
        self::setPushStatus($error);
        return $error === ''
            ? ['ok' => true, 'message' => __('Dados enviados ao formulário.', 'reservafrota')]
            : ['ok' => false, 'message' => sprintf(__('Falha ao enviar dados ao formulário: %s', 'reservafrota'), $error)];
    }

    private static function setPushStatus(string $error): void
    {
        try {
            /** @var \DBmysql $DB */
            global $DB;
            $data = ['last_push_error' => substr($error, 0, 255)];
            if ($error === '') {
                $data['last_push'] = date('Y-m-d H:i:s');
            }
            $DB->update(self::CFG_TABLE, $data, ['id' => 1]);
        } catch (\Throwable $e) {}
    }

    /**
     * Agenda um envio para o fim da requisição. Várias mudanças na mesma
     * requisição (ex.: aprovar a série da semana) geram um envio só.
     */
    public static function queuePush(): void
    {
        self::$pushQueued = true;
        if (!self::$pushShutdownRegistered) {
            self::$pushShutdownRegistered = true;
            register_shutdown_function([self::class, 'runQueuedPush']);
        }
    }

    public static function runQueuedPush(): void
    {
        if (!self::$pushQueued) {
            return;
        }
        try {
            self::pushSnapshot();
        } catch (\Throwable $e) {}
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
            self::pushSnapshot();
            return ['ok' => false, 'message' => (string) $fetch['message'], 'applied' => 0, 'errors' => 0, 'total' => 0];
        }
        $applied = 0;
        $errors = 0;
        $maxRow = (int) ($cfg['last_row'] ?? 1);
        foreach ($fetch['rows'] as $r) {
            $maxRow = max($maxRow, (int) $r['row']);
            $eventWhen = ($r['when'] ?? '') !== '' ? (string) $r['when'] : (($r['ts'] ?? '') !== '' ? (string) $r['ts'] : null);
            $res = self::applyEvent(
                (string) $r['code'],
                (string) $r['event'],
                (int) $r['km'],
                (string) $r['driver'],
                $eventWhen,
                (string) $r['obs'],
                (string) ($r['local'] ?? ''),
                'sheet',
                false,
                (int) $r['row']
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
                (string) ($r['local'] ?? ''),
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
        // Depois do cursor: o formulário descarta os KMs locais já processados.
        self::pushSnapshot();
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
                (string) ($row['local'] ?? ''),
                'sheet',
                true,
                (int) ($row['row_num'] ?? 0)
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
                (string) ($row['local'] ?? ''),
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
