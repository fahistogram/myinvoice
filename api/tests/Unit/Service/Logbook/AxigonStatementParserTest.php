<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Logbook;

use MyInvoice\Service\Logbook\Fuel\AxigonStatementParser;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Parser detailních výpisů Axigon — oba formáty (novější „po řádcích", starší
 * „zhuštěný jednořádkový"). Testuje se přes seam parseRowsFromText() s VYMYŠLENÝM
 * textem (žádná reálná zákaznická data dle pravidla feedback_test_data_local_only —
 * částky, účtenky i místa jsou syntetická).
 */
final class AxigonStatementParserTest extends TestCase
{
    private function parser(): AxigonStatementParser
    {
        return new AxigonStatementParser(new NullLogger());
    }

    public function testNewerFormatSingleRow(): void
    {
        // Novější formát: každá buňka na vlastním řádku, transakce zahájena kódem země.
        // Hodnoty jsou syntetické (DPH 21 % z 1000 = 210, celkem 1210).
        $text = <<<TXT
        Číslo karty: 7000000000000000000
        ID karty: TestNet
        30,00
        1 000,00 Kč
        210,00 Kč
        1 210,00 Kč
        CZ
        15.01.2026 10:00:00
        100100
        Prémiová nafta
        Testov, Hlavní
        TestNet/Stanice
        30,00
        21,00%
        35,00 Kč
        33,33 Kč
        1 000,00 Kč
        210,00 Kč
        1 210,00 Kč
        CELKEM:
        TXT;

        $rows = $this->parser()->parseRowsFromText($text);
        self::assertCount(1, $rows);
        $r = $rows[0];
        self::assertSame('2026-01-15', $r['fueled_date']);
        self::assertSame('10:00', $r['fueled_time']);
        self::assertSame('Prémiová nafta', $r['fuel_type']);
        self::assertEqualsWithDelta(30.00, $r['quantity'], 0.001);
        self::assertEqualsWithDelta(1210.00, $r['amount_with_vat'], 0.001);
        self::assertEqualsWithDelta(1000.00, $r['amount_without_vat'], 0.001);
        self::assertTrue($r['is_fuel']);
    }

    public function testOlderConcatenatedFormatReconciles(): void
    {
        // Starší formát: celý řádek na jedné řádce, základ a celkem odděleny mezerou.
        // Syntetické částky: 500→605 a 800→968 (DPH 21 %), součet 1573.
        $text = "CZ 15.01.26 09:00 100100Orlen500,00 605,00 Testov105,0021,035,0035,0014,29Diesel plus\n"
              . "CZ 16.01.26 10:00 100200Orlen800,00 968,00 Testov168,0021,035,0035,0022,86Diesel plus\n"
              . "CELKEM\n";

        $rows = $this->parser()->parseRowsFromText($text);
        self::assertCount(2, $rows);

        self::assertSame('2026-01-15', $rows[0]['fueled_date']);
        self::assertSame('09:00', $rows[0]['fueled_time']);
        self::assertEqualsWithDelta(605.00, $rows[0]['amount_with_vat'], 0.001);
        self::assertSame('Diesel plus', $rows[0]['fuel_type']);
        self::assertTrue($rows[0]['is_fuel']);
        // Množství je u staršího formátu nespolehlivé → null (poctivě), ne nesmysl.
        self::assertNull($rows[0]['quantity']);

        self::assertEqualsWithDelta(968.00, $rows[1]['amount_with_vat'], 0.001);

        // Self-check: součet celkem řádků.
        $sum = $rows[0]['amount_with_vat'] + $rows[1]['amount_with_vat'];
        self::assertEqualsWithDelta(1573.00, $sum, 0.01);
    }

    public function testNonFuelRowIsFlaggedNotFuel(): void
    {
        $text = <<<TXT
        CZ
        20.01.2026 14:00:00
        100300
        Mytí vozu
        Testov, Hlavní
        TestNet/Stanice
        1,00
        21,00%
        200,00 Kč
        200,00 Kč
        200,00 Kč
        42,00 Kč
        242,00 Kč
        CELKEM:
        TXT;

        $rows = $this->parser()->parseRowsFromText($text);
        self::assertCount(1, $rows);
        self::assertSame('Mytí vozu', $rows[0]['fuel_type']);
        self::assertFalse($rows[0]['is_fuel']);
    }

    public function testSupportsDetectsAxigonByIcAndName(): void
    {
        // IČ 64949320 = veřejný identifikátor dodavatele Axigon a.s. (detekce v parseru),
        // není to zákaznický údaj.
        $p = $this->parser();
        self::assertTrue($p->supports(['vendor_ic' => '64949320']));
        self::assertTrue($p->supports(['vendor_company_name' => 'AXIGON a.s.']));
        self::assertFalse($p->supports(['vendor_ic' => '12345678', 'vendor_company_name' => 'Jiná benzínka s.r.o.']));
        self::assertTrue(AxigonStatementParser::isAxigonVendor(['vendor_ic' => '649 493 20']));
    }
}
