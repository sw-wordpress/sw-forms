<?php
/**
 * Catalog of built-in, secret-free starter documents.
 *
 * Item ids are stable identifiers of the catalog: `contact`, `registration` and `survey` existed
 * before 0.7.0 and keep their ids. A created form is an independent instance – later template
 * versions never change existing forms, and element ids/keys of a published template document
 * must not be edited in place, only through a new version of the template.
 *
 * Display hints: `icon` is a Material Symbols (round) name, `category` is one of the CATEGORY_*
 * slugs, `version` documents the template revision, `tags` are plain search words.
 *
 * @package SWForms
 * @since 0.5.0
 */

declare(strict_types=1);
namespace SWForms\WordPress\Forms;

use SWForms\Core\Form\DocumentStats;
use SWForms\WordPress\Fields\FieldRegistry;
use SWForms\WordPress\Forms\Templates\BusinessTemplates;
use SWForms\WordPress\Forms\Templates\CommunityTemplates;
use SWForms\WordPress\Forms\Templates\EventsTemplates;
use SWForms\WordPress\Forms\Templates\GeneralTemplates;

final class Templates {

	public const CATEGORY_GENERAL   = 'general';
	public const CATEGORY_EVENTS    = 'events';
	public const CATEGORY_BUSINESS  = 'business';
	public const CATEGORY_COMMUNITY = 'community';

	/** Catalog item ids use this narrower pattern, so they stay usable in URLs. */
	private const ITEM_PATTERN = '/^[a-z0-9][a-z0-9-]{0,63}$/';

	/**
	 * Every starter document with its metadata.
	 *
	 * @return list<array<string, mixed>> Includes `document`; use {@see self::summaries()} without it.
	 */
	public static function all(): array {
		$items = array();
		foreach ( self::providers() as $category => $provider ) {
			foreach ( $provider() as $item ) {
				if ( ! isset( $item['category'] ) || ! self::has_category( (string) $item['category'] ) ) {
					$item['category'] = $category;
				}
				$id           = (string) ( $item['id'] ?? '' );
				$items[ $id ] = $item;
			}
		}

		/**
		 * Replaces or extends the built-in starter templates (add-ons can ship their own).
		 *
		 * Items are keyed by their template id and need at least `id` and `title`; `document` holds
		 * the form document. `description`, `icon`, `category`, `tags` and `version` are display
		 * hints, counts and `isMultiStep` are added by the catalog afterwards. Documents go through
		 * the normal import validation, so an invalid document fails when the form is created.
		 *
		 * @since 0.7.0
		 *
		 * @param array<string, array<string, mixed>> $items Item per template id.
		 */
		return self::collect( apply_filters( 'swf_form_templates', $items ) );
	}

	/**
	 * Adds computed metadata and drops items the gallery cannot render (a broken add-on item
	 * must never take the whole dialog down).
	 *
	 * @param mixed $items
	 * @return list<array<string, mixed>>
	 */
	private static function collect( mixed $items ): array {
		$out = array();
		foreach ( is_array( $items ) ? $items : array() as $key => $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$decorated = self::decorate( $item, (string) $key );
			$id        = (string) $decorated['id'];
			if ( '' === (string) $decorated['title'] || ! is_array( $decorated['document'] ) || 1 !== preg_match( self::ITEM_PATTERN, $id ) ) {
				continue;
			}
			$out[ $id ] = $decorated;
		}

		return array_values( $out );
	}

	/**
	 * Catalog metadata without documents: what the template gallery lists.
	 *
	 * @since 0.7.0
	 * @return list<array<string, mixed>>
	 */
	public static function summaries(): array {
		return array_map(
			static function ( array $item ): array {
				unset( $item['document'] );
				return $item;
			},
			self::all()
		);
	}

	/**
	 * One catalog item including its document.
	 *
	 * @since 0.7.0
	 * @return array<string, mixed>|null
	 */
	public static function get( string $id ): ?array {
		foreach ( self::all() as $item ) {
			if ( (string) ( $item['id'] ?? '' ) === $id ) {
				return $item;
			}
		}
		return null;
	}

	/**
	 * Document of one catalog item, unnormalized; import normalizes and validates it.
	 *
	 * @since 0.7.0
	 * @return array<string, mixed>|null
	 */
	public static function document( string $id ): ?array {
		$document = self::get( $id )['document'] ?? null;
		return is_array( $document ) ? $document : null;
	}

	/**
	 * Whether a template id exists in the catalog.
	 *
	 * @since 0.7.0
	 */
	public static function exists( string $id ): bool {
		return null !== self::get( $id );
	}

