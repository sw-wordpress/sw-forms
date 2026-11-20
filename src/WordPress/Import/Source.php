<?php
/**
 * Shared source queries and safe document construction (internal).
 *
 * @package SWForms
 * @since 0.8.0
 */

declare(strict_types=1);
namespace SWForms\WordPress\Import;

final class Source {
	/** @return list<array{id: int, title: string}> */
	public static function forms( string $post_type, int $page ): array {
		$posts = get_posts(
			array(
				'post_type'      => $post_type,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page' => 50,
				'paged'          => max( 1, $page ),
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);
		return array_values(
			array_map(
				static fn ( \WP_Post $post ): array => array(
					'id'    => $post->ID,
					'title' => $post->post_title,
				),
				$posts
			)
		);
	}

	/** @return array{title: string, document: array<string, mixed>, imported: list<string>, skipped: list<string>, warnings: list<string>} */
	public static function result( string $title ): array {
		return array(
			'title'    => $title,
			'document' => array(
				'schemaVersion' => 1,
				'fields'        => array(),
				'settings'      => array( 'notifications' => array() ),
			),
			'imported' => array(),
			'skipped'  => array(),
			'warnings' => array( 'notifications', 'entries', 'layout', 'advanced' ),
		);
	}

	/**
	 * @param array<string, mixed> $settings
	 * @return array<string, mixed>
	 */
	public static function field( int $index, string $key, string $type, string $label, bool $required, array $settings ): array {
		$key = substr( (string) preg_replace( '/[^A-Za-z0-9_]/', '_', $key ), 0, 55 );
		$key = preg_match( '/^[A-Za-z_]/', $key ) ? $key : 'field_' . $key;
		return array(
			'id'       => 'import_' . $index,
			'key'      => $key,
			'type'     => $type,
			'label'    => wp_strip_all_tags( $label ),
			'required' => $required,
			'settings' => $settings,
		);
	}
}
