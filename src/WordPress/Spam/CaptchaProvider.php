<?php
/**
 * CAPTCHA provider support.
 *
 * @package SWForms
 */

declare(strict_types=1);
namespace SWForms\WordPress\Spam;

use SWForms\WordPress\Webhooks\SecretVault;

final class CaptchaProvider implements ProviderInterface {
	public function __construct( private readonly string $name ) {}
	public function slug(): string {
		return $this->name; }
	public function configured(): bool {
		$item = CaptchaSettings::all()[ $this->name ] ?? array();
		return ! empty( $item['siteKey'] ) && ! empty( $item['secretEncrypted'] );
	}
	public function public_config(): array {
		return array(
			'provider' => $this->name,
			'siteKey'  => CaptchaSettings::all()[ $this->name ]['siteKey'] ?? '',
		);
	}
	public function verify( string $token ): bool {
		if ( ! $this->configured() || '' === $token || strlen( $token ) > 4096 ) {
			return false; }
		try {
			$item = CaptchaSettings::all()[ $this->name ];
			$body = array(
				'secret'   => SecretVault::decrypt( $item['secretEncrypted'] ),
				'response' => $token,
			);
			if ( 'hcaptcha' === $this->name ) {
				$body['sitekey'] = $item['siteKey']; }
			$url      = 'turnstile' === $this->name ? 'https://challenges.cloudflare.com/turnstile/v0/siteverify' : 'https://api.hcaptcha.com/siteverify';
			$response = wp_safe_remote_post(
				$url,
				array(
					'timeout'             => 15,
					'redirection'         => 0,
					'limit_response_size' => 16384,
					'body'                => $body,
				)
			);
			if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
				return false; }
			$data  = json_decode( wp_remote_retrieve_body( $response ), true );
			$hosts = (array) apply_filters( 'swf_captcha_allowed_hostnames', array( strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) ), $this->name );
			return is_array( $data ) && true === ( $data['success'] ?? false ) && in_array( strtolower( (string) ( $data['hostname'] ?? '' ) ), $hosts, true )
				&& ( 'turnstile' !== $this->name || 'swforms' === ( $data['action'] ?? '' ) );
		} catch ( \Throwable $error ) {
			return false; }
	}
}
