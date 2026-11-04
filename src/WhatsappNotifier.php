<?php

namespace GlpiPlugin\Reservafrota;

/**
 * Envia ao motorista, por WhatsApp, o link do formulário da viagem aprovada.
 *
 * Reaproveita a configuração da Evolution API do plugin whatsappsimples, mas
 * não usa o sendMessage dele: aquele método exige um chat aberto e registra a
 * mensagem como de um atendente, o que poluiria a fila de atendimento.
 */
class WhatsappNotifier
{
    private const SERVICE = \GlpiPlugin\Whatsappsimples\Service\EvolutionApiService::class;
    private const MESSAGES_TABLE = 'glpi_plugin_whatsappsimples_messages';

    /**
     * O plugin de WhatsApp está ativo e pronto para fornecer a configuração?
     */
    public static function isAvailable(): bool
    {
        try {
            return \Plugin::isPluginActive('whatsappsimples') && class_exists(self::SERVICE);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Converte o telefone do cadastro para 55DDDNUMERO ('' se inválido).
     */
    public static function normalizePhone(string $raw): string
    {
        $digits = preg_replace('/\D/', '', $raw) ?? '';
        // Código de outro país: não confundir com DDD + número (+1 415 ...).
        if (str_starts_with(ltrim($raw), '+') && !str_starts_with($digits, '55')) {
            return '';
        }
        $digits = ltrim($digits, '0');
        if (strlen($digits) === 10 || strlen($digits) === 11) {
            $digits = '55' . $digits;
        }
        if (!str_starts_with($digits, '55') || strlen($digits) < 12 || strlen($digits) > 13) {
            return '';
        }
        return $digits;
    }

    /**
     * Monta o texto da mensagem.
     */
    public static function buildMessage(string $code, string $car, string $departure, string $destination, string $link): string
    {
        $lines = ['*Viagem #' . $code . ' aprovada*'];
        if ($car !== '') {
            $lines[] = 'Carro: ' . $car;
        }
        $ts = strtotime($departure);
        if ($ts !== false) {
            $lines[] = 'Saída: ' . date('d/m/Y H:i', $ts);
        }
        if ($destination !== '') {
            $lines[] = 'Destino: ' . $destination;
        }
        $lines[] = '';
        $lines[] = 'Registre a saída, as paradas e a chegada, com o KM, neste link:';
        $lines[] = $link;
        $lines[] = '';
        $lines[] = 'Este link é só desta viagem: não encaminhe. Mensagem automática, não responda.';
        return implode("\n", $lines);
    }

    /**
     * Envia o link da viagem ao motorista e grava o resultado na reserva.
     * Nunca lança erro: uma falha aqui não pode afetar a aprovação.
     *
     * @return array{ok:bool,skipped:bool,message:string}
     */
    public static function sendTripLink(Booking $booking): array
    {
        try {
            $link = SheetSync::getDriverFormLink((string) ($booking->fields['request_code'] ?? ''));
            if ($link === '' || !self::isAvailable()) {
                return ['ok' => false, 'skipped' => true, 'message' => ''];
            }
            $bid = (int) ($booking->fields['id'] ?? 0);

            $driver = new Driver();
            $driverId = (int) ($booking->fields['plugin_reservafrota_drivers_id'] ?? 0);
            if ($driverId <= 0 || !$driver->getFromDB($driverId)) {
                return self::finish($bid, false, __('Link não enviado por WhatsApp: a viagem está sem motorista.', 'reservafrota'));
            }
            $name = (string) ($driver->fields['name'] ?? '');
            $number = self::normalizePhone((string) ($driver->fields['phone'] ?? ''));
            if ($number === '') {
                return self::finish($bid, false, sprintf(__('Link não enviado por WhatsApp: telefone de %s vazio ou inválido.', 'reservafrota'), $name));
            }

            $carLabel = '';
            $car = new Car();
            if ($car->getFromDB((int) ($booking->fields['plugin_reservafrota_cars_id'] ?? 0))) {
                $plate = trim((string) ($car->fields['plate'] ?? ''));
                $carLabel = trim((string) ($car->fields['name'] ?? '')) . ($plate !== '' ? ' - ' . $plate : '');
            }

            $text = self::buildMessage(
                trim((string) ($booking->fields['request_code'] ?? '')),
                $carLabel,
                (string) ($booking->fields['date_departure'] ?? ''),
                trim((string) ($booking->fields['destination'] ?? '')),
                $link
            );

            $error = self::post($number, $text, trim((string) ($booking->fields['request_code'] ?? '')));
            if ($error !== '') {
                return self::finish($bid, false, sprintf(__('Link não enviado por WhatsApp para %s: %s', 'reservafrota'), $name, $error));
            }
            return self::finish($bid, true, sprintf(__('Link da viagem enviado por WhatsApp para %s.', 'reservafrota'), $name));
        } catch (\Throwable $e) {
            return self::finish((int) ($booking->fields['id'] ?? 0), false, __('Link não enviado por WhatsApp: erro interno.', 'reservafrota'));
        }
    }

    /**
     * Chama a Evolution API. Devolve '' em caso de sucesso ou o motivo da falha.
     * O motivo nunca inclui a resposta nem o token, porque fica salvo na reserva.
     */
    private static function post(string $number, string $text, string $code): string
    {
        $service = self::SERVICE;
        $url = rtrim((string) $service::getConfig('server_url'), '/');
        $token = (string) $service::getConfig('api_token');
        $instance = (string) $service::getConfig('instance_name');
        if ($url === '' || $token === '' || $instance === '') {
            return __('configuração da Evolution API incompleta.', 'reservafrota');
        }

        $ch = curl_init($url . '/message/sendText/' . rawurlencode($instance));
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'apikey: ' . $token],
            // "text" e "textMessage": mesmo formato duplo do whatsappsimples,
            // que cobre as duas gerações da Evolution API.
            CURLOPT_POSTFIELDS     => json_encode([
                'number'      => $number,
                'text'        => $text,
                'textMessage' => ['text' => $text],
            ], JSON_UNESCAPED_UNICODE),
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 12,
        ]);
        $body = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $http === 0) {
            return __('sem resposta da Evolution API.', 'reservafrota');
        }
        if ($http < 200 || $http >= 300) {
            return sprintf(__('a Evolution API recusou o envio (HTTP %d). Confira o número e a conexão do WhatsApp.', 'reservafrota'), $http);
        }
        $response = json_decode((string) $body, true);
        self::ignoreWebhookEcho((string) ($response['key']['id'] ?? ''), $code);
        return '';
    }

    /**
     * Evita que o webhook do whatsappsimples abra um chat com esta mensagem.
     *
     * O plugin trata mensagens "fromMe" sem histórico como conversa iniciada
     * pelo celular da empresa e só ignora as que já estão na tabela dele. Com
     * chats_id = 0 a linha não aparece em tela, exportação nem métrica, pois
     * todas filtram pelo chat; só serve à checagem por message_id. O texto
     * não leva o link, que não deve ficar guardado em outro plugin.
     */
    private static function ignoreWebhookEcho(string $messageId, string $code): void
    {
        if ($messageId === '') {
            return;
        }
        try {
            /** @var \DBmysql $DB */
            global $DB;
            if ($DB->tableExists(self::MESSAGES_TABLE)) {
                $DB->insert(self::MESSAGES_TABLE, [
                    'chats_id'      => 0,
                    'users_id'      => 0,
                    'message_id'    => substr($messageId, 0, 255),
                    'sender_type'   => 'attendant',
                    'message_text'  => 'Link da viagem #' . $code . ' enviado pelo Reserva de Frota.',
                    'is_internal'   => 1,
                    'date_creation' => date('Y-m-d H:i:s'),
                ]);
            }
        } catch (\Throwable $e) {
            error_log('Reservafrota: falha ao registrar mensagem enviada: ' . $e->getMessage());
        }
    }

    /**
     * @return array{ok:bool,skipped:bool,message:string}
     */
    private static function finish(int $bookingsId, bool $ok, string $message): array
    {
        try {
            /** @var \DBmysql $DB */
            global $DB;
            if ($bookingsId > 0 && $DB->fieldExists(Booking::getTable(), 'whatsapp_error')) {
                $data = ['whatsapp_error' => $ok ? '' : substr($message, 0, 255)];
                if ($ok) {
                    $data['whatsapp_sent_at'] = date('Y-m-d H:i:s');
                }
                $DB->update(Booking::getTable(), $data, ['id' => $bookingsId]);
            }
        } catch (\Throwable $e) {}
        return ['ok' => $ok, 'skipped' => false, 'message' => $message];
    }
}
