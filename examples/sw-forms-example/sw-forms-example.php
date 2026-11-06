<?php
/**
 * Plugin Name: SW Forms API Example
 * Description: Example reference field, validator, merge tag and logged action for SW Forms 0.4+.
 * Version: 1.0.0
 * Requires Plugins: sw-forms
 * Text Domain: sw-forms-example
 */
// Keep PHP type handling consistent with the host API. The namespace prevents add-on name collisions.
declare(strict_types=1);
namespace SWFormsExample;

// This file is a WordPress plugin entry point, not a directly callable web endpoint.
defined( 'ABSPATH' ) || exit;

/**
 * Attach registration callbacks after plugins have loaded, before any registry is first used.
 * The Requires Plugins header helps WordPress manage the dependency; the runtime guard below
 * also handles older WordPress versions and a missing/deactivated host gracefully.
 */
function boot(): void {
	// Tests may call boot() explicitly; do not register callbacks twice in the same request.
	static $booted = false;
	if ( $booted ) {
		return;
	}
	if ( ! interface_exists( \SWForms\WordPress\Fields\FieldTypeInterface::class ) ) {
		return;
	}
	// Set this only after the dependency check, so an early unsuccessful call can be retried.
	$booted = true;
	// Delay loading the subclass until its SW Forms parent class is available.
	require_once __DIR__ . '/ReferenceField.php';
	// Register assets on init; registration alone does not load JavaScript on public pages.
	add_action( 'init', __NAMESPACE__ . '\\register_assets' );
	// One PHP definition supplies the builder schema, rendering, collection and formatting.
	add_action( 'swf_register_field_types', static function ( $registry ): void { $registry->register( new ReferenceField() ); } );
	// Validators are registered separately so they can also extend existing field types.
	add_action( 'swf_register_validators', static function ( $registry ): void {
		$registry->register( new class() implements \SWForms\WordPress\Validation\ValidatorInterface {
			// The registry uses this unique identifier for replacement, not for selecting fields.
			public function slug(): string { return 'example-reference'; }
			// Limit this rule to our field type; unrelated inputs must keep their own validation.
			public function supports( array $element ): bool { return 'example-reference' === $element['type']; }
			// SW Forms already handles required/empty fields and skips hidden or disabled fields.
			// Return [] on success, or structured errors. A translated message supports custom codes.
			public function validate( mixed $value, array $element ): array {
				// Both anchors require an exact match; D also rejects a trailing newline.
				return preg_match( '/^REF-[0-9]{3}$/D', (string) $value ) ? array() : array( array( 'code' => 'example_reference', 'message' => __( 'Use REF- followed by three digits.', 'sw-forms-example' ) ) );
			}
		} );
	} );
	// This registers {example_reference:reference}; it does not add a field to the form.
	add_action( 'swf_register_merge_tags', static function ( $registry ): void {
		$registry->register( new class() implements \SWForms\WordPress\Template\MergeTagInterface {
			// The name is the part before the colon in the merge tag.
			public function name(): string { return 'example_reference'; }
			public function resolve( string $argument, \SWForms\WordPress\Submission\Submission $submission ): ?string {
				// Submission data uses published field KEYS, whereas incoming values use element IDs.
				// This example expects key `reference`. Return plain text: the host escapes HTML/URLs.
				// Empty means a known argument without data; null leaves an unknown argument untouched.
				return 'reference' === $argument ? ( is_scalar( $submission->data['reference'] ?? null ) ? (string) $submission->data['reference'] : '' ) : null;
			}
		} );
	} );
	// Actions use a filter: append to the supplied list to retain e-mails and other actions.
	add_filter( 'swf_register_actions', static function ( array $actions ): array {
		$actions[] = new class() implements \SWForms\WordPress\Actions\ActionInterface {
			// This identifier groups the action's messages in the entry log.
			public function type(): string { return 'example'; }
			// Runs after validation/storage, never for preview or rejected submissions.
			public function run( \SWForms\WordPress\Submission\Submission $submission ): void {
				// Actions receive every valid submission; ignore forms without a scalar reference.
				if ( is_string( $submission->data['reference'] ?? null ) && '' !== $submission->data['reference'] ) {
					// No network side effect: log locally. The empty action ID means no per-form instance.
					// A real CRM action would need its own configuration, retry and idempotency policy.
					$submission->log( $this->type(), '', 'success', 'Reference received: ' . $submission->data['reference'] );
				}
			}
		};
		// Returning the complete list is essential: WordPress filters replace their input.
		return $actions;
	} );
}

/**
 * The field's builder_script() returns this exact handle. SW Forms enqueues it on its admin
 * page; the dependency ensures the registration queue exists before builder.js executes.
 * plugins_url() supports relocated plugin directories; the version invalidates browser caches
 * when updated, and the final true asks WordPress to print the script in the footer.
 */
function register_assets(): void {
	wp_register_script( 'sw-forms-example', plugins_url( 'builder.js', __FILE__ ), array( 'sw-forms-builder-api' ), '1.0.0', true );
}

// Priority 20 allows the host to bootstrap first, regardless of plugin file ordering.
add_action( 'plugins_loaded', __NAMESPACE__ . '\\boot', 20 );
