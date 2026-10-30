<?php
/**
 * Public merge tag registry. Duplicate names replace; invalid names are ignored.
 *
 * @package SWForms
 * @since 0.4.0
 */

declare(strict_types=1);
namespace SWForms\WordPress\Template;

use SWForms\WordPress\Submission\Submission;

final class MergeTagRegistry {
	private static ?self $instance = null;
	/** @var array<string, MergeTagInterface> */
	private array $tags = array();

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
			/**
			 * Register custom merge tags once, on first use.
			 *
			 * @since 0.4.0
			 * @param MergeTagRegistry $registry Registry accepting MergeTagInterface instances.
			 */
			do_action( 'swf_register_merge_tags', self::$instance );
		}
		return self::$instance;
	}

	public function register( MergeTagInterface $tag ): void {
		if ( preg_match( '/^[a-z][a-z0-9_]{0,39}$/D', $tag->name() ) ) {
			$this->tags[ $tag->name() ] = $tag;
		}
	}

	public function get( string $name ): ?MergeTagInterface {
		return $this->tags[ $name ] ?? null;
	}

	/** @return array<string, MergeTagInterface> */
	public function all(): array {
		return $this->tags;
	}

	public function resolve( string $name, string $argument, Submission $submission ): ?string {
		return isset( $this->tags[ $name ] ) ? $this->tags[ $name ]->resolve( $argument, $submission ) : null;
	}
}
