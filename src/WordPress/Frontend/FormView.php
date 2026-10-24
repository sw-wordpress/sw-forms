<?php
/**
 * Complete form markup: error summary, fields, spam protection, submit button and the
 * configuration for the frontend script. Also renders the confirmation after a postback.
 *
 * @package SWForms
 */

declare(strict_types=1);

namespace SWForms\WordPress\Frontend;

use SWForms\Core\Form\Tree;
use SWForms\Core\Logic\Evaluator;
use SWForms\WordPress\Fields\FieldRegistry;
use SWForms\WordPress\Forms\FormRepository;
use SWForms\WordPress\Submission\SpamGuard;
use SWForms\WordPress\Submission\SubmissionResult;
use SWForms\WordPress\Support\Capabilities;
use SWForms\WordPress\Support\Wpml;

final class FormView {

	/**
	 * @param array{preview?: bool, title?: bool} $options
	 */
	public static function render( int $form_id, array $options = array() ): string {
		$forms   = new FormRepository();
		$form    = $forms->find( $form_id );
		$preview = ! empty( $options['preview'] );
		if ( null === $form ) {
			return self::notice( __( 'SW Forms: this form does not exist.', 'sw-forms' ) );
		}
		$document = $preview ? ( $form['draft'] ?? $forms->published( $form_id ) ) : $forms->published( $form_id );
		if ( null === $document ) {
			return self::notice( __( 'SW Forms: this form has not been saved yet.', 'sw-forms' ) );
		}
		if ( ! $preview && 'active' !== $form['status'] ) {
			return self::notice( __( 'SW Forms: this form is inactive.', 'sw-forms' ) );
		}

		$result = Postback::result( $form_id );
		if ( null !== $result && SubmissionResult::SUCCESS === $result->status ) {
			return '<div class="swf swf-confirmation" role="status" tabindex="-1">' . $result->message . '</div>';
		}

		Assets::enqueue();
		$document   = Wpml::translate( $document, $form_id );
		$registry   = FieldRegistry::instance();
		$fields     = is_array( $document['fields'] ?? null ) ? $document['fields'] : array();
		$settings   = is_array( $document['settings'] ?? null ) ? $document['settings'] : array();
		$tree       = Tree::build( $fields, $registry );
		$renderer   = new Renderer( $registry, $tree );
		$values     = null !== $result && array() !== $result->values ? $result->values : $renderer->defaults();
		$errors     = null !== $result ? $result->errors : array();
		$visibility = ( new Evaluator( $tree ) )->evaluate( $values );
		$context    = new RenderContext( $renderer, $form_id, $values, $errors, $visibility );

		$config = array(
			'formId'  => $form_id,
			'rest'    => esc_url_raw( rest_url( 'swforms/v1/forms/' . $form_id . '/submit' ) ),
			'ajax'    => esc_url_raw( admin_url( 'admin-ajax.php' ) ),
			'preview' => $preview,
			'nonce'   => $preview ? wp_create_nonce( 'wp_rest' ) : '',
			'fields'  => $renderer->public_tree( $fields ),
			'i18n'    => array(
				'sending'      => __( 'Sending…', 'sw-forms' ),
				'networkError' => __( 'The form could not be sent. Please check your connection and try again.', 'sw-forms' ),
				'summary'      => __( 'Please correct the following fields:', 'sw-forms' ),
			),
		);

		$classes = trim( 'swf swf-form-wrap ' . (string) ( $settings['cssClass'] ?? '' ) );
		$html    = '<div class="' . esc_attr( $classes ) . '" id="swf-' . $form_id . '">';
		if ( ! empty( $options['title'] ) ) {
			$html .= '<h2 class="swf-title">' . esc_html( $form['title'] ) . '</h2>';
		}
		$html .= '<form class="swf-form" method="post" novalidate data-swf-form="' . $form_id . '">';
		$html .= self::summary( $form_id, $result );
		$html .= '<div class="swf-fields">' . $renderer->render_ids( $tree->rootIds(), $context ) . '</div>';
		$html .= '<input type="hidden" name="swf_form_id" value="' . $form_id . '">';
		$html .= '<input type="hidden" name="swf_token" value="' . esc_attr( ( new SpamGuard() )->issue_token( $form_id ) ) . '">';
		if ( ! empty( $settings['spam']['honeypot'] ) ) {
			// Visually hidden, skipped by keyboard and assistive technology; bots tend to fill it in.
			$html .= '<div class="swf-hp" aria-hidden="true"><label for="swf-' . $form_id . '-hp">' . esc_html__( 'Leave this field empty', 'sw-forms' ) . '</label>'
				. '<input type="text" id="swf-' . $form_id . '-hp" name="' . esc_attr( SpamGuard::HONEYPOT_FIELD ) . '" value="" tabindex="-1" autocomplete="off"></div>';
		}
		$html .= '<div class="swf-actions"><button type="submit" class="swf-button swf-submit">' . esc_html( (string) ( $settings['submitLabel'] ?? __( 'Submit', 'sw-forms' ) ) ) . '</button></div>';
		$html .= '<script type="application/json" class="swf-config">' . wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES ) . '</script>';
		$html .= '</form></div>';
		return $html;
	}

	private static function summary( int $form_id, ?SubmissionResult $result ): string {
		$errors = null !== $result ? $result->errors : array();
		$items  = '';
		foreach ( $errors as $path => $message ) {
			$items .= '<li><a href="#' . esc_attr( self::dom_id( $form_id, (string) $path ) . '-err' ) . '">' . esc_html( $message ) . '</a></li>';
		}
		$message = null !== $result ? $result->message : '';
		$hidden  = null === $result ? ' hidden' : '';
		return '<div class="swf-summary" role="alert" tabindex="-1"' . $hidden . '><p class="swf-summary__title">' . esc_html( $message ) . '</p><ul>' . $items . '</ul></div>';
	}

	/** DOM id of an element path (`rep[0].name` → `swf-3-rep-0-name`), matching RenderContext. */
	public static function dom_id( int $form_id, string $path ): string {
		return 'swf-' . $form_id . '-' . str_replace( array( '].', '[' ), '-', $path );
	}

	/** Problems are only shown to people who can fix them. */
	private static function notice( string $message ): string {
		if ( ! current_user_can( Capabilities::MANAGE_FORMS ) ) {
			return '';
		}
		return '<p class="swf-notice">' . esc_html( $message ) . '</p>';
	}
}
