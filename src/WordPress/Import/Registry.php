<?php
/**
 * Importer metadata and factories. No adapter is instantiated for the catalog.
 *
 * @package SWForms
 * @since 0.8.0
 */

declare(strict_types=1);
namespace SWForms\WordPress\Import;

final class Registry {
	private static ?self $instance = null;
	/** @var array<string, array{name: string, imports: list<string>, omits: list<string>, available: callable(): bool, factory: callable(): AdapterInterface}> */
	private array $items = array();
	/** @var array<string, AdapterInterface> */
	private array $loaded = array();

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->register( 'contact-form-7', 'Contact Form 7', array( 'cf7Fields', 'choices', 'required', 'submitLabel' ), array( 'notifications', 'entries', 'layout', 'advanced' ), static fn (): bool => class_exists( 'WPCF7_ContactForm', false ), static fn (): AdapterInterface => new ContactForm7() );
			self::$instance->register( 'wpforms', 'WPForms', array( 'wpformsFields', 'choices', 'required', 'submitLabel' ), array( 'notifications', 'entries', 'layout', 'advanced' ), static fn (): bool => post_type_exists( 'wpforms' ), static fn (): AdapterInterface => new WPForms() );
			/**
			 * Registers lazy import adapters on first authenticated import request.
			 *
			 * @since 0.8.0
			 * @param Registry $registry Import adapter registry.
			 */
			do_action( 'swf_register_importers', self::$instance );
		}
		return self::$instance;
	}

	/**
	 * @param list<string>                 $imports Supported features (plain text or built-in feature codes).
	 * @param list<string>                 $omits   Omitted features.
	 * @param callable(): bool             $available Cheap availability check; must not load the adapter.
	 * @param callable(): AdapterInterface $factory Lazy adapter factory.
	 */
	public function register( string $slug, string $name, array $imports, array $omits, callable $available, callable $factory ): void {
		if ( ! preg_match( '/^[a-z][a-z0-9-]{0,39}$/D', $slug ) ) {
			return;
		}
		$this->items[ $slug ] = array(
			'name'      => $name,
			'imports'   => $imports,
			'omits'     => $omits,
			'available' => $available,
			'factory'   => $factory,
		);
		unset( $this->loaded[ $slug ] );
	}

	/** @return list<array{slug: string, name: string, imports: list<string>, omits: list<string>, available: bool}> */
	public function catalog(): array {
		$items = array();
		foreach ( $this->items as $slug => $item ) {
			$items[] = array(
				'slug'      => $slug,
				'name'      => $item['name'],
				'imports'   => $item['imports'],
				'omits'     => $item['omits'],
				'available' => ( $item['available'] )(),
			);
		}
		return $items;
	}

	public function get( string $slug ): ?AdapterInterface {
		$item = $this->items[ $slug ] ?? null;
		if ( null === $item || ! ( $item['available'] )() ) {
			return null;
		}
		return $this->loaded[ $slug ] ??= ( $item['factory'] )();
	}
}
