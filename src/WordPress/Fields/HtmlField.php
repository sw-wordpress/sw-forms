<?php
/**
 * Safe informational markup, without submission data.
 *
 * @package SWForms
 * @since 0.5.0
 */

declare(strict_types=1);
namespace SWForms\WordPress\Fields;

use SWForms\WordPress\Frontend\RenderContext;

final class HtmlField extends AbstractFieldType {
	public function type(): string {
		return 'html'; }
	public function label(): string {
		return __( 'HTML / text', 'sw-forms' ); }
	public function icon(): string {
		return 'r_article'; }
	public function category(): string {
		return 'layout'; }
	public function is_data(): bool {
		return false; }
	public function supports(): array {
		return array( 'adminLabel', 'cssClass', 'logic' ); }
	public function settings_schema(): array {
		return array(
			array(
				'name'    => 'content',
				'type'    => 'html',
				'label'   => __( 'Content', 'sw-forms' ),
				'default' => '',
			),
		);
	}
	public function render( array $element, RenderContext $context ): string {
		return $this->wrap( $element, $context, wpautop( wp_kses( (string) $this->setting( $element, 'content', '' ), self::inline_html() ) ) );
	}
}
