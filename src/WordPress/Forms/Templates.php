<?php
/**
 * Built-in, secret-free starter documents. Identifiers are stable within each document.
 * `description` and `icon` (Material Symbols name) are display hints for the library dialog.
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
				'id'          => 'contact',
				'title'       => __( 'Contact', 'sw-forms' ),
				'description' => __( 'Name, email and message: the classic contact form.', 'sw-forms' ),
				'icon'        => 'r_mail',
				'document'    => array(
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
				'id'          => 'registration',
				'title'       => __( 'Registration', 'sw-forms' ),
				'description' => __( 'Event or course registration with number of places.', 'sw-forms' ),
				'icon'        => 'r_event_available',
				'document'    => array(
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
				'id'          => 'survey',
				'title'       => __( 'Survey', 'sw-forms' ),
				'description' => __( 'Star rating with optional free-text feedback.', 'sw-forms' ),
				'icon'        => 'r_star_rate',
				'document'    => array(
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
			array(
				'id'          => 'travel',
				'title'       => __( 'Travel request', 'sw-forms' ),
				'description' => __( 'Multi-step form: trip, wishes and contact details with a progress display.', 'sw-forms' ),
				'icon'        => 'r_flight_takeoff',
				'document'    => array(
					'fields'   => array(
						array(
							'id'       => 'destination',
							'type'     => 'input',
							'key'      => 'destination',
							'label'    => __( 'Destination', 'sw-forms' ),
							'required' => true,
						),
						array(
							'id'       => 'period',
							'type'     => 'date-interval',
							'key'      => 'period',
							'label'    => __( 'Travel period', 'sw-forms' ),
							'required' => true,
						),
						array(
							'id'       => 'travellers',
							'type'     => 'input',
							'key'      => 'travellers',
							'label'    => __( 'Travellers', 'sw-forms' ),
							'settings' => array(
								'inputType' => 'number',
								'min'       => 1,
								'integer'   => true,
							),
							'required' => true,
						),
						array(
							'id'    => 'step-wishes',
							'type'  => 'page-break',
							'label' => __( 'Wishes', 'sw-forms' ),
						),
						array(
							'id'    => 'wishes',
							'type'  => 'textarea',
							'key'   => 'wishes',
							'label' => __( 'Special wishes', 'sw-forms' ),
						),
						array(
							'id'    => 'step-contact',
							'type'  => 'page-break',
							'label' => __( 'Contact details', 'sw-forms' ),
						),
						$name,
						$email,
					),
					'settings' => array(
						'notifications' => array(),
						'steps'         => array(
							'enabled'    => true,
							'firstLabel' => __( 'Trip', 'sw-forms' ),
						),
					),
				),
			),
		);
	}
}
