<?php
/**
 * CAPTCHA provider support.
 *
 * Public extension API; stable from 0.4.0.
 *
 * @since 0.3.0
 * @package SWForms
 */

declare(strict_types=1);
namespace SWForms\WordPress\Spam;

interface ProviderInterface {
	public function slug(): string;
	public function configured(): bool;
	/** @return array<string, mixed> Public widget configuration only. */
	public function public_config(): array;
	public function verify( string $token ): bool;
}
