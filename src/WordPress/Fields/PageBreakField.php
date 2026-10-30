<?php
/**
 * Root-level page boundary; sections are rendered by FormView, not a separate document.
 *
 * @package SWForms
 * @since 0.5.0
 */

declare(strict_types=1);
namespace SWForms\WordPress\Fields;

use SWForms\WordPress\Frontend\RenderContext;

final class PageBreakField extends AbstractFieldType {
	public function type(): string {
		return 'page-break'; }
	public function label(): string {
		return __( 'Page break', 'sw-forms' ); }
	public function icon(): string {
		return 'r_auto_stories'; }
	public function category(): string {
		return 'layout'; }
	public function is_data(): bool {
		return false; }
	public function supports(): array {
		return array( 'label', 'adminLabel' ); }
	public function settings_schema(): array {
		return array(); }
	public function render( array $element, RenderContext $context ): string {
		return ''; }
}
