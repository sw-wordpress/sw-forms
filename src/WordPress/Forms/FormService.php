<?php
/**
 * Use cases around forms: create, validate, save (publish), draft, duplicate.
 *
 * @package SWForms
 */

declare(strict_types=1);

namespace SWForms\WordPress\Forms;

use SWForms\Core\Form\SchemaValidator;
use SWForms\Core\Form\Tree;
use SWForms\WordPress\Fields\FieldRegistry;
use SWForms\WordPress\Support\Messages;

final class FormService {

	public function __construct(
		private readonly FormRepository $forms,
		private readonly FieldRegistry $registry,
	) {}

	public static function create_default(): self {
		return new self( new FormRepository(), FieldRegistry::instance() );
	}

	public function repository(): FormRepository {
		return $this->forms;
	}

	/**
	 * @param array<string, mixed> $document
	 * @return array{schemaVersion: int, fields: list<array<string, mixed>>, settings: array<string, mixed>}
	 */
	public function normalize( array $document ): array {
		return ( new DocumentNormalizer( $this->registry ) )->normalize( $document );
	}

	/**
	 * Validates a normalized document.
	 *
	 * @param array<string, mixed> $document
	 * @return list<array{path: string, code: string, message: string}>
	 */
	public function validate( array $document ): array {
		$errors = array();
		foreach ( ( new SchemaValidator( $this->registry ) )->validate( $document ) as $error ) {
			$errors[] = array(
				'path'    => $error['path'],
				'code'    => $error['code'],
				'message' => Messages::schema( $error['code'], $error['params'] ),
			);
		}
		if ( array() !== $errors ) {
			return $errors;
		}
		// Conditions of notifications are evaluated at form level: only fields outside repeaters.
		$tree     = Tree::build( $document['fields'], $this->registry );
		$root_ids = $tree->rootIds();
		foreach ( $tree->ids() as $id ) {
			$type = $tree->type( $id );
			if ( 'page-break' === $type ) {
				$position = array_search( $id, $root_ids, true );
				if ( false === $position || 0 === $position || count( $root_ids ) - 1 === $position || 'page-break' === $tree->type( $root_ids[ $position - 1 ] ) ) {
					$errors[] = array(
						'path'    => $id,
						'code'    => 'page_break',
						'message' => __( 'Page breaks must separate non-empty steps at the top level.', 'sw-forms' ),
					);
				}
			}
			if ( 'calculation' === $type ) {
				$settings = $tree->node( $id )['settings'];
				$valid    = null === $tree->scopeOf( $id ) && ! empty( $settings['sources'] );
				foreach ( $settings['sources'] as $source ) {
					$node  = $tree->node( $source['field'] );
					$valid = $valid && $tree->isLeafData( $source['field'] ) && null === $tree->scopeOf( $source['field'] ) && ( in_array( $node['type'] ?? '', array( 'range', 'rating', 'scale' ), true ) || ( 'input' === ( $node['type'] ?? '' ) && 'number' === ( $node['settings']['inputType'] ?? '' ) ) );
				}
				if ( ! $valid ) {
					$errors[] = array(
						'path'    => $id,
						'code'    => 'calculation',
						'message' => __( 'Calculations need numeric source fields outside repeaters; calculated sources are not allowed.', 'sw-forms' ),
					);
				}
			}
			foreach ( $tree->node( $id )['logic']['rules'] ?? array() as $rule ) {
				if ( 'calculation' === $tree->type( $rule['field'] ) ) {
					$errors[] = array(
						'path'    => $id,
						'code'    => 'calculation_logic',
						'message' => __( 'Field conditions cannot depend on calculated values.', 'sw-forms' ),
					);
				}
			}
		}
		$provider_name = (string) ( $document['settings']['spam']['provider'] ?? '' );
		if ( '' !== $provider_name && ! \SWForms\WordPress\Spam\ProviderRegistry::instance()->get( $provider_name )?->configured() ) {
			$errors[] = array(
				'path'    => 'settings',
				'code'    => 'captcha_configuration',
				'message' => __( 'Configure the selected CAPTCHA provider in SW Forms settings first.', 'sw-forms' ),
			);
		}
		$notifications = $document['settings']['notifications'] ?? array();
		foreach ( is_array( $notifications ) ? $notifications : array() as $notification ) {
			foreach ( $notification['logic']['rules'] ?? array() as $rule ) {
				$field = (string) ( $rule['field'] ?? '' );
				if ( ! $tree->isLeafData( $field ) || null !== $tree->scopeOf( $field ) ) {
					$errors[] = array(
						'path'    => 'notification:' . (string) $notification['id'],
						'code'    => 'rule_unknown_field',
						'message' => Messages::schema( 'rule_unknown_field' ),
					);
					break;
				}
			}
		}
		return array_merge( $errors, \SWForms\WordPress\Webhooks\WebhookValidator::validate( $document['settings']['webhooks'] ?? array(), $tree ) );
	}

