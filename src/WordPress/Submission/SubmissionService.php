<?php
/**
 * The submission pipeline: spam checks, collect, conditional logic, validate, store, actions, confirmation.
 *
 * @package SWForms
 */

declare(strict_types=1);

namespace SWForms\WordPress\Submission;

use SWForms\Core\Form\DataBuilder;
use SWForms\Core\Form\Tree;
use SWForms\Core\Logic\Evaluator;
use SWForms\WordPress\Actions\ActionInterface;
use SWForms\WordPress\Actions\EmailNotifications;
use SWForms\WordPress\Entries\EntryRepository;
use SWForms\WordPress\Fields\FieldRegistry;
use SWForms\WordPress\Forms\FormRepository;
use SWForms\WordPress\Support\Settings;
use SWForms\WordPress\Support\Wpml;
use SWForms\WordPress\Template\MergeTagResolver;

final class SubmissionService {

	public function __construct(
		private readonly FormRepository $forms,
		private readonly EntryRepository $entries,
		private readonly FieldRegistry $registry,
		private readonly SpamGuard $spam,
	) {}

	public static function create_default(): self {
		return new self( new FormRepository(), new EntryRepository(), FieldRegistry::instance(), new SpamGuard() );
	}

	/**
	 * @param array<string, mixed> $raw     Unslashed `swf` request array.
	 * @param array<string, mixed> $request honeypot, token, ip, user_id, source_url, preview (bool).
	 */
	public function submit( int $form_id, array $raw, array $request ): SubmissionResult {
		$form    = $this->forms->find( $form_id );
		$preview = ! empty( $request['preview'] );
		if ( null === $form ) {
			return SubmissionResult::error( 'not_found', __( 'This form does not exist.', 'sw-forms' ) );
		}
		$document = $preview ? ( $form['draft'] ?? $this->forms->published( $form_id ) ) : $this->forms->published( $form_id );
		if ( null === $document || ( ! $preview && 'active' !== $form['status'] ) ) {
			return SubmissionResult::error( 'unavailable', __( 'This form is currently not available.', 'sw-forms' ) );
		}
		$document = Wpml::translate( $document, $form_id );
		$settings = is_array( $document['settings'] ?? null ) ? $document['settings'] : array();
		$ip       = (string) ( $request['ip'] ?? '' );

		$tree   = Tree::build( is_array( $document['fields'] ?? null ) ? $document['fields'] : array(), $this->registry );
		$values = ( new ValueCollector( $tree, $this->registry ) )->collect( $raw );

		$token_id = '';
		// Trusted: programmatic submissions via swf_submit().
		if ( ! $preview && empty( $request['trusted'] ) ) {
			$check = $this->spam->check( $form_id, is_array( $settings['spam'] ?? null ) ? $settings['spam'] : array(), (string) ( $request['honeypot'] ?? '' ), (string) ( $request['token'] ?? '' ), $ip );
			if ( 'spam' === $check['status'] ) {
				// Bots get the normal confirmation so they learn nothing.
				return new SubmissionResult( SubmissionResult::SUCCESS, '', wp_kses_post( (string) ( $settings['confirmation']['message'] ?? '' ) ) );
			}
			if ( 'ok' !== $check['status'] ) {
				return new SubmissionResult( SubmissionResult::ERROR, $check['status'], self::spam_message( $check['status'] ), array(), '', 0, $values );
			}
			$token_id = $check['token_id'];
		}

		$visibility = ( new Evaluator( $tree ) )->evaluate( $values );
		$errors     = ( new SubmissionValidator( $tree, $this->registry ) )->validate( $values, $visibility );
		/**
		 * Filters the field errors. Add an entry `path => message` to reject a submission.
		 *
		 * @since 0.1.0
		 *
		 * @param array<string, string> $errors  Errors per path (`id`, `repeater[0].id`).
		 * @param array<string, mixed>  $values  Clean values keyed by element id.
		 * @param int                   $form_id Form id.
		 */
		$errors = (array) apply_filters( 'swf_validation_errors', $errors, $values, $form_id );
		if ( array() !== $errors ) {
			return new SubmissionResult(
				SubmissionResult::INVALID,
				'invalid',
				__( 'Please correct the marked fields.', 'sw-forms' ),
				array_map( 'strval', $errors ),
				'',
				0,
				$values
			);
		}
		if ( $preview ) {
			return new SubmissionResult( SubmissionResult::SUCCESS, 'preview', esc_html__( 'Preview: all entries are valid. Nothing was stored or sent.', 'sw-forms' ) );
		}

		$builder = new DataBuilder( $tree );
		/**
		 * Filters the data before it is stored and passed to actions.
		 *
		 * @since 0.1.0
		 *
		 * @param array<string, mixed> $data    Data keyed by field key.
		 * @param array<string, mixed> $values  Clean values keyed by element id.
		 * @param int                  $form_id Form id.
		 */
		$data     = (array) apply_filters( 'swf_submission_data', $builder->build( $values, $visibility ), $values, $form_id );
		$entry_id = 0;
		if ( ! empty( $settings['storeEntries'] ) ) {
			$entry_id = $this->entries->insert(
				$form_id,
				$form['current_version'],
				$data,
				array( 'sourceUrl' => esc_url_raw( (string) ( $request['source_url'] ?? '' ) ) ),
				// The index holds the display text (option labels, Yes/No): what people see and search for.
				$builder->flatten(
					$values,
					$visibility,
					function ( string $id, array $element, mixed $value ): string {
						$type = $this->registry->get( (string) ( $element['type'] ?? '' ) );
						return null === $type ? DataBuilder::stringify( $value ) : $type->format_text( $value, $element );
					}
				),
				'hash' === Settings::all()['ip_mode'] && '' !== $ip ? hash_hmac( 'sha256', $ip, Settings::secret() ) : '',
				(int) ( $request['user_id'] ?? 0 )
			);
			if ( 0 === $entry_id ) {
				return SubmissionResult::error( 'storage', __( 'Your entry could not be saved. Please try again later.', 'sw-forms' ) );
			}
		}
		$this->spam->record_success( $form_id, $token_id, $ip );

		$submission = new Submission( $form, $form['current_version'], $document, $tree, $values, $visibility, $data, $entry_id, $this->entries );
		if ( $entry_id > 0 ) {
			/**
			 * Fires after an entry was stored, before actions run.
			 *
			 * @since 0.1.0
			 *
			 * @param int        $entry_id   Entry id.
			 * @param Submission $submission The submission.
			 */
			do_action( 'swf_entry_created', $entry_id, $submission );
		}
		$this->run_actions( $submission );
		/**
		 * Fires after all actions of a submission ran.
		 *
		 * @since 0.1.0
		 *
		 * @param Submission $submission The submission.
		 */
		do_action( 'swf_after_actions', $submission );

		return $this->confirmation( $submission, $settings );
	}

