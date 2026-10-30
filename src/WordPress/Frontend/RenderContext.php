<?php
/**
 * Rendering state handed to the field types: names, DOM ids, values, errors, visibility.
 *
 * One context exists per scope (form level or one repeater row). Template rows (for the
 * frontend script) use the index placeholder `__INDEX__`.
 *
 * Public extension API; stable from 0.4.0.
 *
 * @since 0.1.0
 * @package SWForms
 */

declare(strict_types=1);

namespace SWForms\WordPress\Frontend;

use SWForms\Core\Logic\Visibility;

final class RenderContext {

	public const INDEX_PLACEHOLDER = '__INDEX__';

	/**
	 * @param array<string, mixed>  $values Values of this scope keyed by element id.
	 * @param array<string, string> $errors Error messages of the whole form keyed by path.
	 */
	public function __construct(
		private readonly Renderer $renderer,
		public readonly int $form_id,
		private readonly array $values,
		private readonly array $errors,
		private readonly ?Visibility $visibility,
		private readonly string $path_prefix = '',
		private readonly string $name_prefix = 'swf',
		private readonly string $id_prefix = '',
		private readonly string $row_index = '',
	) {}

	/** Request name, e.g. `swf[abc]` or `swf[rep][0][abc][]`. */
	public function name( string $id, string $suffix = '' ): string {
		return $this->name_prefix . '[' . $id . ']' . $suffix;
	}

	public function dom_id( string $id ): string {
		return ( '' === $this->id_prefix ? 'swf-' . $this->form_id : $this->id_prefix ) . '-' . $id;
	}

	/** Logic/error path, e.g. `abc` or `rep[0].abc`. */
	public function path( string $id ): string {
		return $this->path_prefix . $id;
	}

	public function value( string $id ): mixed {
		return $this->values[ $id ] ?? null;
	}

	public function error( string $id ): string {
		return $this->errors[ $this->path( $id ) ] ?? '';
	}

	/** Template rows are always rendered visible; the script evaluates them after cloning. */
	public function hidden( string $id ): bool {
		return null !== $this->visibility && ! $this->visibility->isVisible( $id, $this->path_prefix );
	}

	public function row_index(): string {
		return $this->row_index;
	}

	public function disabled( string $id ): bool {
		return null !== $this->visibility && ! $this->visibility->isEnabled( $id, $this->path_prefix );
	}

	public function required( string $id ): bool {
		return null !== $this->visibility && $this->visibility->isRequired( $id, $this->path_prefix );
	}

	/**
	 * Renders child elements in this scope.
	 *
	 * @param list<string> $ids
	 */
	public function children( array $ids ): string {
		return $this->renderer->render_ids( $ids, $this );
	}

	/**
	 * Context of one repeater row.
	 *
	 * @param array<string, mixed> $row_values
	 */
	public function row( string $repeater_id, string $index, array $row_values, bool $template = false ): self {
		return new self(
			$this->renderer,
			$this->form_id,
			$template ? $this->renderer->row_defaults( $repeater_id ) : $row_values,
			$template ? array() : $this->errors,
			$template ? null : $this->visibility,
			$this->path_prefix . $repeater_id . '[' . $index . '].',
			$this->name_prefix . '[' . $repeater_id . '][' . $index . ']',
			$this->dom_id( $repeater_id ) . '-' . $index,
			$index
		);
	}
}
