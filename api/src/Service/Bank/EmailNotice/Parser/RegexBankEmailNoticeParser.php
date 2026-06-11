<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\EmailNotice\Parser;

use MyInvoice\Service\Bank\EmailNotice\BankEmailNoticeMessage;
use MyInvoice\Service\Bank\EmailNotice\ParsedBankEmailNotice;

final class RegexBankEmailNoticeParser extends AbstractBankEmailNoticeParser
{
    public function key(): string
    {
        return 'regex';
    }

    public function defaultProvider(): ?BankEmailNoticeProvider
    {
        return null;
    }

    protected function parserLabel(): string
    {
        return 'Regex';
    }

    public function supports(BankEmailNoticeMessage $message, BankEmailNoticeProvider $provider): bool
    {
        if (!$this->senderAllowed($message->sender, (string) ($provider->senderWhitelist ?? ''))) {
            return false;
        }
        $subjectPattern = trim((string) ($provider->subjectPattern ?? ''));
        if ($subjectPattern !== '' && !preg_match('~' . $subjectPattern . '~iu', $message->subject)) {
            return false;
        }
        $bodyPattern = trim((string) ($provider->bodyPattern ?? ''));
        if ($bodyPattern !== '' && !preg_match('~' . $bodyPattern . '~iu', $this->normalizeText($message->text))) {
            return false;
        }
        return true;
    }

    public function parse(BankEmailNoticeMessage $message, BankEmailNoticeProvider $provider): ParsedBankEmailNotice
    {
        $text = $this->normalizeText($message->text);
        $patterns = $provider->fieldPatterns;

        $data = [];
        foreach ($patterns as $field => $pattern) {
            if (!is_string($pattern) || trim($pattern) === '') {
                continue;
            }
            if (preg_match('~' . $pattern . '~u', $text, $m) !== 1) {
                continue;
            }
            foreach ($m as $key => $value) {
                if (is_string($key)) {
                    $data[$key] = trim((string) $value);
                }
            }
            if (!isset($data[$field]) && isset($m[1])) {
                $data[$field] = trim((string) $m[1]);
            }
        }

        // Některé banky (např. Česká spořitelna) datum platby v těle avíza neuvádějí —
        // jako fallback použij datum doručení e-mailu, ať povinné pole nechybí.
        if (trim((string) ($data['posted_at'] ?? '')) === '' && $message->date instanceof \DateTimeImmutable) {
            $data['posted_at'] = $message->date->format('d.m.Y H:i');
        }

        // #110: šablona ČS „Odešla platba" nemusí obsahovat řádek „Číslo účtu:" —
        // jako fallback vytáhni vlastní účet z úvodní věty („z účtu NÁZEV 123/0800 právě
        // odešla platba…" / „na účet NÁZEV 123/0800 právě dorazila platba…").
        if (trim((string) ($data['recipient_account'] ?? '')) === ''
            && preg_match('/(?:z\s+účtu|na\s+účet)\s+[^\n]{0,120}?(?<value>\d[\d\-]*\/\d{4})/iu', $text, $m) === 1
        ) {
            $data['recipient_account'] = trim($m['value']);
        }

        foreach (['variable_symbol', 'amount', 'currency', 'posted_at', 'recipient_account'] as $required) {
            if (trim((string) ($data[$required] ?? '')) === '') {
                throw new \RuntimeException("Parser nenašel povinné pole {$required}.");
            }
        }

        [$cpAccount, $cpBank] = $this->splitAccount((string) ($data['counterparty_account'] ?? ''));
        $postedAt = $this->parseDate((string) $data['posted_at']);

        return new ParsedBankEmailNotice(
            variableSymbol: $this->digitsOnly((string) $data['variable_symbol']),
            amount: $this->applyDirection($this->parseAmount((string) $data['amount']), (string) ($data['direction'] ?? '')),
            currency: $this->normalizeCurrency((string) $data['currency']),
            postedAt: $postedAt,
            recipientAccount: $this->normalizeAccount((string) $data['recipient_account']),
            counterpartyAccount: $cpAccount,
            counterpartyBank: $cpBank,
            counterpartyName: $this->cleanNullable((string) ($data['counterparty_name'] ?? '')),
            constantSymbol: $this->cleanNullable((string) ($data['constant_symbol'] ?? '')),
            message: $this->cleanNullable((string) ($data['message'] ?? '')),
            bankRef: $this->cleanNullable((string) ($data['bank_ref'] ?? '')),
        );
    }
}
