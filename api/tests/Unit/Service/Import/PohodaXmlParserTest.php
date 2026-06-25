<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Import;

use MyInvoice\Service\Import\PohodaXmlParser;
use PHPUnit\Framework\TestCase;

final class PohodaXmlParserTest extends TestCase
{
    private PohodaXmlParser $parser;

    protected function setUp(): void
    {
        $this->parser = new PohodaXmlParser();
    }

    private function minimalPohoda(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<dat:dataPack xmlns:dat="http://www.stormware.cz/schema/version_2/data.xsd"
              xmlns:inv="http://www.stormware.cz/schema/version_2/invoice.xsd"
              xmlns:typ="http://www.stormware.cz/schema/version_2/type.xsd"
              ico="21370362" version="2.0">
  <dat:dataPackItem id="i1">
    <inv:invoice>
      <inv:invoiceHeader>
        <inv:invoiceType>issuedInvoice</inv:invoiceType>
        <inv:symVar>2605002</inv:symVar>
        <inv:date>2026-05-02</inv:date>
        <inv:dateTax>2026-05-02</inv:dateTax>
        <inv:dateDue>2026-05-16</inv:dateDue>
        <inv:text>Faktura test</inv:text>
        <inv:partnerIdentity>
          <typ:address>
            <typ:company>Klient X s.r.o.</typ:company>
            <typ:ico>12345678</typ:ico>
            <typ:email>klient@example.com</typ:email>
          </typ:address>
        </inv:partnerIdentity>
      </inv:invoiceHeader>
      <inv:invoiceDetail>
        <inv:invoiceItem>
          <inv:text>Programování</inv:text>
          <inv:quantity>5</inv:quantity>
          <inv:unit>hod</inv:unit>
          <inv:rateVAT>high</inv:rateVAT>
          <inv:homeCurrency>
            <typ:unitPrice>2000</typ:unitPrice>
          </inv:homeCurrency>
        </inv:invoiceItem>
      </inv:invoiceDetail>
    </inv:invoice>
  </dat:dataPackItem>
</dat:dataPack>
XML;
    }

    public function testHappyPath(): void
    {
        $result = $this->parser->parse($this->minimalPohoda());
        self::assertSame('21370362', $result['supplier_ic']);
        self::assertCount(1, $result['invoices']);

        $inv = $result['invoices'][0];
        self::assertSame('invoice', $inv['invoice_type']);
        self::assertSame('2605002', $inv['varsymbol']);
        self::assertSame('2026-05-02', $inv['issue_date']);
        self::assertSame('2026-05-16', $inv['due_date']);
        self::assertSame('CZK', $inv['currency']);
        self::assertNull($inv['exchange_rate']);
        self::assertSame('Klient X s.r.o.', $inv['client']['company_name']);
        self::assertSame('klient@example.com', $inv['client']['email']);
        self::assertCount(1, $inv['items']);
        self::assertSame(2000.0, $inv['items'][0]['unit_price_without_vat']);
        self::assertSame(21.0, $inv['items'][0]['vat_rate']);
        self::assertSame(5.0, $inv['items'][0]['quantity']);
    }

    /**
     * Uživatelský export z Pohody (VydFaktury.xml) — root je `responsePack`
     * s `listInvoice` a fakturami v `lst:invoice`, hlavička dál v `inv:`.
     */
    private function responsePackExport(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rsp:responsePack version="2.0" id="Usr01" state="ok" ico="05687691"
        xmlns:rsp="http://www.stormware.cz/schema/version_2/response.xsd"
        xmlns:typ="http://www.stormware.cz/schema/version_2/type.xsd"
        xmlns:lst="http://www.stormware.cz/schema/version_2/list.xsd"
        xmlns:inv="http://www.stormware.cz/schema/version_2/invoice.xsd">
  <rsp:responsePackItem version="2.0" id="Usr01" state="ok">
    <lst:listInvoice version="2.0" state="ok">
      <lst:invoice version="2.0">
        <inv:invoiceHeader>
          <inv:invoiceType>issuedInvoice</inv:invoiceType>
          <inv:number><typ:numberRequested>26FV001</typ:numberRequested></inv:number>
          <inv:symVar>26001</inv:symVar>
          <inv:date>2026-01-04</inv:date>
          <inv:dateTax>2026-01-04</inv:dateTax>
          <inv:dateDue>2026-01-18</inv:dateDue>
          <inv:classificationVAT><typ:id>165</typ:id><typ:ids>UD</typ:ids></inv:classificationVAT>
          <inv:text>Fakturujeme Vam zbozi:</inv:text>
          <inv:partnerIdentity>
            <typ:address>
              <typ:company>AR SERVIS s.r.o.</typ:company>
              <typ:city>Jindrichuv Hradec</typ:city>
              <typ:ico>42408393</typ:ico>
              <typ:dic>CZ42408393</typ:dic>
            </typ:address>
          </inv:partnerIdentity>
        </inv:invoiceHeader>
        <inv:invoiceDetail>
          <inv:invoiceItem>
            <inv:text>HDD Western Digital</inv:text>
            <inv:quantity>2.0</inv:quantity>
            <inv:unit>ks</inv:unit>
            <inv:rateVAT value="21">high</inv:rateVAT>
            <inv:homeCurrency>
              <typ:unitPrice>3086</typ:unitPrice>
              <typ:price>6172</typ:price>
              <typ:priceVAT>1296.12</typ:priceVAT>
            </inv:homeCurrency>
          </inv:invoiceItem>
        </inv:invoiceDetail>
        <inv:invoiceSummary>
          <inv:homeCurrency>
            <typ:priceHigh>6172</typ:priceHigh>
            <typ:priceHighVAT rate="21">1296.12</typ:priceHighVAT>
          </inv:homeCurrency>
        </inv:invoiceSummary>
      </lst:invoice>
    </lst:listInvoice>
  </rsp:responsePackItem>
</rsp:responsePack>
XML;
    }