	public function create( string $title ): int {
		$title = '' === trim( $title ) ? __( 'Untitled form', 'sw-forms' ) : $title;
		$id    = $this->forms->create( $title, DocumentNormalizer::blank() );
		/**
		 * Fires after a form was created.
		 *
		 * @since 0.1.0
		 *
		 * @param int $form_id Form id.
		 */
		do_action( 'swf_form_created', $id );
		return $id;
	}

	/**
	 * Validates and publishes. Returns the errors (nothing saved) or the new version.
	 *
	 * @param array<string, mixed> $document
	 * @return array{errors: list<array{path: string, code: string, message: string}>, version: int, document: array<string, mixed>}
	 */
	public function save( int $id, string $title, array $document ): array {
		$document = $this->normalize( $document );
		$errors   = $this->validate( $document );
		if ( array() !== $errors ) {
			return array(
				'errors'   => $errors,
				'version'  => 0,
				'document' => $document,
			);
		}
		$title    = sanitize_text_field( $title );
		$version  = $this->forms->publish( $id, '' === $title ? __( 'Untitled form', 'sw-forms' ) : $title, $document );
		$document = $this->forms->version( $id, $version ) ?? $document;
		/**
		 * Fires after a form was saved (published).
		 *
		 * Failure policy: exceptions of callbacks are not caught; the form is already stored.
		 *
		 * @since 0.1.0
		 *
		 * @param int                  $form_id  Form id.
		 * @param int                  $version  Current version.
		 * @param array<string, mixed> $document Saved document.
		 */
		do_action( 'swf_form_saved', $id, $version, $document );
		return array(
			'errors'   => array(),
			'version'  => $version,
			'document' => $document,
		);
	}

	/**
	 * Autosave without validation (the preview renders the draft).
	 *
	 * @param array<string, mixed> $document
	 */
	public function save_draft( int $id, array $document ): void {
		$this->forms->save_draft( $id, $this->normalize( $document ) );
	}

	public function duplicate( int $id ): int {
		$form = $this->forms->find( $id );
		if ( null === $form ) {
			return 0;
		}
		$document = $form['draft'] ?? $this->forms->published( $id ) ?? DocumentNormalizer::blank();
		/* translators: %s: title of the copied form */
		$copy_id = $this->forms->create( sprintf( __( '%s (copy)', 'sw-forms' ), $form['title'] ), $document );
		if ( array() === $this->validate( $this->normalize( $document ) ) ) {
			$this->forms->publish( $copy_id, sprintf( __( '%s (copy)', 'sw-forms' ), $form['title'] ), $this->normalize( $document ) ); // phpcs:ignore WordPress.WP.I18n.MissingTranslatorsComment -- same string as above.
		}
		return $copy_id;
	}

	/**
	 * The document the builder edits: the draft if there is one, otherwise the published version.
	 *
	 * @return array<string, mixed>
	 */
	public function editable_document( int $id ): array {
		$form = $this->forms->find( $id );
		if ( null === $form ) {
			return DocumentNormalizer::blank();
		}
		return $form['draft'] ?? $this->forms->published( $id ) ?? DocumentNormalizer::blank();
	}
}
