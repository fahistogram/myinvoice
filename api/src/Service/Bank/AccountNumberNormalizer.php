<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank;

/**
 * Normalizace bankovního účtu pro porovnávání mezi:
 *  - GPC výpisem (zero-padded 16 cifer, např. `0000000123456789`)
 *  - currencies.account_number (uložené bez padding, např. `123456789`)
 *  - CZ účty s prefixem (`19-2000145399` → `192000145399`)
 *
 * Strip non-digits + ltrim '0'. Po normalize se dva různé zápisy stejného účtu
 * shodují.
 *
 * Pozn.: ztrácíme tím rozlišení účtů, které se liší pouze prefixem (např.
 * `19-1000000005` vs. `1000000005` budou normalizované shodné). To je v praxi
 * OK — žádný důstojný účet nemá takovou kolizi.
 */
final class AccountNumberNormalizer
{
    public static function normalize(string $accountNumber): string
    {
        $digitsOnly = preg_replace('/\D/', '', $accountNumber) ?? '';
        return ltrim($digitsOnly, '0');
    }

    /** True pokud dvě account number stringy odkazují na stejný účet (po normalize). */
    public static function equals(string $a, string $b): bool
    {
        return self::normalize($a) === self::normalize($b);
    }

    /**
     * Domácí část (předčíslí+číslo, 16 cifer) z českého IBANu — porovnatelná
     * s GPC account_number. Vrací NULL, pokud vstup není validně tvarovaný CZ IBAN.
     *
     * CZ IBAN: CZkk BBBB PPPPPP NNNNNNNNNN (kontrolní 2, banka 4, předčíslí 6, číslo 10).
     * Pozn.: kontrolní číslice neověřujeme — vstup je vlastní uložený účet, ne user input.
     */
    public static function czechIbanAccountPart(string $iban): ?string
    {
        $compact = strtoupper((string) preg_replace('/\s+/', '', $iban));
        if (preg_match('/^CZ\d{2}\d{4}(\d{6}\d{10})$/', $compact, $m) !== 1) {
            return null;
        }
        return $m[1];
    }

    /** Kód banky (4 cifry) z českého IBANu, NULL pokud vstup není CZ IBAN. */
    public static function czechIbanBankCode(string $iban): ?string
    {
        $compact = strtoupper((string) preg_replace('/\s+/', '', $iban));
        if (preg_match('/^CZ\d{2}(\d{4})\d{16}$/', $compact, $m) !== 1) {
            return null;
        }
        return $m[1];
    }

    /**
     * True pokud účet z výpisu odpovídá uloženému účtu — buď přes `account_number`,
     * nebo přes domácí část `iban` (issue #109: EUR účty bývají evidované jen IBANem,
     * GPC ale nese domácí číslo účtu → bez tohohle se EUR výpis nikdy nespároval).
     */
    public static function matchesAny(string $statementAccount, ?string $accountNumber, ?string $iban = null): bool
    {
        if (is_string($accountNumber) && trim($accountNumber) !== '') {
            if (self::equals($accountNumber, $statementAccount)) {
                return true;
            }
            // Defenzivně: IBAN vepsaný do pole account_number porovnej přes domácí část.
            $part = self::czechIbanAccountPart($accountNumber);
            if ($part !== null && self::equals($part, $statementAccount)) {
                return true;
            }
        }
        $ibanPart = is_string($iban) ? self::czechIbanAccountPart($iban) : null;
        return $ibanPart !== null && self::equals($ibanPart, $statementAccount);
    }
}
