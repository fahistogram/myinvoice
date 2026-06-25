<?php

declare(strict_types=1);

namespace MyInvoice\Service\Pdf;

use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use MyInvoice\Bootstrap;

/**
 * Registrace fontu Satoshi (Fontshare) pro mPDF — sdíleno renderery, které
 * používají styles/invoice.css (faktura, výkaz víceprací, přijatá faktura).
 *
 * Satoshi je primární brandové písmo faktury. mPDF zná jen fonty zaregistrované
 * ve `fontdata`; bez tohoto helperu by `font-family: 'satoshi'` spadl zpět na
 * default (dejavusans). Statické TTF (Regular/Bold/Italic/BoldItalic) leží
 * v styles/fonts/satoshi/ (cesta přes Bootstrap::rootDir(), žádný systémový font).
 *
 * Tabulkové číslice jedou přes 'jetbrainsmono' (JetBrains Mono, OFL) kvůli
 * zarovnání číselných sloupců — Satoshi má jen proporcionální číslice. Font leží
 * v api/resources/fonts/ (v REPU, deployuje se vždy), takže záměrně NEzávisíme
 * na mPDF bundled DejaVuSansMono, který build cache / cleanup může smazat
 * (→ Cannot find TTF DejaVuSansMono.ttf na deployi).
 */
final class PdfFonts
{
    /**
     * Fragment Mpdf konfigurace (fontDir + fontdata + default_font) k rozbalení
     * do konstruktoru Mpdf přes `...PdfFonts::mpdfConfig()`. Default font = 'satoshi'.
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
        // Satoshi (Fontshare) — primární brandové písmo faktury, self-hosted
        // v styles/fonts/satoshi/. Řezy R/B/I/BI (mPDF váhový model).
        $fontData['satoshi'] = [
            'R'  => 'Satoshi-Regular.ttf',
            'B'  => 'Satoshi-Bold.ttf',
            'I'  => 'Satoshi-Italic.ttf',
            'BI' => 'Satoshi-BoldItalic.ttf',
        ];
        // JetBrains Mono (OFL) — tabulkové číslice (zarovnání sloupců, IBANy, varsymboly).
        // Stejný zdroj (api/resources/fonts/) jako MpdfFontConfig → deployuje se vždy.
        $fontData['jetbrainsmono'] = ['R' => 'JetBrainsMono-Regular.ttf', 'B' => 'JetBrainsMono-Bold.ttf'];

        return [
            'fontDir'          => array_merge(
                $defCfg['fontDir'],
                [MpdfFontConfig::fontDir(), Bootstrap::rootDir() . '/styles/fonts/satoshi'],
            ),
            'fontdata'         => $fontData,
            'default_font'     => 'satoshi',
            'useSubstitutions' => true,
            'backupSubsFont'   => ['dejavusans'],
            // Generické CSS rodiny → fonty, které se VŽDY deployují (jinak by
            // `…, sans-serif` / `…, monospace` padlo na smazaný vendor font a shodilo render).
            'sans_fonts'       => ['satoshi', 'dejavusans'],
            'serif_fonts'      => ['dejavusans'],
            'mono_fonts'       => ['jetbrainsmono', 'dejavusans'],
        ];
    }
}
