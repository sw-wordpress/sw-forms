<?php
/**
 * Entries: list per form, detail, status changes, deletion, bulk actions.
 *
 * @package SWForms
 */

declare(strict_types=1);

namespace SWForms\WordPress\Rest;

use SWForms\Core\Form\Tree;
use SWForms\WordPress\Entries\EntryPresenter;
use SWForms\WordPress\Entries\EntryRepository;
use SWForms\WordPress\Fields\FieldRegistry;
use SWForms\WordPress\Forms\FormRepository;
use SWForms\WordPress\Support\Capabilities;

final class EntriesController extends BaseController {

	private const LIST_COLUMNS = 5;
	public const BULK_ACTIONS  = array( 'read', 'unread', 'star', 'unstar', 'spam', 'trash', 'restore', 'delete' );

	private EntryRepository $entries;
	private FormRepository $forms;

	public function __construct() {
		$this->entries = new EntryRepository();
		$this->forms   = new FormRepository();
	}

	public function register_routes(): void {
		$view   = $this->can( Capabilities::VIEW_ENTRIES );
		$manage = $this->can( Capabilities::MANAGE_ENTRIES );
		register_rest_route(
			self::NAMESPACE,
			'/forms/(?P<id>\d+)/entries',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_entries' ),
				'permission_callback' => $view,
				'args'                => $this->id_arg() + $this->pagination_args() + array(
					'filter' => array(
						'type'    => 'string',
						'enum'    => EntryRepository::FILTERS,
						'default' => 'inbox',
					),
					'search' => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'order'  => array(
						'type'    => 'string',
						'enum'    => array( 'asc', 'desc' ),
						'default' => 'desc',
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/entries/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_entry' ),
					'permission_callback' => $view,
					'args'                => $this->id_arg(),
				),
				array(
					'methods'             => 'PATCH',
					'callback'            => array( $this, 'update_entry' ),
					'permission_callback' => $manage,
					'args'                => $this->id_arg() + array(
						'status'  => array(
							'type' => 'string',
							'enum' => EntryRepository::STATUSES,
						),
						'starred' => array( 'type' => 'boolean' ),
					),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'delete_entry' ),
					'permission_callback' => $manage,
					'args'                => $this->id_arg(),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/entries/bulk',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'bulk' ),
				'permission_callback' => $manage,
				'args'                => array(
					'ids'    => array(
						'type'     => 'array',
						'items'    => array( 'type' => 'integer' ),
						'required' => true,
						'maxItems' => 200,
					),
					'action' => array(
						'type'     => 'string',
						'enum'     => self::BULK_ACTIONS,
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function list_entries( \WP_REST_Request $request ) {
		$form_id = (int) $request['id'];
		$form    = $this->forms->find( $form_id );
		if ( null === $form ) {
			return $this->error( 'swf_not_found', __( 'Form not found.', 'sw-forms' ), 404 );
		}
		$per_page = (int) $request['per_page'];
		$result   = $this->entries->search( $form_id, (string) $request['filter'], (string) $request['search'], (int) $request['page'], $per_page, (string) $request['order'] );
		$document = $this->forms->published( $form_id ) ?? $form['draft'] ?? array();
		return new \WP_REST_Response(
			array(
				'items'   => array_map( static fn ( array $item ): array => array_diff_key( $item, array( 'data' => true ) ), $result['items'] ),
				'total'   => $result['total'],
				'pages'   => (int) max( 1, ceil( $result['total'] / max( 1, $per_page ) ) ),
				'counts'  => $this->entries->counts( $form_id ),
				'columns' => $this->columns( is_array( $document['fields'] ?? null ) ? $document['fields'] : array() ),
			)
		);
	}

	/**
	 * First form level fields of the current version as list columns.
	 *
	 * @param list<array<string, mixed>> $fields
	 * @return list<array{id: string, label: string}>
	 */
	private function columns( array $fields ): array {
		$tree    = Tree::build( $fields, FieldRegistry::instance() );
		$columns = array();
		foreach ( $tree->ids() as $id ) {
			if ( count( $columns ) >= self::LIST_COLUMNS ) {
				break;
			}
			$type = $tree->node( $id )['settings']['inputType'] ?? '';
			if ( $tree->isLeafData( $id ) && null === $tree->scopeOf( $id ) && 'password' !== $type ) {
				$columns[] = array(
					'id'    => $id,
					'label' => EntryPresenter::label( $tree->node( $id ) ),
				);
			}
		}
		return $columns;
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_entry( \WP_REST_Request $request ) {
		$entry = $this->entries->find( (int) $request['id'] );
		if ( null === $entry ) {
			return $this->error( 'swf_not_found', __( 'Entry not found.', 'sw-forms' ), 404 );
		}
		$document = $this->forms->version( $entry['formId'], $entry['formVersion'] ) ?? array();
		$user     = $entry['userId'] > 0 ? get_userdata( $entry['userId'] ) : false;
		return new \WP_REST_Response(
			array(
				'entry'  => $entry,
				'fields' => ( new EntryPresenter( FieldRegistry::instance() ) )->present( is_array( $document['fields'] ?? null ) ? $document['fields'] : array(), $entry['data'] ),
				'user'   => $user instanceof \WP_User ? $user->display_name : '',
				'log'    => $this->entries->logs( $entry['id'] ),
			)
		);
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_entry( \WP_REST_Request $request ) {
		$id = (int) $request['id'];
		if ( null === $this->entries->find( $id ) ) {
			return $this->error( 'swf_not_found', __( 'Entry not found.', 'sw-forms' ), 404 );
		}
		$changes = array();
		if ( null !== $request['status'] ) {
			$changes['status'] = (string) $request['status'];
		}
		if ( null !== $request['starred'] ) {
			$changes['starred'] = (bool) $request['starred'];
		}
		$this->entries->update( $id, $changes );
		return new \WP_REST_Response( $this->entries->find( $id ) );
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_entry( \WP_REST_Request $request ) {
		$id = (int) $request['id'];
		if ( null === $this->entries->find( $id ) ) {
			return $this->error( 'swf_not_found', __( 'Entry not found.', 'sw-forms' ), 404 );
		}
		$this->entries->delete( $id );
		return new \WP_REST_Response( array( 'deleted' => true ) );
	}

	public function bulk( \WP_REST_Request $request ): \WP_REST_Response {
		$action = (string) $request['action'];
		$count  = 0;
		foreach ( array_unique( array_map( 'absint', (array) $request['ids'] ) ) as $id ) {
			if ( 0 === $id || null === $this->entries->find( $id ) ) {
				continue;
			}
			match ( $action ) {
				'read', 'restore' => $this->entries->update( $id, array( 'status' => 'read' ) ),
				'unread'          => $this->entries->update( $id, array( 'status' => 'unread' ) ),
				'star'            => $this->entries->update( $id, array( 'starred' => true ) ),
				'unstar'          => $this->entries->update( $id, array( 'starred' => false ) ),
				'spam', 'trash'   => $this->entries->update( $id, array( 'status' => $action ) ),
				'delete'          => $this->entries->delete( $id ),
				default           => null,
			};
			++$count;
		}
		return new \WP_REST_Response( array( 'affected' => $count ) );
	}
}
