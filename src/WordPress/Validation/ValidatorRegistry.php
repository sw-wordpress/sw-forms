<?php
/**
 * Public validator registry. Duplicate slugs replace; invalid slugs are ignored.
 *
 * @package SWForms
 * @since 0.4.0
 */

declare(strict_types=1);
namespace SWForms\WordPress\Validation;

final class ValidatorRegistry {
	private static ?self $instance = null;
	/** @var array<string, ValidatorInterface> */
	private array $validators = array();

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
			/**
			 * Register custom validators once, on first use.
			 *
			 * @since 0.4.0
			 * @param ValidatorRegistry $registry Registry accepting ValidatorInterface instances.
			 */
			do_action( 'swf_register_validators', self::$instance );
		}
		return self::$instance;
	}

	public function register( ValidatorInterface $validator ): void {
		if ( preg_match( '/^[a-z][a-z0-9_-]{0,39}$/D', $validator->slug() ) ) {
			$this->validators[ $validator->slug() ] = $validator;
		}
	}

	public function get( string $name ): ?ValidatorInterface {
		return $this->validators[ $name ] ?? null;
	}

	/** @return array<string, ValidatorInterface> */
	public function all(): array {
		return $this->validators;
	}

	/**
	 * @param array<string, mixed> $element
	 * @return list<array{code: string, params?: array<string, string|int>, message?: string}>
	 */
	public function validate( mixed $value, array $element ): array {
		foreach ( $this->validators as $validator ) {
			if ( $validator->supports( $element ) ) {
				$errors = $validator->validate( $value, $element );
				if ( array() !== $errors ) {
					return $errors;
				}
			}
		}
		return array();
	}
}