    public function testResponsePackExportParsed(): void
    {
        $result = $this->parser->parse($this->responsePackExport());
        self::assertSame('05687691', $result['supplier_ic']);
        self::assertCount(1, $result['invoices']);

        $inv = $result['invoices'][0];
        self::assertSame('invoice', $inv['invoice_type']);
        self::assertSame('26001', $inv['varsymbol']);
        self::assertSame('2026-01-04', $inv['issue_date']);
        self::assertSame('2026-01-18', $inv['due_date']);
        self::assertFalse($inv['reverse_charge']);
        self::assertSame('AR SERVIS s.r.o.', $inv['client']['company_name']);
        self::assertSame('42408393', $inv['client']['ic']);
        self::assertCount(1, $inv['items']);
        self::assertSame(3086.0, $inv['items'][0]['unit_price_without_vat']);
        self::assertSame(21.0, $inv['items'][0]['vat_rate']);
        self::assertSame(2.0, $inv['items'][0]['quantity']);
        // Rekapitulace DPH z summary (homeCurrency) — high sazba.
        self::assertArrayHasKey('21.00', $inv['vat_recap']);
        self::assertEqualsWithDelta(6172.0, $inv['vat_recap']['21.00']['base'], 0.01);
        self::assertEqualsWithDelta(1296.12, $inv['vat_recap']['21.00']['vat'], 0.01);
    }

    public function testProformaTypeMapping(): void
    {
        $xml = str_replace(
            '<inv:invoiceType>issuedInvoice</inv:invoiceType>',
            '<inv:invoiceType>issuedAdvanceInvoice</inv:invoiceType>',
            $this->minimalPohoda()
        );
        $result = $this->parser->parse($xml);
        self::assertSame('proforma', $result['invoices'][0]['invoice_type']);
    }

    public function testCreditNoteMapping(): void
    {
        $xml = str_replace(
            '<inv:invoiceType>issuedInvoice</inv:invoiceType>',
            '<inv:invoiceType>issuedCreditNotice</inv:invoiceType>',
            $this->minimalPohoda()
        );
        $result = $this->parser->parse($xml);
        self::assertSame('credit_note', $result['invoices'][0]['invoice_type']);
    }

    public function testRejectsDoctype(): void
    {
        $xml = <<<'XML'
<?xml version="1.0"?>
<!DOCTYPE pwn [<!ENTITY x SYSTEM "file:///etc/passwd">]>
<dat:dataPack xmlns:dat="http://www.stormware.cz/schema/version_2/data.xsd"
              ico="21370362" version="2.0"/>
XML;
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/DOCTYPE/i');
        $this->parser->parse($xml);
    }

    public function testRejectsNonDataPackRoot(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->parser->parse('<?xml version="1.0"?><foo/>');
    }

    public function testMalformedXmlThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->parser->parse('not really xml');
    }

    public function testForeignCurrencyDetected(): void
    {
        $xml = str_replace(
            '</inv:invoiceDetail>',
            '</inv:invoiceDetail>
      <inv:invoiceSummary>
        <inv:foreignCurrency>
          <typ:currency><typ:ids>EUR</typ:ids></typ:currency>
          <typ:rate>24.36</typ:rate>
        </inv:foreignCurrency>
      </inv:invoiceSummary>',
            $this->minimalPohoda()
        );
        $result = $this->parser->parse($xml);
        self::assertSame('EUR', $result['invoices'][0]['currency']);
        self::assertEqualsWithDelta(24.36, $result['invoices'][0]['exchange_rate'], 1e-6);
    }
}
