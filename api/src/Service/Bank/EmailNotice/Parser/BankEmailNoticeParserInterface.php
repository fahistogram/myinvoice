<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\EmailNotice\Parser;

use MyInvoice\Service\Bank\EmailNotice\BankEmailNoticeMessage;
use MyInvoice\Service\Bank\EmailNotice\ParsedBankEmailNotice;

interface BankEmailNoticeParserInterface
{
    /**
     * Stabilní parser_type klíč používaný v provider konfiguraci a logu.
     */
    public function key(): string;

    /**
     * Volitelný systémový provider dodaný parserem bez DB řádku.
     *
     * Regex/custom parsery vrací null, protože jejich konfigurace žije v DB/UI.
     */
    public function defaultProvider(): ?BankEmailNoticeProvider;

    public function supports(BankEmailNoticeMessage $message, BankEmailNoticeProvider $provider): bool;

    public function parse(BankEmailNoticeMessage $message, BankEmailNoticeProvider $provider): ParsedBankEmailNotice;
}
