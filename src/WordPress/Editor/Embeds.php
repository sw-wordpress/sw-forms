<?php
/**
 * Gutenberg dynamic block and optional WPBakery (Visual Composer) element.
 *
 * @package SWForms
 * @since 0.5.0
 */

declare(strict_types=1);
namespace SWForms\WordPress\Editor;

use SWForms\WordPress\Forms\FormRepository;
use SWForms\WordPress\Frontend\FormView;

final class Embeds {
	public function register(): void {
		add_action( 'init', array( $this, 'block' ) );
		add_action( 'vc_before_init', array( $this, 'visual_composer' ) );
	}

	public function block(): void {
		wp_register_script( 'sw-forms-block', SWF_URL . 'assets/block.js', array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-server-side-render', 'wp-api-fetch', 'wp-i18n' ), SWF_VERSION, true );
		wp_add_inline_script(
			'sw-forms-block',
			'window.swFormsBlockI18n = ' . wp_json_encode(
				array(
					'form'   => __( 'Form', 'sw-forms' ),
					'select' => __( 'Select a form', 'sw-forms' ),
					'title'  => __( 'Show form title', 'sw-forms' ),
				)
			) . ';',
			'before'
		);
		register_block_type(
			'swforms/form',
			array(
				'api_version'     => 3,
				'editor_script'   => 'sw-forms-block',
				'attributes'      => array(
					'formId' => array(
						'type'    => 'integer',
						'default' => 0,
					),
					'title'  => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
				'render_callback' => static fn ( array $attributes ): string => empty( $attributes['formId'] ) ? '' : FormView::render( absint( $attributes['formId'] ), array( 'title' => ! empty( $attributes['title'] ) ) ),
			)
		);
	}

	/** Only public embedding metadata; never documents or signing secrets.
	 *
	 * @return list<array{id: int, title: string}>
	 */
	public static function choices(): array {
		return array_values(
			array_map(
				static fn ( array $form ): array => array(
					'id'    => $form['id'],
					'title' => $form['title'],
				),
				array_filter( ( new FormRepository() )->all(), static fn ( array $form ): bool => 'active' === $form['status'] && $form['currentVersion'] > 0 )
			)
		);
	}

	public function visual_composer(): void {
		if ( ! function_exists( 'vc_map' ) ) {
			return;
		}
		$values = array( __( 'Select a form', 'sw-forms' ) => '' );
		foreach ( self::choices() as $form ) {
			$values[ $form['title'] . ' (#' . $form['id'] . ')' ] = (string) $form['id'];
		}
		vc_map(
			array(
				'name'     => __( 'SW Forms', 'sw-forms' ),
				'base'     => 'sw_form',
				'category' => __( 'Content', 'sw-forms' ),
				'params'   => array(
					array(
						'type'       => 'dropdown',
						'heading'    => __( 'Form', 'sw-forms' ),
						'param_name' => 'id',
						'value'      => $values,
					),
				),
			)
		);
	}
}
