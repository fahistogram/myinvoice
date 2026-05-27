<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Mail;

use MyInvoice\Service\Mail\Mailer;
use PHPUnit\Framework\TestCase;

/**
 * Regression for issue #51 — QR platba se v odeslaných emailech nezobrazovala.
 * Generátor vrací `data:image/png;base64,…` URI a šablony ho dávaly přímo do
 * `<img src>`. Gmail/Outlook ale `data:` URI v obrázcích blokují. Mailer teď
 * data URI dekóduje a embeduje jako inline CID image (`cid:qr_payment`).
 *
 * Test invokuje privátní `decodeDataUri()` přes reflexi (bez DB/SMTP) — to je
 * jádro fixu: korektní rozparsování data URI na bytes + content type.
 */
final class MailerQrEmbedTest extends TestCase
{
    private function decode(string $uri): ?array
    {
        $mailer = (new \ReflectionClass(Mailer::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(Mailer::class, 'decodeDataUri');
        return $method->invoke($mailer, $uri);
    }

    public function testDecodesPngDataUri(): void
    {
        $bytes = "\x89PNG\r\n\x1a\nfake-png-bytes";
        $uri = 'data:image/png;base64,' . base64_encode($bytes);

        $result = $this->decode($uri);

        self::assertNotNull($result);
        self::assertSame('image/png', $result['contentType']);
        self::assertSame($bytes, $result['bytes']);
    }

    public function testReturnsNullForPlainUrl(): void
    {
        self::assertNull($this->decode('https://example.com/qr.png'));
        self::assertNull($this->decode('cid:qr_payment'));
    }

    public function testReturnsNullForNonBase64DataUri(): void
    {
        // SVG data URI bez base64 (url-encoded) — nepodporujeme, vrací null.
        self::assertNull($this->decode('data:image/svg+xml,%3Csvg%3E%3C/svg%3E'));
    }

    public function testReturnsNullForEmptyPayload(): void
    {
        self::assertNull($this->decode('data:image/png;base64,'));
    }
}
