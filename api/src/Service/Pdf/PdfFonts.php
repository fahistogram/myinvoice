<?php

declare(strict_types=1);

namespace MyInvoice\Service\Pdf;

use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use MyInvoice\Bootstrap;

/**
 * Registrace fontu Inter (OFL) pro mPDF — sdíleno renderery, které používají
 * styles/invoice.css (faktura, výkaz víceprací, přijatá faktura).
 *
 * Inter je primární brandové písmo MyInvoice (shodné s webovým UI). mPDF zná
 * jen fonty zaregistrované ve `fontdata`; bez tohoto helperu by `font-family:
 * 'Inter'` spadl zpět na default (dejavusans). Statické TTF (Regular/SemiBold/
 * Bold, varianta 4.1) leží v styles/fonts/inter/ a fungují identicky na
 * Win/Linux/Dockeru (cesta přes Bootstrap::rootDir(), žádný systémový font).
 *
 * Tabulkové číslice zůstávají v 'DejaVu Sans Mono' (bundled v mPDF) kvůli
 * zarovnání číselných sloupců — Inter má jen proporcionální číslice.
 */
final class PdfFonts
{
    /**
     * Fragment Mpdf konfigurace (fontDir + fontdata + default_font) k rozbalení
     * do konstruktoru Mpdf přes `...PdfFonts::mpdfConfig()`. Default font = 'inter'.
     *
     * @return array{fontDir: list<string>, fontdata: array<string,mixed>, default_font: string}
     */
    public static function mpdfConfig(): array
    {
        $fontDir  = (new ConfigVariables())->getDefaults()['fontDir'];
        $fontData = (new FontVariables())->getDefaults()['fontdata'];

        return [
            'fontDir' => array_merge($fontDir, [Bootstrap::rootDir() . '/styles/fonts/inter']),
            // mapování family → soubory; 'Inter SemiBold' v CSS se mapuje na 'intersemibold'.
            'fontdata' => $fontData + [
                'inter' => [
                    'R' => 'Inter-Regular.ttf',
                    'B' => 'Inter-Bold.ttf',
                ],
                'intersemibold' => [
                    'R' => 'Inter-SemiBold.ttf',
                ],
            ],
            'default_font' => 'inter',
        ];
    }
}
