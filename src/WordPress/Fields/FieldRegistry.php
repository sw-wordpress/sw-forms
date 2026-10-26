<?php
/**
 * Registry of element types. Add-ons register their types on the `swf_register_field_types` action.
 *
 * @package SWForms
 */

declare(strict_types=1);

namespace SWForms\WordPress\Fields;

use SWForms\Core\Form\TypeCatalog;

final class FieldRegistry implements TypeCatalog {

	private const TYPE_PATTERN = '/^[a-z][a-z0-9-]{0,39}$/';

	private static ?FieldRegistry $instance = null;

	/** @var array<string, FieldTypeInterface> */
	private array $types = array();

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
			foreach ( array( new InputField(), new TextareaField(), new CheckboxField(), new RadioField(), new SelectField(), new GroupField(), new ColumnsField() ) as $type ) {
				self::$instance->register( $type );
			}
			/**
			 * Register additional element types.
			 *
			 * Failure policy: a type with an invalid slug is ignored; registering an existing slug replaces it.
			 *
			 * @since 0.1.0
			 *
			 * @param FieldRegistry $registry Call `$registry->register( new My_Type() )`.
			 */
			do_action( 'swf_register_field_types', self::$instance );
		}
		return self::$instance;
	}

	public function register( FieldTypeInterface $type ): void {
		if ( 1 !== preg_match( self::TYPE_PATTERN, $type->type() ) ) {
			_doing_it_wrong( __METHOD__, esc_html( 'Invalid SW Forms field type slug: ' . $type->type() ), '0.1.0' );
			return;
		}
		$this->types[ $type->type() ] = $type;
	}

	public function get( string $type ): ?FieldTypeInterface {
		return $this->types[ $type ] ?? null;
	}

	/**
	 * @return array<string, FieldTypeInterface>
	 */
	public function all(): array {
		return $this->types;
	}

	public function has( string $type ): bool {
		return isset( $this->types[ $type ] );
	}

	public function isData( string $type ): bool {
		return isset( $this->types[ $type ] ) && $this->types[ $type ]->is_data();
	}

	public function container( string $type ): string {
		return isset( $this->types[ $type ] ) ? $this->types[ $type ]->container() : self::CONTAINER_NONE;
	}

	/**
	 * Type definitions for the builder (REST `GET /field-types`).
	 *
	 * @return list<array<string, mixed>>
	 */
	public function definitions(): array {
		$out = array();
		foreach ( $this->types as $type ) {
			$out[] = array(
				'type'      => $type->type(),
				'label'     => $type->label(),
				'icon'      => $type->icon(),
				'category'  => $type->category(),
				'isData'    => $type->is_data(),
				'container' => $type->container(),
				'supports'  => $type->supports(),
				'settings'  => $type->settings_schema(),
				'defaults'  => $type->default_settings(),
			);
		}
		return $out;
	}
}
