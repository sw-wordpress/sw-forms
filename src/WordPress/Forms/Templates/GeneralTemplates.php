<?php
/**
 * Starter documents of the "General" category (docs/schema.md).
 *
 * Field ids double as condition references and stay stable; keys are the data contract of the
 * created form. Every document passes normalization plus schema validation, which the catalog
 * test asserts for all templates.
 *
 * @package SWForms
 * @since 0.7.0
 */

declare(strict_types=1);
namespace SWForms\WordPress\Forms\Templates;

use SWForms\WordPress\Forms\TemplateElements as TE;

final class GeneralTemplates {

	/**
	 * @return list<array<string, mixed>>
	 */
	public static function all(): array {
		return array( self::contact(), self::callback(), self::feedback(), self::newsletter(), self::appointment() );
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function contact(): array {
		return array(
			'id'          => 'contact',
			'version'     => '2.0.0',
			'icon'        => 'r_mark_email_unread',
			'title'       => __( 'Contact form', 'sw-forms' ),
			'description' => __( 'Salutation, name, e-mail, subject and message in two columns.', 'sw-forms' ),
			'tags'        => array( 'contact', 'message', 'e-mail', 'support' ),
			'document'    => array(
				'fields'   => array(
					TE::half_row(
						'row_salutation',
						TE::select( 'salutation', __( 'Salutation', 'sw-forms' ), TE::salutations() ),
						TE::text(
							'first_name',
							__( 'First name', 'sw-forms' ),
							array(
								'minLength'    => 1,
								'maxLength'    => 100,
								'autocomplete' => 'given-name',
							),
							array( 'required' => true )
						)
					),
					TE::half_row(
						'row_last_name',
						TE::text(
							'last_name',
							__( 'Last name', 'sw-forms' ),
							array(
								'minLength'    => 1,
								'maxLength'    => 100,
								'autocomplete' => 'family-name',
							),
							array( 'required' => true )
						),
						TE::email( 'email', __( 'E-mail', 'sw-forms' ), array( 'autocomplete' => 'email' ), array( 'required' => true ) )
					),
					// The empty second cell keeps the telephone field at half width.
					TE::row( 'row_phone', TE::cell( 6, TE::telephone( 'phone', __( 'Telephone', 'sw-forms' ), array( 'autocomplete' => 'tel' ) ) ), TE::cell( 6 ) ),
					TE::text(
						'subject',
						__( 'Subject', 'sw-forms' ),
						array(
							'minLength' => 3,
							'maxLength' => 150,
						),
						array( 'required' => true )
					),
					TE::textarea(
						'message',
						__( 'Message', 'sw-forms' ),
						array(
							'minLength' => 10,
							'maxLength' => 5000,
						),
						array(
							'required'    => true,
							'description' => __( 'Please describe your request in as much detail as possible.', 'sw-forms' ),
						)
					),
					self::privacy_note(),
				),
				'settings' => TE::settings(),
			),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function callback(): array {
		return array(
			'id'          => 'callback',
			'version'     => '1.0.0',
			'icon'        => 'r_call',
			'title'       => __( 'Request a callback', 'sw-forms' ),
			'description' => __( 'Name, number and a preferred slot; the time window only appears once a date is set.', 'sw-forms' ),
			'tags'        => array( 'phone', 'call back', 'appointment', 'service' ),
			'document'    => array(
				'fields'   => array(
					TE::text( 'name', __( 'Name', 'sw-forms' ), array( 'maxLength' => 150 ), array( 'required' => true ) ),
					TE::half_row(
						'row_contact',
						TE::telephone( 'phone', __( 'Telephone', 'sw-forms' ), array( 'autocomplete' => 'tel' ), array( 'required' => true ) ),
						TE::email( 'email', __( 'E-mail', 'sw-forms' ), array( 'autocomplete' => 'email' ) )
					),
					TE::half_row(
						'row_slot',
						TE::date(
							'preferred_date',
							__( 'Preferred date', 'sw-forms' ),
							// Relative bounds keep cached pages valid: yesterday is rejected on submission.
							array( 'minDate' => 'today' )
						),
						TE::select(
							'time_window',
							__( 'Preferred time of day', 'sw-forms' ),
							TE::day_parts(),
							array(),
							array( 'logic' => TE::show( array( TE::rule( 'preferred_date', 'notempty' ) ) ) )
						)
					),
					TE::textarea( 'request', __( 'What is it about?', 'sw-forms' ), array( 'maxLength' => 2000 ) ),
					self::privacy_note(),
				),
				'settings' => TE::settings(),
			),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function feedback(): array {
		return array(
			'id'          => 'feedback',
			'version'     => '1.0.0',
			'icon'        => 'r_thumb_up',
			'title'       => __( 'Feedback and rating', 'sw-forms' ),
			'description' => __( 'Star rating, category and recommendation; the e-mail field only appears for people who want to be contacted.', 'sw-forms' ),
			'tags'        => array( 'feedback', 'rating', 'review', 'survey', 'stars' ),
			'document'    => array(
				'fields'   => array(
					TE::rating( 'rating', __( 'Overall rating', 'sw-forms' ), array( 'max' => 5 ), array( 'required' => true ) ),
					TE::half_row(
						'row_topic',
						TE::select(
							'category',
							__( 'What is your feedback about?', 'sw-forms' ),
							array(
								TE::choice( 'product', __( 'Product', 'sw-forms' ) ),
								TE::choice( 'service', __( 'Service', 'sw-forms' ) ),
								TE::choice( 'website', __( 'Website', 'sw-forms' ) ),
								TE::choice( 'other', __( 'Other', 'sw-forms' ) ),
							)
						),
						TE::radio(
							'recommend',
							__( 'Would you recommend us?', 'sw-forms' ),
							array(
								TE::choice( 'yes', __( 'Yes', 'sw-forms' ) ),
								TE::choice( 'maybe', __( 'Maybe', 'sw-forms' ) ),
								TE::choice( 'no', __( 'No', 'sw-forms' ) ),
							),
							array( 'presentation' => 'buttons' ),
							array( 'required' => true )
						)
					),
					TE::textarea( 'liked', __( 'What did you like?', 'sw-forms' ), array( 'maxLength' => 3000 ) ),
					TE::textarea( 'improve', __( 'What can we improve?', 'sw-forms' ), array( 'maxLength' => 3000 ) ),
					// Highlighting low ratings declaratively: an extra note, the field stays optional.
					TE::note(
						'low_rating_hint',
						__( 'Your rating tells us something went wrong. A concrete example helps us fix it – but you can also submit the form without it.', 'sw-forms' ),
						array( 'logic' => TE::show( array( TE::rule( 'rating', 'lt', '4' ) ) ) )
					),
					TE::radio(
						'contact_allowed',
						__( 'May we contact you about this?', 'sw-forms' ),
						array(
							TE::choice( 'yes', __( 'Yes', 'sw-forms' ) ),
							TE::choice( 'no', __( 'No', 'sw-forms' ) ),
						),
						array( 'presentation' => 'buttons' )
					),
					// Hidden fields are never validated, so a static `required` plus a show rule
					// is the conditional requirement; a second logic action is not possible.
					TE::email(
						'email',
						__( 'E-mail', 'sw-forms' ),
						array( 'autocomplete' => 'email' ),
						array(
							'required' => true,
							'logic'    => TE::show( array( TE::rule( 'contact_allowed', 'is', 'yes' ) ) ),
						)
					),
				),
				'settings' => TE::settings(),
			),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function newsletter(): array {
		return array(
			'id'          => 'newsletter',
			'version'     => '1.0.0',
			'icon'        => 'r_rss_feed',
			'title'       => __( 'Newsletter sign-up', 'sw-forms' ),
			'description' => __( 'E-mail first, optional name and topic selection as checkbox cards.', 'sw-forms' ),
			'tags'        => array( 'newsletter', 'subscription', 'marketing', 'e-mail' ),
			'document'    => array(
				'fields'   => array(
					TE::email(
						'email',
						__( 'E-mail address', 'sw-forms' ),
						array(
							'autocomplete'      => 'email',
							'emailConfirmation' => true,
						),
						array(
							'required'    => true,
							'description' => __( 'You will receive a confirmation e-mail before the subscription starts.', 'sw-forms' ),
						)
					),
					TE::half_row(
						'row_name',
						TE::text(
							'first_name',
							__( 'First name', 'sw-forms' ),
							array(
								'maxLength'    => 100,
								'autocomplete' => 'given-name',
							)
						),
						TE::text(
							'last_name',
							__( 'Last name', 'sw-forms' ),
							array(
								'maxLength'    => 100,
								'autocomplete' => 'family-name',
							)
						)
					),
					TE::checkbox_group(
						'interests',
						__( 'Topics you are interested in', 'sw-forms' ),
						array(
							TE::choice( 'news', __( 'News', 'sw-forms' ) ),
							TE::choice( 'events', __( 'Events', 'sw-forms' ) ),
							TE::choice( 'offers', __( 'Offers', 'sw-forms' ) ),
							TE::choice( 'articles', __( 'Articles', 'sw-forms' ) ),
						),
						array( 'presentation' => 'buttons' )
					),
					self::privacy_note(),
				),
				'settings' => TE::settings(),
			),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function appointment(): array {
		return array(
			'id'          => 'appointment',
			'version'     => '1.0.0',
			'icon'        => 'r_event_available',
			'title'       => __( 'Appointment request', 'sw-forms' ),
			'description' => __( 'Three steps: slot, contact data and confirmation. An inquiry, not a binding booking.', 'sw-forms' ),
			'tags'        => array( 'appointment', 'booking', 'meeting', 'calendar', 'multi-step' ),
			'document'    => array(
				'fields'   => array(
					TE::select(
						'service',
						__( 'What do you need?', 'sw-forms' ),
						array(
							TE::choice( 'consulting', __( 'Consulting', 'sw-forms' ) ),
							TE::choice( 'support', __( 'Support', 'sw-forms' ) ),
							TE::choice( 'intro', __( 'Introductory meeting', 'sw-forms' ) ),
							TE::choice( 'other', __( 'Other', 'sw-forms' ) ),
						),
						array(),
						array( 'required' => true )
					),
					TE::text(
						'service_detail',
						__( 'Describe the service you need', 'sw-forms' ),
						array( 'maxLength' => 200 ),
						array(
							'required' => true,
							'logic'    => TE::show( array( TE::rule( 'service', 'is', 'other' ) ) ),
						)
					),
					TE::half_row(
						'row_slot',
						TE::date( 'preferred_date', __( 'Preferred date', 'sw-forms' ), array( 'minDate' => 'today' ), array( 'required' => true ) ),
						TE::time( 'preferred_time', __( 'Preferred time', 'sw-forms' ), array( 'timeStep' => 900 ), array( 'required' => true ) )
					),
					TE::half_row(
						'row_alternative',
						TE::date( 'alternative_date', __( 'Alternative date', 'sw-forms' ), array( 'minDate' => 'today' ) ),
						TE::time(
							'alternative_time',
							__( 'Alternative time', 'sw-forms' ),
							array( 'timeStep' => 900 ),
							array( 'logic' => TE::show( array( TE::rule( 'alternative_date', 'notempty' ) ) ) )
						)
					),
					TE::step( 'step_contact', __( 'Contact details', 'sw-forms' ) ),
					TE::half_row(
						'row_first_name',
						TE::text(
							'first_name',
							__( 'First name', 'sw-forms' ),
							array(
								'maxLength'    => 100,
								'autocomplete' => 'given-name',
							),
							array( 'required' => true )
						),
						TE::text(
							'last_name',
							__( 'Last name', 'sw-forms' ),
							array(
								'maxLength'    => 100,
								'autocomplete' => 'family-name',
							),
							array( 'required' => true )
						)
					),
					TE::half_row(
						'row_email',
						TE::email( 'email', __( 'E-mail', 'sw-forms' ), array( 'autocomplete' => 'email' ), array( 'required' => true ) ),
						TE::telephone( 'phone', __( 'Telephone', 'sw-forms' ), array( 'autocomplete' => 'tel' ) )
					),
					TE::textarea( 'notes', __( 'Anything we should know?', 'sw-forms' ), array( 'maxLength' => 2000 ) ),
					TE::step( 'step_confirm', __( 'Confirm', 'sw-forms' ) ),
					TE::note(
						'confirm_hint',
						__( 'Please check your entries on the previous steps. After submitting you will see a summary of everything you entered.', 'sw-forms' )
					),
					self::privacy_note(),
					TE::note(
						'booking_hint',
						__( 'This is a request. You will receive a written confirmation once the slot has actually been booked.', 'sw-forms' )
					),
				),
				'settings' => TE::step_settings( __( 'Preferred slot', 'sw-forms' ) ),
			),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function privacy_note(): array {
		return TE::note(
			'privacy_note',
			'<strong>' . esc_html__( 'Data protection', 'sw-forms' ) . '</strong> '
			. esc_html__( 'We process your entries to handle your request. Replace this text with your own privacy notice and add a link to it before publishing.', 'sw-forms' )
		);
	}
}
