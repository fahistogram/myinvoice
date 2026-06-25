<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Bank;

use MyInvoice\Service\Bank\EmailNotice\MimeHeaderDecoder;
use PHPUnit\Framework\TestCase;

/**
 * issue #58: hlavičky avíz (Subject/From) musí zachovat diakritiku bez ohledu
 * na to, zda dorazí jako RFC 2047 encoded-word nebo jako raw 8-bit (windows-1250).
 */
final class MimeHeaderDecoderTest extends TestCase
{
    public function testKeepsValidUtf8LiteralHeader(): void
    {
        // Dřív: iconv_mime_decode literal text bral jako us-ascii → „SOB"/„Avzo".
        self::assertSame('ČSOB <noreply@csob.cz>', MimeHeaderDecoder::decode('ČSOB <noreply@csob.cz>'));
        self::assertSame('Moje info - Avízo', MimeHeaderDecoder::decode('Moje info - Avízo'));
    }

    public function testRecoversRawWindows1250Header(): void
    {
        $rawSubject = iconv('UTF-8', 'WINDOWS-1250', 'Moje info - Avízo');
        $rawSender = iconv('UTF-8', 'WINDOWS-1250', 'ČSOB') . ' <noreply@csob.cz>';
        self::assertIsString($rawSubject);

        self::assertSame('Moje info - Avízo', MimeHeaderDecoder::decode($rawSubject));
        self::assertSame('ČSOB <noreply@csob.cz>', MimeHeaderDecoder::decode($rawSender));
    }

    public function testDecodesWindows1250EncodedWord(): void
    {
        // =?windows-1250?Q?Moje_info_-_Av=EDzo?=  (í = 0xED)
        $encoded = '=?windows-1250?Q?Moje_info_-_Av=EDzo?=';
        self::assertSame('Moje info - Avízo', MimeHeaderDecoder::decode($encoded));
    }

    public function testDecodesUtf8EncodedWord(): void
    {
        $encoded = '=?utf-8?B?' . base64_encode('Moje info - Avízo') . '?=';
        self::assertSame('Moje info - Avízo', MimeHeaderDecoder::decode($encoded));
    }

    public function testEmptyHeader(): void
    {
        self::assertSame('', MimeHeaderDecoder::decode(''));
        self::assertSame('', MimeHeaderDecoder::decode('   '));
    }
}
