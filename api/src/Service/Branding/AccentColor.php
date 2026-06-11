<?php

declare(strict_types=1);

namespace MyInvoice\Service\Branding;

/**
 * Pomocník pro odvozování světlých variant brandingového akcentu — sdílí ho
 * PDF renderer (InvoicePdfRenderer::brandAccentCss) i email buildery
 * (InvoiceEmailVarsBuilder, Mailer, EmailBrandingAction preview).
 *
 * Branding přebarvuje nejen popředí (texty, hlavičky), ale i světlé plochy a
 * tenké linky, které jsou v base stylu napevno odvozené od defaultní fialové
 * (#3B2D83). `tint()` je smíchá s bílou v daném poměru, aby odpovídaly
 * zvolenému akcentu.
 */
final class AccentColor
{
    /** Defaultní MyInvoice fialová — pro ni branding negeneruje override (je už v base). */
    public const DEFAULT = '#3B2D83';

    /** Normalizuje a zvaliduje hex; vrací uppercase `#RRGGBB` nebo null. */
    public static function normalize(?string $hex): ?string
    {
        $hex = strtoupper(trim((string) $hex));
        return preg_match('/^#[0-9A-F]{6}$/', $hex) === 1 ? $hex : null;
    }

    /**
     * Smíchá akcent s bílou. `$accentRatio` 0..1 = podíl akcentu
     * (0 = bílá, 1 = čistý akcent). Vrací `#RRGGBB`, nebo null pro nevalidní hex.
     */
    public static function tint(string $hex, float $accentRatio): ?string
    {
        $hex = self::normalize($hex);
        if ($hex === null) {
            return null;
        }
        $ratio = max(0.0, min(1.0, $accentRatio));
        $mix = static fn (int $c): int => (int) round(255 - (255 - $c) * $ratio);

        return sprintf(
            '#%02X%02X%02X',
            $mix((int) hexdec(substr($hex, 1, 2))),
            $mix((int) hexdec(substr($hex, 3, 2))),
            $mix((int) hexdec(substr($hex, 5, 2))),
        );
    }

    /**
     * Světlá varianta akcentu pro pozadí emailu (gradient hlavičky + boxy s částkou).
     * Vrací tint jen pro zapnutý branding s nedefaultní validní barvou — jinak null,
     * aby šablona spadla zpět na fixní #F4F2FB. Gating sjednocen pro všechny buildery.
     */
    public static function emailBackground(bool $brandingEnabled, ?string $hex): ?string
    {
        if (!$brandingEnabled) {
            return null;
        }
        $hex = self::normalize($hex);
        if ($hex === null || $hex === self::DEFAULT) {
            return null;
        }
        return self::tint($hex, 0.08);
    }
}
