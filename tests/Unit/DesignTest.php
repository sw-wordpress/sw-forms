<?php

declare(strict_types=1);

namespace SWForms\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SWForms\Core\Design\Color;
use SWForms\Core\Design\Design;

final class DesignTest extends TestCase
{
    public function testHexColoursAreNormalisedAndEverythingElseRejected(): void
    {
        self::assertSame('#aabbcc', Color::hex('#ABC'));
        self::assertSame('#12ab9f', Color::hex(' #12AB9F '));
        foreach (['red', '#12345', '#1234567', 'url(x)', '#fff;}', '', null, 5, ['#fff']] as $invalid) {
            self::assertNull(Color::hex($invalid));
        }
    }

    public function testContrastFollowsWcag(): void
    {
        self::assertEqualsWithDelta(21.0, Color::contrast('#000000', '#ffffff'), 0.001);
        self::assertEqualsWithDelta(1.0, Color::contrast('#777777', '#777777'), 0.001);
        self::assertEqualsWithDelta(5.17, Color::contrast('#2563eb', '#ffffff'), 0.01);
        self::assertSame(Color::LIGHT_TEXT, Color::readableOn('#2563eb'));
        self::assertSame(Color::DARK_TEXT, Color::readableOn('#fde047'));
    }

    public function testNormalizeDefaultsToTheClassicLook(): void
    {
        $expected = ['variant' => 'classic', 'scheme' => 'light', 'tokens' => []];
        self::assertSame($expected, Design::normalize(null));
        self::assertSame($expected, Design::normalize(['variant' => 'neon', 'scheme' => 'sepia', 'tokens' => 'x']));
    }

    public function testNormalizeKeepsOnlyValidTokens(): void
    {
        $design = Design::normalize([
            'variant' => 'animated-float',
            'scheme' => 'auto',
            'tokens' => ['accent' => '#F0A', 'text' => 'red', 'border' => '#334455', 'unknown' => '#000', 'radius' => '99'],
        ]);
        self::assertSame('animated-float', $design['variant']);
        self::assertSame('auto', $design['scheme']);
        self::assertSame(['accent' => '#ff00aa', 'border' => '#334455', 'radius' => 32], $design['tokens']);
        self::assertSame(0, Design::normalize(['tokens' => ['radius' => -4]])['tokens']['radius'] ?? null);
        self::assertArrayNotHasKey('radius', Design::normalize(['tokens' => ['radius' => '1px']])['tokens']);
    }

    public function testEveryVariantIsKnownAndFloatingIsLimitedToLabelVariants(): void
    {
        self::assertCount(7, Design::VARIANTS);
        foreach (Design::VARIANTS as $variant) {
            self::assertSame($variant, Design::variant($variant));
            self::assertSame(in_array($variant, ['floating', 'animated-float'], true), Design::floatingLabels($variant));
        }
        self::assertNull(Design::variant('Animated-Float'));
        self::assertNull(Design::variant(1));
    }

    public function testClassesAndInlineStyleAreSafe(): void
    {
        $design = Design::normalize(['variant' => 'minimal', 'scheme' => 'dark', 'tokens' => ['accent' => '#fde047', 'surface' => '#101010', 'radius' => 4]]);
        self::assertSame(['swf--minimal', 'swf-scheme--dark', 'swf--has-surface'], Design::classes($design));
        self::assertSame(
            '--swf-accent:#fde047;--swf-button-bg:#fde047;--swf-accent-contrast:#111827;--swf-surface:#101010;--swf-radius:4px',
            Design::style($design)
        );
        self::assertSame('', Design::style(Design::normalize([])));
        // Unnormalised input never reaches the style attribute.
        self::assertSame('', Design::style(['variant' => 'classic', 'scheme' => 'light', 'tokens' => ['accent' => 'red;}body{x:y']]));
    }
}
