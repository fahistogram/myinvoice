<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Bank;

use MyInvoice\Service\Bank\EmailNotice\BankEmailNoticeMessage;
use MyInvoice\Service\Bank\EmailNotice\Parser\BankEmailNoticeProvider;
use MyInvoice\Service\Bank\EmailNotice\Parser\UnicreditBankEmailNoticeParser;
use PHPUnit\Framework\TestCase;

final class UnicreditBankEmailNoticeParserTest extends TestCase
{
    public function testParsesSanitizedQuotedPrintableHtmlNotice(): void
    {
        $html = <<<HTML
<html>Dobrý den,<br/>
na Vašem účtu č. 123456789 (CZK) Test právě proběhla transakce s následujícími údaji:<br/>
<br/>
Částka: 20,546.00 CZK<br/>
Číslo účtu protistrany: 0-987654321/0600<br/>
Název účtu protistrany: PLÁTCE DEMO s.r.o.<br/>
Variabilní symbol: 0002026017<br/>
Specifický symbol: <br/>
Detaily transakce: Faktura 2026017<br/>
Datum: 25.05.2026 13:02:31<br/>
<br/>
S pozdravem,<br/>
Vaše UniCredit Bank<br/>
</html>
HTML;
        $raw = "Content-Type: text/html; charset=utf-8\n"
            . "Content-Transfer-Encoding: quoted-printable\n\n"
            . quoted_printable_encode($html);
        $message = new BankEmailNoticeMessage(
            uid: 1,
            messageId: '<sanitized-sample@unicreditgroup.cz>',
            date: new \DateTimeImmutable('2026-05-25 13:02:54'),
            sender: 'UniCredit Bank <unicreditbank@unicreditgroup.cz>',
            subject: 'Informace_o_pohybu_na_účtu',
            text: $raw,
            raw: $raw,
        );

        $parser = new UnicreditBankEmailNoticeParser();
        $provider = $parser->defaultProvider();
        self::assertInstanceOf(BankEmailNoticeProvider::class, $provider);
        self::assertTrue($parser->supports($message, $provider));

        $parsed = $parser->parse($message, $provider);

        self::assertSame('2026017', $parsed->variableSymbol);
        self::assertSame(20546.0, $parsed->amount);
        self::assertSame('CZK', $parsed->currency);
        self::assertSame('2026-05-25', $parsed->postedAt);
        self::assertSame('123456789', $parsed->recipientAccount);
        self::assertSame('0-987654321', $parsed->counterpartyAccount);
        self::assertSame('0600', $parsed->counterpartyBank);
        self::assertSame('PLÁTCE DEMO s.r.o.', $parsed->counterpartyName);
        self::assertSame('Faktura 2026017', $parsed->message);
    }

    public function testRejectsSpoofedSenderDomain(): void
    {
        $parser = new UnicreditBankEmailNoticeParser();
        $provider = $parser->defaultProvider();
        self::assertInstanceOf(BankEmailNoticeProvider::class, $provider);

        $message = new BankEmailNoticeMessage(
            uid: 1,
            messageId: '<spoof@evil.com>',
            date: new \DateTimeImmutable('2026-05-25 13:02:54'),
            sender: 'UniCredit Bank <attacker@unicreditgroup.cz.evil.com>',
            subject: 'Informace o pohybu na účtu',
            text: 'Variabilní symbol: 1 Číslo účtu protistrany: 1/0100 UniCredit Bank',
            raw: '',
        );
        self::assertFalse($parser->supports($message, $provider));
    }
}