	private function run_actions( Submission $submission ): void {
		$actions = array( new EmailNotifications( $this->registry ) );
		/**
		 * Actions executed after a valid submission.
		 *
		 * @since 0.1.0
		 *
		 * @param list<ActionInterface> $actions    Actions.
		 * @param Submission            $submission The submission.
		 */
		$actions = apply_filters( 'swf_register_actions', $actions, $submission );
		foreach ( is_array( $actions ) ? $actions : array() as $action ) {
			if ( ! $action instanceof ActionInterface ) {
				continue;
			}
			try {
				$action->run( $submission );
			} catch ( \Throwable $throwable ) {
				$submission->log( $action->type(), '', 'failed', $throwable->getMessage() );
			}
		}
	}

	/**
	 * @param array<string, mixed> $settings
	 */
	private function confirmation( Submission $submission, array $settings ): SubmissionResult {
		$confirmation = is_array( $settings['confirmation'] ?? null ) ? $settings['confirmation'] : array();
		$resolver     = new MergeTagResolver( $submission, $this->registry );
		$redirect     = '';
		$type         = (string) ( $confirmation['type'] ?? 'message' );
		if ( 'redirect' === $type ) {
			$redirect = esc_url_raw( $resolver->replace( (string) ( $confirmation['url'] ?? '' ), MergeTagResolver::CONTEXT_URL ) );
		} elseif ( 'page' === $type && (int) ( $confirmation['pageId'] ?? 0 ) > 0 ) {
			$redirect = (string) get_permalink( (int) $confirmation['pageId'] );
		}
		$message = wp_kses_post( $resolver->replace( (string) ( $confirmation['message'] ?? '' ), MergeTagResolver::CONTEXT_HTML ) );
		return new SubmissionResult( SubmissionResult::SUCCESS, '', $message, array(), $redirect, $submission->entry_id );
	}

	private static function spam_message( string $status ): string {
		return match ( $status ) {
			'expired'      => __( 'The page was open for a long time. Please submit the form again.', 'sw-forms' ),
			'too_fast'     => __( 'That was very fast. Please wait a moment and submit again.', 'sw-forms' ),
			'duplicate'    => __( 'This form has already been submitted.', 'sw-forms' ),
			'rate_limited' => __( 'Too many submissions. Please try again in a few minutes.', 'sw-forms' ),
			default        => __( 'The form could not be verified. Please reload the page and try again.', 'sw-forms' ),
		};
	}
}
