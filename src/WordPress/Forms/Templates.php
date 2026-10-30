<?php
/**
 * Built-in, secret-free starter documents. Identifiers are stable within each document.
 *
 * @package SWForms
 * @since 0.5.0
 */

declare(strict_types=1);
namespace SWForms\WordPress\Forms;

final class Templates {
	/** @return list<array<string, mixed>> */
	public static function all(): array {
		$name  = array(
			'id'       => 'name',
			'type'     => 'input',
			'key'      => 'name',
			'label'    => __( 'Name', 'sw-forms' ),
			'required' => true,
		);
		$email = array(
			'id'       => 'email',
			'type'     => 'input',
			'key'      => 'email',
			'label'    => __( 'Email', 'sw-forms' ),
			'required' => true,
			'settings' => array( 'inputType' => 'email' ),
		);
		return array(
			array(
				'id'       => 'contact',
				'title'    => __( 'Contact', 'sw-forms' ),
				'document' => array(
					'fields'   => array(
						$name,
						$email,
						array(
							'id'       => 'message',
							'type'     => 'textarea',
							'key'      => 'message',
							'label'    => __( 'Message', 'sw-forms' ),
							'required' => true,
						),
					),
					'settings' => array( 'notifications' => array() ),
				),
			),
			array(
				'id'       => 'registration',
				'title'    => __( 'Registration', 'sw-forms' ),
				'document' => array(
					'fields'   => array(
						$name,
						$email,
						array(
							'id'       => 'places',
							'type'     => 'input',
							'key'      => 'places',
							'label'    => __( 'Places', 'sw-forms' ),
							'settings' => array(
								'inputType' => 'number',
								'min'       => 1,
								'integer'   => true,
							),
							'required' => true,
						),
					),
					'settings' => array( 'notifications' => array() ),
				),
			),
			array(
				'id'       => 'survey',
				'title'    => __( 'Survey', 'sw-forms' ),
				'document' => array(
					'fields'   => array(
						array(
							'id'       => 'rating',
							'type'     => 'rating',
							'key'      => 'rating',
							'label'    => __( 'Rating', 'sw-forms' ),
							'required' => true,
						),
						array(
							'id'    => 'feedback',
							'type'  => 'textarea',
							'key'   => 'feedback',
							'label' => __( 'Feedback', 'sw-forms' ),
						),
					),
					'settings' => array( 'notifications' => array() ),
				),
			),
		);
	}
}
