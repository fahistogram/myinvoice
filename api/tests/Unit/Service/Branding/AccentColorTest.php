<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Branding;

use MyInvoice\Service\Branding\AccentColor;
use PHPUnit\Framework\TestCase;

/**
 * AccentColor odvozuje světlé varianty brandingového akcentu pro PDF i email.
 * Test fixuje tint math + gating, aby se nerozbily světlé plochy/linky faktury.
 */
final class AccentColorTest extends TestCase
{
    public function testNormalizeAcceptsValidHexAndUppercases(): void
    {
        self::assertSame('#1565C0', AccentColor::normalize(' #1565c0 '));
        self::assertSame('#3B2D83', AccentColor::normalize('#3b2d83'));
    }

    public function testNormalizeRejectsInvalid(): void
    {
        self::assertNull(AccentColor::normalize('xyz'));
        self::assertNull(AccentColor::normalize('#123'));
        self::assertNull(AccentColor::normalize('1565C0'));
        self::assertNull(AccentColor::normalize(null));
    }

    public function testTintMixesWithWhite(): void
    {
        // ratio 0 = čistá bílá, ratio 1 = čistý akcent
        self::assertSame('#FFFFFF', AccentColor::tint('#1565C0', 0.0));
        self::assertSame('#1565C0', AccentColor::tint('#1565C0', 1.0));
        // 8% akcentu nad bílou — světlé pozadí (analogie base #EFEAFF/#F4F2F8)
        self::assertSame('#ECF3FA', AccentColor::tint('#1565C0', 0.08));
    }

    public function testTintClampsOutOfRangeRatio(): void
    {
        self::assertSame('#FFFFFF', AccentColor::tint('#1565C0', -1.0));
        self::assertSame('#1565C0', AccentColor::tint('#1565C0', 2.0));
    }

    public function testTintReturnsNullForInvalidHex(): void
    {
        self::assertNull(AccentColor::tint('nope', 0.1));
    }

    public function testEmailBackgroundGating(): void
    {
        // zapnutý branding + nedefaultní validní barva → tint
        self::assertSame('#ECF3FA', AccentColor::emailBackground(true, '#1565C0'));
        // vypnutý branding → null (fallback na fixní #F4F2FB v šabloně)
        self::assertNull(AccentColor::emailBackground(false, '#1565C0'));
        // defaultní fialová → null (base hodnota stačí)
        self::assertNull(AccentColor::emailBackground(true, AccentColor::DEFAULT));
        // nevalidní barva → null
        self::assertNull(AccentColor::emailBackground(true, 'bogus'));
    }
}