	/**
	 * Categories with their labels and item counts, in catalog order.
	 *
	 * @since 0.7.0
	 * @return list<array<string, mixed>>
	 */
	public static function categories(): array {
		$counts = array();
		foreach ( self::all() as $item ) {
			$category            = (string) ( $item['category'] ?? '' );
			$counts[ $category ] = ( $counts[ $category ] ?? 0 ) + 1;
		}

		$labels = self::category_labels();

		/** @var array<string, array<string, mixed>> $out */
		$out = array();
		// Items whose category was replaced by a filter keep their own group at the end.
		foreach ( array_merge( array_keys( $labels ), array_keys( $counts ) ) as $slug ) {
			$slug = (string) $slug;
			if ( '' === $slug || isset( $out[ $slug ] ) ) {
				continue;
			}
			$out[ $slug ] = array(
				'slug'  => $slug,
				'title' => $labels[ $slug ] ?? $slug,
				'icon'  => self::CATEGORY_ICONS[ $slug ] ?? 'r_category',
				'count' => $counts[ $slug ] ?? 0,
			);
		}

		/**
		 * Extends the template categories shown by the gallery.
		 *
		 * @since 0.7.0
		 *
		 * @param array<string, array<string, mixed>> $categories Group per slug, with `slug`, `title`, `icon`, `count`.
		 */
		return self::category_list( apply_filters( 'swf_form_template_categories', $out ) );
	}

	/**
	 * Keeps the gallery shape even when an add-on returns partial or unrelated data.
	 *
	 * @param mixed $categories
	 * @return list<array<string, mixed>>
	 */
	private static function category_list( mixed $categories ): array {
		$out = array();
		foreach ( is_array( $categories ) ? $categories : array() as $category ) {
			if ( ! is_array( $category ) || '' === (string) ( $category['slug'] ?? '' ) ) {
				continue;
			}
			$slug         = (string) $category['slug'];
			$out[ $slug ] = array(
				'slug'  => $slug,
				'title' => (string) ( $category['title'] ?? $slug ),
				'icon'  => (string) ( $category['icon'] ?? 'r_category' ),
				'count' => (int) ( $category['count'] ?? 0 ),
			);
		}

		return array_values( $out );
	}

	/**
	 * @return array<string, string>
	 */
	private static function category_labels(): array {
		return array(
			self::CATEGORY_GENERAL   => __( 'General', 'sw-forms' ),
			self::CATEGORY_EVENTS    => __( 'Events', 'sw-forms' ),
			self::CATEGORY_BUSINESS  => __( 'Company', 'sw-forms' ),
			self::CATEGORY_COMMUNITY => __( 'Community and clubs', 'sw-forms' ),
		);
	}

	/** @var array<string, string> */
	private const CATEGORY_ICONS = array(
		self::CATEGORY_GENERAL   => 'r_chat',
		self::CATEGORY_EVENTS    => 'r_event',
		self::CATEGORY_BUSINESS  => 'r_business_center',
		self::CATEGORY_COMMUNITY => 'r_diversity_1',
	);

	/**
	 * @param array<string, mixed> $item
	 * @param string               $key array key of the item, used when `id` is missing
	 * @return array<string, mixed>
	 */
	private static function decorate( array $item, string $key = '' ): array {
		$document = is_array( $item['document'] ?? null ) ? $item['document'] : array();
		$stats    = DocumentStats::measure( $document, FieldRegistry::instance() );
		$tags     = array_values( array_filter( array_map( 'strval', is_array( $item['tags'] ?? null ) ? $item['tags'] : array() ) ) );

		// Extra keys of add-on items (an industry label, an audit trail) pass through, but never
		// replace what the catalog computes itself.
		$computed    = array(
			'id'          => (string) ( $item['id'] ?? $key ),
			'version'     => (string) ( $item['version'] ?? '1.0.0' ),
			'title'       => (string) ( $item['title'] ?? '' ),
			'description' => (string) ( $item['description'] ?? '' ),
			'icon'        => (string) ( $item['icon'] ?? 'r_description' ),
			'category'    => (string) ( $item['category'] ?? self::CATEGORY_GENERAL ),
			'tags'        => $tags,
			'fields'      => $stats['fields'],
			'steps'       => $stats['steps'],
			'conditions'  => $stats['conditions'],
			'isMultiStep' => $stats['steps'] > 1,
			'document'    => $document,
		);
		$passthrough = array_filter(
			$item,
			static fn ( string $key ): bool => ! array_key_exists( $key, $computed ),
			ARRAY_FILTER_USE_KEY
		);

		return array_merge( $passthrough, $computed );
	}

	/**
	 * @return array<string, callable> category slug => item provider
	 */
	private static function providers(): array {
		return array(
			self::CATEGORY_GENERAL   => array( GeneralTemplates::class, 'all' ),
			self::CATEGORY_EVENTS    => array( EventsTemplates::class, 'all' ),
			self::CATEGORY_BUSINESS  => array( BusinessTemplates::class, 'all' ),
			self::CATEGORY_COMMUNITY => array( CommunityTemplates::class, 'all' ),
		);
	}

	private static function has_category( string $slug ): bool {
		return isset( self::category_labels()[ $slug ] );
	}

	/**
	 * Ids of all catalog items (used by validation and the preview route).
	 *
	 * @since 0.7.0
	 * @return list<string>
	 */
	public static function ids(): array {
		return array_map( 'strval', array_column( self::all(), 'id' ) );
	}
}
