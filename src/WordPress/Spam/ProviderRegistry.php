<?php
/**
 * CAPTCHA provider support.
 *
 * @package SWForms
 */

declare(strict_types=1);
namespace SWForms\WordPress\Spam;

final class ProviderRegistry {
	private static ?self $instance = null;
	/** @var array<string, ProviderInterface> */
	private array $providers = array();
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->register( new CaptchaProvider( 'turnstile' ) );
			self::$instance->register( new CaptchaProvider( 'hcaptcha' ) );
			do_action( 'swf_register_spam_providers', self::$instance );
		}
		return self::$instance;
	}
	public function register( ProviderInterface $provider ): void {
		if ( preg_match( '/^[a-z][a-z0-9_-]{0,39}$/D', $provider->slug() ) ) {
			$this->providers[ $provider->slug() ] = $provider; }
	}
	public function get( string $name ): ?ProviderInterface {
		return $this->providers[ $name ] ?? null; }
}
