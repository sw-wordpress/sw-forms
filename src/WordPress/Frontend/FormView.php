<?php
/**
 * Complete form markup: error summary, fields, spam protection, submit button and the
 * configuration for the frontend script. Also renders the confirmation after a postback.
 *
 * @package SWForms
 */

declare(strict_types=1);

namespace SWForms\WordPress\Frontend;

use SWForms\Core\Design\Design;
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
	 * @param array{preview?: bool, title?: bool, variant?: string} $options `variant` overrides the
	 *                                                                     stored design variant (0.6.0).
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
		$options['heading'] = (string) $form['title'];
		return self::markup( $form_id, Wpml::translate( $document, $form_id ), $options, $result );
	}

	/**
	 * Non-persistent demonstration of a document (design gallery): the runtime validates
	 * required fields locally and never sends data; the markup has no postback target.
	 *
	 * @internal
	 * @param array<string, mixed> $document
	 */
	public static function render_demo( int $demo_id, array $document, string $heading ): string {
		return self::markup(
			$demo_id,
			$document,
			array(
				'demo'    => true,
				'title'   => true,
				'heading' => $heading,
			),
			null
		);
	}

	/**
	 * Effective design: stored settings, then the embed `variant`, then `swf_form_design`.
	 *
	 * @param array<string, mixed> $settings
	 * @return array{variant: string, scheme: string, tokens: array<string, string|int>}
	 */
	public static function design( int $form_id, array $settings, string $variant = '' ): array {
		$design = Design::normalize( $settings['design'] ?? null );
		if ( null !== Design::variant( $variant ) ) {
			$design['variant'] = $variant;
		}
		/**
		 * Filters the design (variant, colour scheme, token overrides) of a rendered form.
		 *
		 * @since 0.6.0
		 *
		 * @param array{variant: string, scheme: string, tokens: array<string, string|int>} $design
		 * @param int                                                                         $form_id
		 */
		return Design::normalize( apply_filters( 'swf_form_design', $design, $form_id ) );
	}

	/**
	 * @param array<string, mixed>                                                                 $document
	 * @param array{preview?: bool, title?: bool, variant?: string, demo?: bool, heading?: string} $options
	 */
	private static function markup( int $form_id, array $document, array $options, ?SubmissionResult $result ): string {
		$preview = ! empty( $options['preview'] );
		$demo    = ! empty( $options['demo'] );
		Assets::enqueue();
		$registry   = FieldRegistry::instance();
		$fields     = is_array( $document['fields'] ?? null ) ? $document['fields'] : array();
		$settings   = is_array( $document['settings'] ?? null ) ? $document['settings'] : array();
		$design     = self::design( $form_id, $settings, (string) ( $options['variant'] ?? '' ) );
		$tree       = Tree::build( $fields, $registry );
		$renderer   = new Renderer( $registry, $tree );
		$values     = null !== $result && array() !== $result->values ? $result->values : $renderer->defaults();
		$errors     = null !== $result ? $result->errors : array();
		$visibility = ( new Evaluator( $tree ) )->evaluate( $values );
		$values     = \SWForms\WordPress\Forms\Calculations::apply( $tree, $values, $visibility );
		$context    = new RenderContext( $renderer, $form_id, $values, $errors, $visibility, '', 'swf', '', '', Design::floatingLabels( $design['variant'] ) );

		$config = array(
			'formId'  => $form_id,
			'rest'    => $demo ? '' : esc_url_raw( rest_url( 'swforms/v1/forms/' . $form_id . '/submit' ) ),
			'ajax'    => esc_url_raw( admin_url( 'admin-ajax.php' ) ),
			'preview' => $preview || $demo,
			'nonce'   => $preview ? wp_create_nonce( 'wp_rest' ) : '',
			'fields'  => $renderer->public_tree( $fields ),
			'i18n'    => array(
				'sending'      => __( 'Sending…', 'sw-forms' ),
				'networkError' => __( 'The form could not be sent. Please check your connection and try again.', 'sw-forms' ),
				'progress'     => __( 'Step {current} of {total}: {label}', 'sw-forms' ),
				'summary'      => __( 'Please correct the following fields:', 'sw-forms' ),
			),
		);
		if ( $demo ) {
			$config['demo']                 = true;
			$config['i18n']['required']     = __( 'This field is required.', 'sw-forms' );
			$config['i18n']['demoComplete'] = __( 'Demo only: the values are valid and were not sent.', 'sw-forms' );
		}

		$classes = trim( 'swf swf-form-wrap ' . implode( ' ', Design::classes( $design ) ) . ' ' . (string) ( $settings['cssClass'] ?? '' ) );
		$style   = Design::style( $design );
		$html    = '<div class="' . esc_attr( $classes ) . '" id="swf-' . $form_id . '" data-swf-variant="' . esc_attr( $design['variant'] ) . '"' . ( '' !== $style ? ' style="' . esc_attr( $style ) . '"' : '' ) . '>';
		if ( ! empty( $options['title'] ) ) {
			$level = $demo ? 'h3' : 'h2';
			$html .= '<' . $level . ' class="swf-title">' . esc_html( (string) ( $options['heading'] ?? '' ) ) . '</' . $level . '>';
		}
		$armour_class = ! $preview && ! $demo && \SWForms\WordPress\Spam\WpArmour::available() ? ' wpa_form' : '';
		// Demo forms have no postback target: without JavaScript they only reload the page.
		$action = $demo ? '' : ' action="' . esc_url( add_query_arg( 'swf_form', $form_id ) ) . '"';

		$html  .= '<form class="swf-form' . $armour_class . '" method="post"' . $action . ' enctype="multipart/form-data" novalidate data-swf-form="' . $form_id . '">';
		$html  .= self::summary( $form_id, $result );
		$groups = \SWForms\WordPress\Forms\Steps::groups( $tree, $settings );
		$html  .= '<div class="swf-fields">';
		if ( count( $groups ) > 1 ) {
			$html .= self::step_indicator( $groups, (string) ( $settings['steps']['indicator'] ?? 'numbers' ) );
			foreach ( $groups as $index => $group ) {
				$html .= '<section class="swf-step" data-swf-step="' . $index . '"><h3 tabindex="-1">' . esc_html( $group['label'] ) . '</h3>' . $renderer->render_ids( $group['ids'], $context ) . '</section>';
			}
			$html .= '<div class="swf-step-nav" hidden><button type="button" class="swf-button swf-step-back">' . esc_html__( 'Back', 'sw-forms' ) . '</button><button type="button" class="swf-button swf-step-next">' . esc_html__( 'Next', 'sw-forms' ) . '</button></div>';
		} else {
			$html .= $renderer->render_ids( $tree->rootIds(), $context );
		}
		$html .= '</div>';
		if ( ! $demo ) {
			$html .= '<input type="hidden" name="swf_form_id" value="' . $form_id . '">';
			$html .= '<input type="hidden" name="swf_token" value="' . esc_attr( ( new SpamGuard() )->issue_token( $form_id ) ) . '">';
		}
		if ( ! $demo && ! empty( $settings['spam']['honeypot'] ) ) {
			// Visually hidden, skipped by keyboard and assistive technology; bots tend to fill it in.
			$html .= '<div class="swf-hp" aria-hidden="true"><label for="swf-' . $form_id . '-hp">' . esc_html__( 'Leave this field empty', 'sw-forms' ) . '</label>'
				. '<input type="text" id="swf-' . $form_id . '-hp" name="' . esc_attr( SpamGuard::HONEYPOT_FIELD ) . '" value="" tabindex="-1" autocomplete="off"></div>';
		}
		$html         .= '<div class="swf-actions"><button type="submit" class="swf-button swf-submit">' . esc_html( (string) ( $settings['submitLabel'] ?? __( 'Submit', 'sw-forms' ) ) ) . '</button></div>';
		$provider_name = (string) ( $settings['spam']['provider'] ?? '' );
		if ( ! $preview && ! $demo && '' !== $provider_name ) {
			$provider          = \SWForms\WordPress\Spam\ProviderRegistry::instance()->get( $provider_name );
			$config['captcha'] = $provider?->public_config() ?? array( 'provider' => $provider_name );
			$html             .= '<div class="swf-captcha" role="group" aria-label="' . esc_attr__( 'Spam protection', 'sw-forms' ) . '"></div><input type="hidden" name="swf_captcha" value=""><noscript>' . esc_html__( 'JavaScript is required to complete the CAPTCHA.', 'sw-forms' ) . '</noscript>';
		}
		$html .= '<script type="application/json" class="swf-config">' . wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES ) . '</script>';
		$html .= '</form></div>';
		return $html;
	}

	/**
	 * Progress list (hidden until the runtime enables steps) plus a live status text.
	 *
	 * @param list<array{label: string, ids: list<string>}> $groups
	 */
	private static function step_indicator( array $groups, string $indicator ): string {
		$status = 'text' === $indicator ? 'swf-step-progress' : 'swf-step-progress swf-sr-only';
		$html   = '';
		if ( 'text' !== $indicator ) {
			$html .= '<ol class="swf-steps swf-steps--' . esc_attr( 'dots' === $indicator ? 'dots' : 'numbers' ) . '" aria-label="' . esc_attr__( 'Form progress', 'sw-forms' ) . '" hidden>';
			foreach ( $groups as $index => $group ) {
				$html .= '<li class="swf-steps__item" data-swf-step-indicator="' . $index . '"><button type="button" class="swf-steps__button" disabled>'
					. '<span class="swf-steps__marker" aria-hidden="true">' . ( $index + 1 ) . '</span>'
					. '<span class="swf-steps__label">' . esc_html( $group['label'] ) . '</span></button></li>';
			}
			$html .= '</ol>';
		}
		return $html . '<p class="' . $status . '" role="status" aria-live="polite" hidden></p>';
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
