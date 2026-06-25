<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Bank;

use MyInvoice\Service\Bank\EmailNotice\BankEmailNoticeMessage;
use MyInvoice\Service\Bank\EmailNotice\EmailNoticeTextNormalizer;
use MyInvoice\Service\Bank\EmailNotice\MimeHeaderDecoder;
use MyInvoice\Service\Bank\EmailNotice\Parser\BankEmailNoticeProvider;
use MyInvoice\Service\Bank\EmailNotice\Parser\CsobBankEmailNoticeParser;
use PHPUnit\Framework\TestCase;

final class CsobBankEmailNoticeParserTest extends TestCase
{
    public function testParsesIncomingNoticeWithoutVariableSymbol(): void
    {
        $body = <<<TEXT
Dobrý den,
dne 31.5.2026 byla na účtu 123456789 zaúčtována transakce typu: Příchozí úhrada okamžitá.

Parametry platby
Účet
123456789/0300
Účet protistrany
1002201927/2700
Název protistrany
PLÁTCE DEMO
Datum účtování
31.5.2026
Částka
+10 000,00 CZK
Zůstatek na účtu po zaúčtování transakce: +10 000,00 CZK.

Přejeme příjemný den.
Vaše ČSOB
TEXT;

        $parser = new CsobBankEmailNoticeParser();
        $message = $this->message($body);
        $provider = $this->provider($parser);

        self::assertTrue($parser->supports($message, $provider));

        $parsed = $parser->parse($message, $provider);

        self::assertSame('', $parsed->variableSymbol);
        self::assertSame(10000.0, $parsed->amount);
        self::assertSame('CZK', $parsed->currency);
        self::assertSame('2026-05-31', $parsed->postedAt);
        self::assertSame('123456789/0300', $parsed->recipientAccount);
        self::assertSame('1002201927', $parsed->counterpartyAccount);
        self::assertSame('2700', $parsed->counterpartyBank);
        self::assertSame('PLÁTCE DEMO', $parsed->counterpartyName);
    }

    public function testParsesOutgoingNoticeWithSymbols(): void
    {
        $body = <<<TEXT
Dobrý den,
dne 31.5.2026 byla na účtu 123456789 zaúčtována transakce typu: Odchozí úhrada okamžitá.

Parametry platby
Účet
123456789/0300
Účet protistrany
35-4544230267/0100
Datum účtování
31.5.2026
Částka
-10 000,00 CZK
Variabilní symbol
0009002567593
Konstantní symbol
1120
Zůstatek na účtu po zaúčtování transakce: +0,00 CZK.

Přejeme příjemný den.
Vaše ČSOB
TEXT;

        $parser = new CsobBankEmailNoticeParser();
        $parsed = $parser->parse($this->message($body), $this->provider($parser));

        self::assertSame('9002567593', $parsed->variableSymbol);
        self::assertSame(-10000.0, $parsed->amount);
        self::assertSame('35-4544230267', $parsed->counterpartyAccount);
        self::assertSame('0100', $parsed->counterpartyBank);
        self::assertSame('1120', $parsed->constantSymbol);
    }

    /**
     * issue #58: ČSOB avízo doručené ve windows-1250 (přeposlané přes jiný
     * server). Dřív zmizela diakritika („Avízo"→„Avzo", „Částka"→„stka") a
     * parser e-mail nepoznal → parse_failed. Po opravě kódování projde celá
     * cesta přes EmailNoticeTextNormalizer + MimeHeaderDecoder jako u UTF-8.
     */
    public function testDetectsAndParsesWindows1250Notice(): void
    {
        $utf8Body = <<<TEXT
        Dobrý den,
        dne 31.5.2026 byla na účtu 123456789 zaúčtována transakce typu: Příchozí úhrada okamžitá.

        Parametry platby
        Účet
        123456789/0300
        Účet protistrany
        1002201927/2700
        Název protistrany
        PLÁTCE DEMO
        Datum účtování
        31.5.2026
        Částka
        +10 000,00 CZK

        Přejeme příjemný den.
        Vaše ČSOB
        TEXT;

        $cp1250Body = iconv('UTF-8', 'WINDOWS-1250', $utf8Body);
        $cp1250Subject = iconv('UTF-8', 'WINDOWS-1250', 'Moje info - Avízo');
        $cp1250Sender = iconv('UTF-8', 'WINDOWS-1250', 'ČSOB') . ' <noreply@csob.cz>';
        self::assertIsString($cp1250Body);
        self::assertIsString($cp1250Subject);

        $normalizer = new EmailNoticeTextNormalizer();
        $message = new BankEmailNoticeMessage(
            uid: 1,
            messageId: '<sanitized-cp1250@csob.cz>',
            date: new \DateTimeImmutable('2026-05-31 10:00:00'),
            sender: MimeHeaderDecoder::decode($cp1250Sender),
            subject: MimeHeaderDecoder::decode($cp1250Subject),
            text: $normalizer->normalize($cp1250Body),
            raw: $cp1250Body,
        );

        $parser = new CsobBankEmailNoticeParser();
        $provider = $this->provider($parser);

        self::assertSame('Moje info - Avízo', $message->subject);
        self::assertTrue($parser->supports($message, $provider));

        $parsed = $parser->parse($message, $provider);
        self::assertSame('123456789/0300', $parsed->recipientAccount);
        self::assertSame(10000.0, $parsed->amount);
        self::assertSame('CZK', $parsed->currency);
        self::assertSame('PLÁTCE DEMO', $parsed->counterpartyName);
    }

    public function testRejectsSpoofedSenderDomain(): void
    {
        $parser = new CsobBankEmailNoticeParser();
        $message = new BankEmailNoticeMessage(
            uid: 1,
            messageId: '<spoof@evil.com>',
            date: new \DateTimeImmutable('2026-05-31 10:00:00'),
            sender: 'ČSOB <attacker@csob.cz.evil.com>',
            subject: 'Moje info - Avízo',
            text: 'Parametry platby Částka Vaše ČSOB',
            raw: '',
        );
        self::assertFalse($parser->supports($message, $this->provider($parser)));
    }

    private function message(string $body): BankEmailNoticeMessage
    {
        return new BankEmailNoticeMessage(
            uid: 1,
            messageId: '<sanitized-sample@csob.cz>',
            date: new \DateTimeImmutable('2026-05-31 10:00:00'),
            sender: 'ČSOB <noreply@csob.cz>',
            subject: 'Moje info - Avízo',
            text: $body,
            raw: $body,
        );
    }

    private function provider(CsobBankEmailNoticeParser $parser): BankEmailNoticeProvider
    {
        $provider = $parser->defaultProvider();
        self::assertInstanceOf(BankEmailNoticeProvider::class, $provider);
        return $provider;
    }
}
