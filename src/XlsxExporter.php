<?php

namespace GlpiPlugin\Reservafrota;

use ZipArchive;

/**
 * Gerador de planilhas .xlsx sem dependências externas.
 *
 * Monta um arquivo Office Open XML (o mesmo formato do Excel) usando apenas
 * a extensão ZipArchive, padrão no PHP. As células de texto usam "inline
 * strings" (sem tabela de strings compartilhadas), o que mantém o arquivo
 * simples e válido para o Excel e o LibreOffice.
 *
 * Uso típico (dispara o download e encerra a requisição):
 *   XlsxExporter::download('arquivo.xlsx', 'Planilha', $headers, $rows);
 */
final class XlsxExporter
{
    /**
     * Escapa um valor para uso dentro do XML, removendo caracteres de
     * controle que invalidariam o documento.
     */
    private static function xml(string $value): string
    {
        // Remove caracteres de controle não permitidos em XML 1.0
        // (mantém tab, quebra de linha e retorno de carro).
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $value) ?? '';
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /**
     * Converte um índice de coluna (1 = A, 2 = B, 27 = AA) em letra.
     */
    private static function colLetter(int $n): string
    {
        $s = '';
        while ($n > 0) {
            $rem = ($n - 1) % 26;
            $s   = chr(65 + $rem) . $s;
            $n   = intdiv($n - 1, 26);
        }
        return $s;
    }

    /**
     * Constrói o XML da planilha (worksheet) a partir do cabeçalho e linhas.
     *
     * @param list<string>        $headers
     * @param list<list<string>>  $rows
     */
    private static function buildSheet(array $headers, array $rows): string
    {
        $ncols = count($headers);
        $nrows = count($rows) + 1; // + cabeçalho
        $dim   = 'A1:' . self::colLetter(max(1, $ncols)) . max(1, $nrows);

        // Larguras de coluna (fixa, legível).
        $cols = '<cols>';
        for ($i = 1; $i <= $ncols; $i++) {
            $cols .= '<col min="' . $i . '" max="' . $i . '" width="22" customWidth="1"/>';
        }
        $cols .= '</cols>';

        // Cabeçalho (estilo 1 = negrito sobre fundo azul).
        $body = '<row r="1">';
        foreach ($headers as $i => $h) {
            $ref   = self::colLetter($i + 1) . '1';
            $body .= '<c r="' . $ref . '" s="1" t="inlineStr"><is><t xml:space="preserve">'
                . self::xml((string) $h) . '</t></is></c>';
        }
        $body .= '</row>';

        // Linhas de dados.
        $r = 2;
        foreach ($rows as $row) {
            $body .= '<row r="' . $r . '">';
            $c = 1;
            foreach ($row as $val) {
                $ref   = self::colLetter($c) . $r;
                $body .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">'
                    . self::xml((string) $val) . '</t></is></c>';
                $c++;
            }
            $body .= '</row>';
            $r++;
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<dimension ref="' . $dim . '"/>'
            . '<sheetViews><sheetView workbookViewId="0">'
            . '<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>'
            . '</sheetView></sheetViews>'
            . '<sheetFormatPr defaultRowHeight="15"/>'
            . $cols
            . '<sheetData>' . $body . '</sheetData>'
            . '<autoFilter ref="' . $dim . '"/>'
            . '</worksheet>';
    }

    /**
     * Gera o arquivo .xlsx em disco e devolve o caminho do arquivo temporário.
     *
     * @param list<string>       $headers
     * @param list<list<string>> $rows
     */
    public static function build(string $sheetName, array $headers, array $rows): string
    {
        $sheet = self::buildSheet($headers, $rows);

        $content_types =
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>';

        $rels =
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';

        // Nome da aba: máx. 31 caracteres e sem caracteres proibidos.
        $safe_sheet = preg_replace('/[\\\\\/?*\[\]:]/', ' ', $sheetName) ?? 'Planilha';
        $safe_sheet = mb_substr(trim($safe_sheet), 0, 31, 'UTF-8');
        if ($safe_sheet === '') {
            $safe_sheet = 'Planilha';
        }

        $workbook =
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . self::xml($safe_sheet) . '" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>';

        $wb_rels =
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>';

        $styles =
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2">'
            . '<font><sz val="11"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
            . '</fonts>'
            . '<fills count="3">'
            . '<fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF4263EB"/></patternFill></fill>'
            . '</fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="2">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"><alignment vertical="center"/></xf>'
            . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';

        $tmp = tempnam(sys_get_temp_dir(), 'cbxlsx');
        if ($tmp === false) {
            throw new \RuntimeException('Não foi possível criar arquivo temporário para a planilha.');
        }

        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Não foi possível gerar a planilha (ZipArchive).');
        }

        $zip->addFromString('[Content_Types].xml', $content_types);
        $zip->addFromString('_rels/.rels', $rels);
        $zip->addFromString('xl/workbook.xml', $workbook);
        $zip->addFromString('xl/_rels/workbook.xml.rels', $wb_rels);
        $zip->addFromString('xl/styles.xml', $styles);
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
        $zip->close();

        return $tmp;
    }

    /**
     * Gera a planilha e a envia ao navegador como download, encerrando o script.
     *
     * @param list<string>       $headers
     * @param list<list<string>> $rows
     */
    public static function download(string $filename, string $sheetName, array $headers, array $rows): void
    {
        $tmp = self::build($sheetName, $headers, $rows);

        // Garante que nenhum conteúdo anterior contamine o arquivo binário.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $safe_name = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) ?: 'planilha.xlsx';

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $safe_name . '"');
        header('Content-Length: ' . filesize($tmp));
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');

        readfile($tmp);
        @unlink($tmp);
        exit;
    }
}
