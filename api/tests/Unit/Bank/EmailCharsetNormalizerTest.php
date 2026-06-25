<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Bank;

use MyInvoice\Service\Bank\EmailNotice\EmailCharsetNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * issue #58: windows-1250 ČSOB avíza nesmí přijít o diakritiku.
 */
final class EmailCharsetNormalizerTest extends TestCase
{
    public function testTranscodesWindows1250DiacriticsInsteadOfDropping(): void
    {
        // windows-1250: Č=0xC8, á=0xE1, í=0xED — single-byte, v UTF-8 nevalidní.
        self::assertSame('Částka', EmailCharsetNormalizer::toUtf8("\xC8\xE1stka"));
        self::assertSame('Avízo', EmailCharsetNormalizer::toUtf8("Av\xEDzo"));
        self::assertSame('ČSOB', EmailCharsetNormalizer::toUtf8("\xC8SOB"));
        self::assertSame('Účet', EmailCharsetNormalizer::toUtf8("\xDA\xE8et"));
    }

    public function testRoundTripFromUtf8ViaWindows1250(): void
    {
        $utf8 = 'Příchozí úhrada, Vaše ČSOB';
        $cp1250 = iconv('UTF-8', 'WINDOWS-1250', $utf8);
        self::assertIsString($cp1250);
        self::assertFalse(mb_check_encoding($cp1250, 'UTF-8'));
        self::assertSame($utf8, EmailCharsetNormalizer::toUtf8($cp1250));
    }

    public function testLeavesValidUtf8AndAsciiUntouched(): void
    {
        self::assertSame('Částka', EmailCharsetNormalizer::toUtf8('Částka'));
        self::assertSame('plain ascii 123/0300', EmailCharsetNormalizer::toUtf8('plain ascii 123/0300'));
        self::assertSame('', EmailCharsetNormalizer::toUtf8(''));
    }
}
