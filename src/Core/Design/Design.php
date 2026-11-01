<?php

declare(strict_types=1);

namespace SWForms\Core\Design;

/**
 * Presentation of a form (`settings.design`): a predefined variant, a colour scheme and
 * optional token overrides. Pure data; it never changes fields, names or validation.
 *
 * @since 0.6.0
 */
final class Design
{
    public const DEFAULT_VARIANT = 'classic';

    /** Predefined variants; `classic` is the original look. */
    public const VARIANTS = [
        'classic',
        'floating',
        'minimal',
        'dark-focus',
        'guided-card',
        'animated-float',
        'surprise-motion',
    ];

    /** Variants whose labels sit inside single-line controls. */
    public const FLOATING_VARIANTS = ['floating', 'animated-float'];

    public const SCHEMES = ['light', 'dark', 'auto'];

    /** Colour tokens that may be overridden, mapped to their CSS custom property. */
    public const COLOR_TOKENS = [
        'accent' => '--swf-accent',
        'text' => '--swf-text',
        'background' => '--swf-bg',
        'surface' => '--swf-surface',
        'border' => '--swf-border',
    ];

    public const MAX_RADIUS = 32;

    /**
     * @return array{variant: string, scheme: string, tokens: array<string, string|int>}
     */
    public static function normalize(mixed $input): array
    {
        $input = is_array($input) ? $input : [];
        $variant = $input['variant'] ?? null;
        $scheme = $input['scheme'] ?? null;
        $tokens = [];
        $raw = is_array($input['tokens'] ?? null) ? $input['tokens'] : [];
        foreach (array_keys(self::COLOR_TOKENS) as $name) {
            $color = Color::hex($raw[$name] ?? null);
            if (null !== $color) {
                $tokens[$name] = $color;
            }
        }
        if (is_int($raw['radius'] ?? null) || (is_string($raw['radius'] ?? null) && ctype_digit($raw['radius']))) {
            $tokens['radius'] = max(0, min(self::MAX_RADIUS, (int) $raw['radius']));
        }
        return [
            'variant' => self::variant($variant) ?? self::DEFAULT_VARIANT,
            'scheme' => is_string($scheme) && in_array($scheme, self::SCHEMES, true) ? $scheme : 'light',
            'tokens' => $tokens,
        ];
    }

    /** A known variant slug or null. */
    public static function variant(mixed $value): ?string
    {
        return is_string($value) && in_array($value, self::VARIANTS, true) ? $value : null;
    }

    /**
     * Wrapper classes of a normalised design.
     *
     * @param array{variant: string, scheme: string, tokens: array<string, string|int>} $design
     * @return list<string>
     */
    public static function classes(array $design): array
    {
        $classes = ['swf--' . $design['variant'], 'swf-scheme--' . $design['scheme']];
        if (isset($design['tokens']['surface'])) {
            $classes[] = 'swf--has-surface';
        }
        return $classes;
    }

    public static function floatingLabels(string $variant): bool
    {
        return in_array($variant, self::FLOATING_VARIANTS, true);
    }

    /**
     * Inline custom properties of the token overrides. Values are validated hex colours and
     * integers only, so the result is safe inside a `style` attribute.
     *
     * @param array{variant: string, scheme: string, tokens: array<string, string|int>} $design
     */
    public static function style(array $design): string
    {
        $declarations = [];
        foreach (self::COLOR_TOKENS as $name => $property) {
            $color = Color::hex($design['tokens'][$name] ?? null);
            if (null === $color) {
                continue;
            }
            $declarations[] = $property . ':' . $color;
            if ('accent' === $name) {
                $declarations[] = '--swf-button-bg:' . $color;
                $declarations[] = '--swf-accent-contrast:' . Color::readableOn($color);
            }
        }
        if (is_int($design['tokens']['radius'] ?? null)) {
            $declarations[] = '--swf-radius:' . $design['tokens']['radius'] . 'px';
        }
        return implode(';', $declarations);
    }
}
