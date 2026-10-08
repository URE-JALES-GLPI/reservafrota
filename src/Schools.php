<?php

namespace GlpiPlugin\Reservafrota;

/**
 * Escolas estaduais da URE de Jales (fonte: educacao.sp.gov.br/urejales/escolas).
 * Os centros de línguas (CEL de Jales e CEL de Santa Fé do Sul) foram
 * desconsiderados a pedido da gestão — não aparecem como destino.
 *
 * O destino da viagem é sempre "ESCOLA — MUNICÍPIO". O usuário primeiro
 * escolhe o município (tratado como cidade) e depois a escola.
 */
class Schools
{
    /**
     * @return array<string, list<string>> município => escolas
     */
    public static function getMap(): array
    {
        return [
            "Aparecida D'Oeste" => [
                'EE Coripheu de Azevedo Marques',
            ],
            'Aspásia' => [
                'EE José dos Santos',
            ],
            'Auriflama' => [
                'EE Profª Maria Pereira de B. Benetoli',
                'EE João Rodrigues Fernandes',
            ],
            'Dirce Reis' => [
                'EE de Osvaldo Ramos',
            ],
            'Dolcinópolis' => [
                'EE Baptista Dolci',
            ],
            'Guzolândia' => [
                'EE Profª Vanir Ferrero Moraes',
            ],
            'Jales' => [
                'EE Dom Artur Horsthuis',
                'EE Dr. Euphly Jalles',
                'EE Prof. Carlos de Arnaldo Silva',
                'EE Profª Sueli da Silveira Marin Batista',
                'EE Juvenal Giraldelli',
                'EE Profª Onélia Faggioni Moreira',
            ],
            'Marinópolis' => [
                'EE Antonio Marin Cruz',
            ],
            'Mesópolis' => [
                'EE Adelino Bertani',
            ],
            'Nova Canaã Paulista' => [
                'EE Profª Maria Pilar Ortega Garcia',
            ],
            "Palmeira d'Oeste" => [
                'EE Orestes Ferreira de Toledo',
            ],
            'Paranapuã' => [
                'EE Prefeito José Ribeiro',
            ],
            'Pontalinda' => [
                'EE Profª Zélia de Lourdes Zaccarelli Lopes',
            ],
            'Rubinéia' => [
                'EE Rubens de Oliveira Camargo',
            ],
            'Santa Albertina' => [
                'EE Carlos Celso Lenarduzzi',
            ],
            "Santa Clara d'Oeste" => [
                'EE Prefeito Antonio Bezerra de Araújo',
            ],
            'Santa Fé do Sul' => [
                'EE Professor Itael de Mattos',
            ],
            "Santa Rita d'Oeste" => [
                'EE Profª Maria das Dores Ferreira Rocha',
            ],
            'Santa Salete' => [
                'EE Francisco Molina Molina',
            ],
            'Santana da Ponte Pensa' => [
                'EE Domingos Donato Rivelli',
            ],
            'São Francisco' => [
                'EE Oscar Antônio da Costa',
            ],
            'Suzanápolis' => [
                'EE Coronel Ernesto Schmidt',
            ],
            'Três Fronteiras' => [
                'EE Prof. José Joaquim dos Santos',
            ],
            'Urânia' => [
                'EE Professor Akio Satoru',
                'EE Profª Elide Apparecida Carlos',
                'EE José Teixeira do Amaral',
            ],
            'Vitória Brasil' => [
                'EE José Nogueira de Souza',
            ],
        ];
    }

    /**
     * @return list<string> municípios ordenados
     */
    public static function getMunicipalities(): array
    {
        $keys = array_keys(self::getMap());
        sort($keys, SORT_LOCALE_STRING);
        return $keys;
    }

    /**
     * @return list<string> destinos no formato "ESCOLA — MUNICÍPIO"
     */
    public static function getAllDestinations(): array
    {
        $out = [];
        foreach (self::getMap() as $city => $schools) {
            foreach ($schools as $school) {
                $out[] = $school . ' — ' . $city;
            }
        }
        sort($out, SORT_LOCALE_STRING);
        return $out;
    }

    public static function isValidDestination(string $destination): bool
    {
        $destination = trim($destination);
        if ($destination === '') {
            return false;
        }
        return in_array($destination, self::getAllDestinations(), true);
    }

    /**
     * Dado um destino gravado, devolve [escola, município] ou null.
     *
     * @return array{0:string,1:string}|null
     */
    public static function splitDestination(string $destination): ?array
    {
        $parts = explode(' — ', trim($destination));
        if (count($parts) !== 2) {
            return null;
        }
        return [$parts[0], $parts[1]];
    }
}
