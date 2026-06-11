<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Export;

use MyInvoice\Service\Export\ExportFilename;
use PHPUnit\Framework\TestCase;

final class ExportFilenameTest extends TestCase
{
    public function testTransliteratesCzechDiacritics(): void
    {
        $this->assertSame('Zluty kun', ExportFilename::transliterate('Žlutý kůň'));
        $this->assertSame('Prilis zlutoucky', ExportFilename::transliterate('Příliš žluťoučký'));
    }

    public function testTransliteratesForeignDiacritics(): void
    {
        // Němčina (ß→ss, ö/ü→o/u), Polština (ł→l, ą→a, ż→z)
        $this->assertSame('Grosser Muller', ExportFilename::transliterate('Größer Müller'));
        $this->assertSame('Lodz Zaba', ExportFilename::transliterate('Łódź Żaba'));
    }

    public function testSanitizeKeepsDiacriticsAsAsciiNotUnderscores(): void
    {
        // Dřív se diakritika ztratila do podtržítek ('2025001-Z_lut_ k_ _ sro');
        // teď je z ní čitelné ASCII (mezery zůstávají '_' jako dřív).
        $this->assertSame('2025001-Zluty_kun_sro', ExportFilename::sanitize('2025001-Žlutý kůň sro'));
    }

    public function testSanitizeReplacesTrulyIllegalCharsWithUnderscore(): void
    {
        // Znaky bez transliterace (slash, emoji) → podtržítko (bezpečnost / zip-slip).
        $this->assertSame('a_b_c', ExportFilename::sanitize('a/b\\c'));
        $this->assertSame('faktura_', ExportFilename::sanitize('faktura✓'));
    }

    public function testSanitizePreservesDotsAndDashes(): void
    {
        $this->assertSame('kniha-dph-2026-01.pdf', ExportFilename::sanitize('kniha-dph-2026-01.pdf'));
    }

    public function testSanitizeEmptyFallsBackToDefault(): void
    {
        $this->assertSame('soubor', ExportFilename::sanitize(''));
        $this->assertSame('invoice', ExportFilename::sanitize('', 'invoice'));
    }
}
