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
 * Tabulkové číslice jedou přes 'jetbrainsmono' (JetBrains Mono, OFL) kvůli
 * zarovnání číselných sloupců — Inter má jen proporcionální číslice. Font leží
 * v api/resources/fonts/ (v REPU, deployuje se vždy), takže záměrně NEzávisíme
 * na mPDF bundled DejaVuSansMono, který build cache / cleanup může smazat
 * (→ Cannot find TTF DejaVuSansMono.ttf na deployi).
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
        $defCfg   = (new ConfigVariables())->getDefaults();
        $defFonts = (new FontVariables())->getDefaults();

        // Registruj JEN fonty, které jsou v REPU (deployují se vždy) — záměrně
        // NEzávisíme na mPDF bundled fontech (DejaVuSansMono apod.), které build
        // cache / cleanup-mpdf-fonts.php může smazat (→ Cannot find TTF na deployi).
        // dejavusans (z mPDF) necháváme jen jako backupSubsFont pro chybějící glyfy.
        $fontData = array_intersect_key($defFonts['fontdata'], ['dejavusans' => 1]);
        // Inter (OFL) — primární brandové písmo, self-hosted v styles/fonts/inter/.
        // 'Inter SemiBold' v CSS se mapuje na 'intersemibold'.
        $fontData['inter']         = ['R' => 'Inter-Regular.ttf', 'B' => 'Inter-Bold.ttf'];
        $fontData['intersemibold'] = ['R' => 'Inter-SemiBold.ttf'];
        // JetBrains Mono (OFL) — tabulkové číslice (zarovnání sloupců, IBANy, varsymboly).
        // Stejný zdroj (api/resources/fonts/) jako MpdfFontConfig → deployuje se vždy.
        $fontData['jetbrainsmono'] = ['R' => 'JetBrainsMono-Regular.ttf', 'B' => 'JetBrainsMono-Bold.ttf'];

        return [
            'fontDir'          => array_merge(
                $defCfg['fontDir'],
                [MpdfFontConfig::fontDir(), Bootstrap::rootDir() . '/styles/fonts/inter'],
            ),
            'fontdata'         => $fontData,
            'default_font'     => 'inter',
            'useSubstitutions' => true,
            'backupSubsFont'   => ['dejavusans'],
            // Generické CSS rodiny → fonty, které se VŽDY deployují (jinak by
            // `…, sans-serif` / `…, monospace` padlo na smazaný vendor font a shodilo render).
            'sans_fonts'       => ['inter', 'dejavusans'],
            'serif_fonts'      => ['dejavusans'],
            'mono_fonts'       => ['jetbrainsmono', 'dejavusans'],
        ];
    }
}
