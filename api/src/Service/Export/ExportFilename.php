<?php

declare(strict_types=1);

namespace MyInvoice\Service\Export;

/**
 * Sdílený helper pro bezpečné názvy souborů / ZIP entry v exportech.
 *
 * Místo aby se diakritika (č, ě, ž, …) ve jménu firmy nahradila podtržítkem
 * (`Prijata-2025001-Z_lut_ k_ _.pdf`), nejdřív ji **přepíšeme na ASCII**
 * (č→c, ě→e, ž→z, ö→o, ß→ss, ł→l, …) a teprve zbytek nepovolených znaků nahradíme
 * podtržítkem. Výsledek je čitelný a přitom bezpečný proti zip-slipu / problémovým
 * znakům na FAT/NTFS (`Prijata-2025001-Zluty-kun.pdf`).
 *
 * Mapa pokrývá CZ + SK + DE/AT + PL (dodavatel přijaté faktury může být zahraniční).
 */
final class ExportFilename
{
    /** @var array<string,string> diakritika → ASCII */
    private const DIACRITICS = [
        // Čeština + Slovenština
        'á'=>'a','ä'=>'a','č'=>'c','ď'=>'d','é'=>'e','ě'=>'e','í'=>'i','ĺ'=>'l','ľ'=>'l',
        'ň'=>'n','ó'=>'o','ô'=>'o','ŕ'=>'r','ř'=>'r','š'=>'s','ť'=>'t','ú'=>'u','ů'=>'u',
        'ý'=>'y','ž'=>'z',
        'Á'=>'A','Ä'=>'A','Č'=>'C','Ď'=>'D','É'=>'E','Ě'=>'E','Í'=>'I','Ĺ'=>'L','Ľ'=>'L',
        'Ň'=>'N','Ó'=>'O','Ô'=>'O','Ŕ'=>'R','Ř'=>'R','Š'=>'S','Ť'=>'T','Ú'=>'U','Ů'=>'U',
        'Ý'=>'Y','Ž'=>'Z',
        // Němčina / Rakousko
        'ö'=>'o','ü'=>'u','ß'=>'ss','Ö'=>'O','Ü'=>'U',
        // Polština
        'ą'=>'a','ć'=>'c','ę'=>'e','ł'=>'l','ń'=>'n','ś'=>'s','ź'=>'z','ż'=>'z',
        'Ą'=>'A','Ć'=>'C','Ę'=>'E','Ł'=>'L','Ń'=>'N','Ś'=>'S','Ź'=>'Z','Ż'=>'Z',
    ];

    /** Přepíše diakritiku na ASCII (č→c, ě→e, ž→z, ö→o, ß→ss, ł→l, …). */
    public static function transliterate(string $s): string
    {
        return strtr($s, self::DIACRITICS);
    }

    /**
     * Bezpečný název souboru / ZIP entry: nejdřív transliterace diakritiky, pak
     * zbývající nepovolené znaky → podtržítko. Zachová `. - _` a alfanumeriku.
     */
    public static function sanitize(string $s, string $fallback = 'soubor'): string
    {
        $s = self::transliterate($s);
        $out = preg_replace('/[^A-Za-z0-9._\-]/u', '_', $s);
        return ($out === null || $out === '') ? $fallback : $out;
    }
}
