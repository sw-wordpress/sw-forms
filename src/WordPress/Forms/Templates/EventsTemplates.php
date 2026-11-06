<?php
/**
 * Starter documents of the "Events" category (docs/schema.md).
 *
 * @package SWForms
 * @since 0.7.0
 */

declare(strict_types=1);
namespace SWForms\WordPress\Forms\Templates;

use SWForms\WordPress\Forms\TemplateElements as TE;

final class EventsTemplates {

	/**
	 * @return list<array<string, mixed>>
	 */
	public static function all(): array {
		return array( self::registration(), self::workshop(), self::volunteer(), self::camp() );
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function registration(): array {
		return array(
			'id'          => 'registration',
			'version'     => '2.0.0',
			'icon'        => 'r_event_note',
			'title'       => __( 'Event registration', 'sw-forms' ),
			'description' => __( 'Attendee data, ticket cards with a conditional discount reason, participant count and a confirmation step.', 'sw-forms' ),
			'tags'        => array( 'event', 'registration', 'tickets', 'participants', 'multi-step' ),
			'document'    => array(
				'fields'   => array(
					TE::half_row(
						'row_salutation',
						TE::select( 'salutation', __( 'Salutation', 'sw-forms' ), TE::salutations() ),
						TE::text(
							'first_name',
							__( 'First name', 'sw-forms' ),
							array(
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
								'maxLength'    => 100,
								'autocomplete' => 'family-name',
							),
							array( 'required' => true )
						),
						TE::email( 'email', __( 'E-mail', 'sw-forms' ), array( 'autocomplete' => 'email' ), array( 'required' => true ) )
					),
					TE::row( 'row_phone', TE::cell( 6, TE::telephone( 'phone', __( 'Telephone', 'sw-forms' ), array( 'autocomplete' => 'tel' ) ) ), TE::cell( 6 ) ),
					TE::step( 'step_event', __( 'Event', 'sw-forms' ) ),
					TE::select(
						'event',
						__( 'Event', 'sw-forms' ),
						array(
							TE::choice( 'date_a', __( 'First date', 'sw-forms' ) ),
							TE::choice( 'date_b', __( 'Second date', 'sw-forms' ) ),
							TE::choice( 'online', __( 'Online', 'sw-forms' ) ),
						),
						array(),
						array( 'required' => true )
					),
					TE::radio(
						'ticket',
						__( 'Ticket category', 'sw-forms' ),
						array(
							TE::choice( 'standard', __( 'Standard', 'sw-forms' ) ),
							TE::choice( 'reduced', __( 'Reduced', 'sw-forms' ) ),
							TE::choice( 'free', __( 'Free', 'sw-forms' ) ),
						),
						array( 'presentation' => 'buttons' ),
						array( 'required' => true )
					),
					TE::select(
						'reduction_reason',
						__( 'Reason for the reduced ticket', 'sw-forms' ),
						array(
							TE::choice( 'student', __( 'Student', 'sw-forms' ) ),
							TE::choice( 'pupil', __( 'Pupil', 'sw-forms' ) ),
							TE::choice( 'volunteer_card', __( 'Volunteer card', 'sw-forms' ) ),
							TE::choice( 'other', __( 'Other', 'sw-forms' ) ),
						),
						array(),
						array(
							'required' => true,
							'logic'    => TE::show( array( TE::rule( 'ticket', 'is', 'reduced' ) ) ),
						)
					),
					TE::half_row(
						'row_places',
						TE::number(
							'places',
							__( 'Number of participants', 'sw-forms' ),
							array(
								'min'     => 1,
								'max'     => 20,
								'integer' => true,
							),
							array( 'required' => true )
						),
						TE::select(
							'catering',
							__( 'Catering', 'sw-forms' ),
							array(
								TE::choice( 'none', __( 'Without catering', 'sw-forms' ) ),
								TE::choice( 'standard', __( 'Standard menu', 'sw-forms' ) ),
								TE::choice( 'vegetarian', __( 'Vegetarian', 'sw-forms' ) ),
								TE::choice( 'vegan', __( 'Vegan', 'sw-forms' ) ),
							)
						)
					),
					// A repeater never inherits values: the visitor adds one row per further person.
					TE::repeater(
						'further_attendees',
						__( 'Further participants', 'sw-forms' ),
						array(
							TE::text( 'attendee_name', __( 'Name of the further participant', 'sw-forms' ), array( 'maxLength' => 150 ), array( 'required' => true ) ),
							TE::birthdate( 'attendee_birthdate', __( 'Date of birth', 'sw-forms' ) ),
						),
						array( 'maxRows' => 10 ),
						array(
							'description' => __( 'Add one row for every further person. Their data is stored inside this group.', 'sw-forms' ),
							'logic'       => TE::show( array( TE::rule( 'places', 'gt', '1' ) ) ),
						)
					),
					TE::textarea( 'remarks', __( 'Barriers, allergies, other remarks', 'sw-forms' ), array( 'maxLength' => 2000 ) ),
					TE::step( 'step_confirm', __( 'Confirm', 'sw-forms' ) ),
					TE::note( 'confirm_hint', __( 'Use Back to review your entries. After submitting you will see a summary of everything you entered.', 'sw-forms' ) ),
					// Conditional requirement instead of hiding: the box stays visible, but people
					// with a free ticket do not have to tick it (docs/schema.md, action `require`).
					TE::consent(
						'terms',
						__( 'Terms and conditions', 'sw-forms' ),
						__( 'I accept the terms and conditions of this event.', 'sw-forms' ),
						array(
							'required' => false,
							'logic'    => TE::require_if(
								array(
									TE::rule( 'ticket', 'is', 'standard' ),
									TE::rule( 'ticket', 'is', 'reduced' ),
								),
								'any'
							),
						)
					),
					self::privacy_note(),
				),
				'settings' => TE::step_settings( __( 'Personal details', 'sw-forms' ) ),
			),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function workshop(): array {
		return array(
			'id'          => 'workshop',
			'version'     => '1.0.0',
			'icon'        => 'r_school',
			'title'       => __( 'Workshop booking', 'sw-forms' ),
			'description' => __( 'Workshop and session, participant data, and questions for the instructor.', 'sw-forms' ),
			'tags'        => array( 'workshop', 'course', 'training', 'seminar', 'multi-step' ),
			'document'    => array(
				'fields'   => array(
					TE::select(
						'workshop',
						__( 'Workshop', 'sw-forms' ),
						array(
							TE::choice( 'basics', __( 'Basics', 'sw-forms' ) ),
							TE::choice( 'advanced', __( 'Advanced', 'sw-forms' ) ),
							TE::choice( 'special', __( 'Special session', 'sw-forms' ) ),
						),
						array(),
						array(
							'required'    => true,
							'description' => __( 'Replace the options with the workshops you actually offer; session times are fixed in this starter.', 'sw-forms' ),
						)
					),
					TE::half_row(
						'row_session',
						TE::date( 'session_date', __( 'Date', 'sw-forms' ), array( 'minDate' => 'today' ), array( 'required' => true ) ),
						TE::select(
							'session_time',
							__( 'Start', 'sw-forms' ),
							array(
								TE::choice( '09:00', '09:00' ),
								TE::choice( '13:00', '13:00' ),
								TE::choice( '18:30', '18:30' ),
							),
							array(),
							array( 'required' => true )
						)
					),
					TE::radio(
						'level',
						__( 'Your experience', 'sw-forms' ),
						array(
							TE::choice( 'beginner', __( 'Beginner', 'sw-forms' ) ),
							TE::choice( 'intermediate', __( 'Intermediate', 'sw-forms' ) ),
							TE::choice( 'expert', __( 'Expert', 'sw-forms' ) ),
						),
						array( 'presentation' => 'buttons' )
					),
					TE::text(
						'expert_topic',
						__( 'Topic you want to go deeper into', 'sw-forms' ),
						array( 'maxLength' => 200 ),
						array( 'logic' => TE::show( array( TE::rule( 'level', 'is', 'expert' ) ) ) )
					),
					TE::step( 'step_participant', __( 'Participant', 'sw-forms' ) ),
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
					TE::text(
						'company',
						__( 'Company or organisation', 'sw-forms' ),
						array(
							'maxLength'    => 200,
							'autocomplete' => 'organization',
						)
					),
					TE::step( 'step_finish', __( 'Questions', 'sw-forms' ) ),
					TE::textarea( 'questions', __( 'Questions for the instructor', 'sw-forms' ), array( 'maxLength' => 2000 ) ),
					TE::note( 'confirm_hint', __( 'Use Back to review your entries. The confirmation lists everything you submitted.', 'sw-forms' ) ),
					self::privacy_note(),
				),
				'settings' => TE::step_settings( __( 'Workshop and session', 'sw-forms' ) ),
			),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function volunteer(): array {
		return array(
			'id'          => 'volunteer',
			'version'     => '1.0.0',
			'icon'        => 'r_volunteer_activism',
			'title'       => __( 'Volunteer sign-up', 'sw-forms' ),
			'description' => __( 'Tasks and availability as cards, with a free-text field for the “other” task.', 'sw-forms' ),
			'tags'        => array( 'volunteer', 'help', 'team', 'charity', 'multi-step' ),
			'document'    => array(
				'fields'   => array(
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
					TE::step( 'step_deployment', __( 'Deployment', 'sw-forms' ) ),
					TE::checkbox_group(
						'tasks',
						__( 'Tasks you can help with', 'sw-forms' ),
						array(
							TE::choice( 'build_up', __( 'Set-up', 'sw-forms' ) ),
							TE::choice( 'tear_down', __( 'Take-down', 'sw-forms' ) ),
							TE::choice( 'kitchen', __( 'Kitchen', 'sw-forms' ) ),
							TE::choice( 'technology', __( 'Technology', 'sw-forms' ) ),
							TE::choice( 'care', __( 'Supervision', 'sw-forms' ) ),
							TE::choice( 'other', __( 'Other', 'sw-forms' ) ),
						),
						array( 'presentation' => 'buttons' ),
						array( 'required' => true )
					),
					TE::text(
						'task_other',
						__( 'Which other task?', 'sw-forms' ),
						array( 'maxLength' => 200 ),
						array(
							'required' => true,
							// `contains` matches option values of a checkbox list.
							'logic'    => TE::show( array( TE::rule( 'tasks', 'contains', 'other' ) ) ),
						)
					),
					TE::checkbox_group(
						'available_days',
						__( 'Days you are available', 'sw-forms' ),
						TE::days(),
						array( 'presentation' => 'buttons' ),
						array( 'required' => true )
					),
					TE::checkbox_group(
						'available_windows',
						__( 'Preferred time of day', 'sw-forms' ),
						TE::day_parts(),
						array( 'presentation' => 'buttons' )
					),
					TE::textarea( 'experience', __( 'Relevant experience', 'sw-forms' ), array( 'maxLength' => 2000 ) ),
					TE::step( 'step_finish', __( 'Last things', 'sw-forms' ) ),
					TE::textarea( 'remarks', __( 'Anything else we should know?', 'sw-forms' ), array( 'maxLength' => 1000 ) ),
					TE::note( 'confirm_hint', __( 'Use Back to review your entries. The confirmation lists everything you submitted.', 'sw-forms' ) ),
					self::privacy_note(),
				),
				'settings' => TE::step_settings( __( 'Personal details', 'sw-forms' ) ),
			),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function camp(): array {
		return array(
			'id'          => 'camp',
			'version'     => '1.0.0',
			'icon'        => 'r_beach_access',
			'title'       => __( 'Camp sign-up (youth)', 'sw-forms' ),
			'description' => __( 'Four steps: participant, contact, stay and confirmation. Minor participants unlock a contact person group.', 'sw-forms' ),
			'tags'        => array( 'camp', 'youth', 'retreat', 'children', 'multi-step' ),
			'document'    => array(
				'fields'   => array(
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
						'row_birthdate',
						// Birthdates are always checked against the site calendar; the age check for the
						// event start is asked explicitly below, because a condition cannot compute ages.
						TE::birthdate( 'birthdate', __( 'Date of birth', 'sw-forms' ), array(), array( 'required' => true ) ),
						TE::select(
							'gender',
							__( 'Gender', 'sw-forms' ),
							array(
								TE::choice( 'female', __( 'Female', 'sw-forms' ) ),
								TE::choice( 'male', __( 'Male', 'sw-forms' ) ),
								TE::choice( 'diverse', __( 'Diverse', 'sw-forms' ) ),
								TE::choice( 'none', __( 'Prefer not to say', 'sw-forms' ) ),
							)
						)
					),
					TE::text(
						'street',
						__( 'Street and house number', 'sw-forms' ),
						array(
							'maxLength'    => 200,
							'autocomplete' => 'street-address',
						),
						array( 'required' => true )
					),
					TE::half_row(
						'row_postal_code',
						TE::text(
							'postal_code',
							__( 'Postal code', 'sw-forms' ),
							array(
								'maxLength'    => 12,
								'autocomplete' => 'postal-code',
							),
							array( 'required' => true )
						),
						TE::text(
							'city',
							__( 'City', 'sw-forms' ),
							array(
								'maxLength'    => 120,
								'autocomplete' => 'address-level2',
							),
							array( 'required' => true )
						)
					),
					TE::step( 'step_contact', __( 'Contact', 'sw-forms' ) ),
					TE::half_row(
						'row_email',
						TE::email( 'email', __( 'E-mail', 'sw-forms' ), array( 'autocomplete' => 'email' ), array( 'required' => true ) ),
						TE::telephone( 'phone', __( 'Telephone', 'sw-forms' ), array( 'autocomplete' => 'tel' ), array( 'required' => true ) )
					),
					TE::radio(
						'is_minor',
						__( 'Will the participant still be under 18 at the start of the camp?', 'sw-forms' ),
						array(
							TE::choice( 'yes', __( 'Yes', 'sw-forms' ) ),
							TE::choice( 'no', __( 'No', 'sw-forms' ) ),
						),
						array( 'presentation' => 'buttons' ),
						array(
							'required'    => true,
							'description' => __( 'Compare the date of birth with the first day of the camp, not with today.', 'sw-forms' ),
						)
					),
					TE::group(
						'emergency_contact',
						__( 'Contact person for minors', 'sw-forms' ),
						array(
							TE::text( 'contact_name', __( 'Name of the contact person', 'sw-forms' ), array( 'maxLength' => 150 ), array( 'required' => true ) ),
							TE::telephone( 'contact_phone', __( 'Telephone of the contact person', 'sw-forms' ), array( 'autocomplete' => 'tel' ), array( 'required' => true ) ),
							TE::email( 'contact_email', __( 'E-mail of the contact person', 'sw-forms' ) ),
						),
						array(),
						array(
							'description' => __( 'These fields are grouped together and stored under their own key.', 'sw-forms' ),
							'logic'       => TE::show( array( TE::rule( 'is_minor', 'is', 'yes' ) ) ),
						)
					),
					TE::step( 'step_stay', __( 'About the stay', 'sw-forms' ) ),
					TE::radio(
						'travel',
						__( 'How do you want to travel?', 'sw-forms' ),
						array(
							TE::choice( 'own', __( 'On my own', 'sw-forms' ) ),
							TE::choice( 'carpool', __( 'I need a lift', 'sw-forms' ) ),
							TE::choice( 'group', __( 'Together as a group', 'sw-forms' ) ),
						),
						array( 'presentation' => 'buttons' ),
						array( 'required' => true )
					),
					TE::text(
						'carpool_from',
						__( 'Where should the lift start?', 'sw-forms' ),
						array( 'maxLength' => 200 ),
						array(
							'required' => true,
							'logic'    => TE::show( array( TE::rule( 'travel', 'is', 'carpool' ) ) ),
						)
					),
					TE::row( 'row_room', TE::cell( 6, self::room_choice() ), TE::cell( 6 ) ),
					TE::checkbox_group(
						'diets',
						__( 'Food preferences', 'sw-forms' ),
						array(
							TE::choice( 'vegetarian', __( 'Vegetarian', 'sw-forms' ) ),
							TE::choice( 'vegan', __( 'Vegan', 'sw-forms' ) ),
							TE::choice( 'other', __( 'Other', 'sw-forms' ) ),
						),
						array( 'presentation' => 'buttons' )
					),
					TE::textarea(
						'diet_other',
						__( 'Please describe your dietary needs', 'sw-forms' ),
						array( 'maxLength' => 1000 ),
						array( 'logic' => TE::show( array( TE::rule( 'diets', 'contains', 'other' ) ) ) )
					),
					TE::textarea( 'hints', __( 'Anything else we should know about you?', 'sw-forms' ), array( 'maxLength' => 2000 ) ),
					TE::step( 'step_confirm', __( 'Confirm', 'sw-forms' ) ),
					TE::note( 'confirm_hint', __( 'Use Back to review your entries. After submitting you will see a summary of everything you entered.', 'sw-forms' ) ),
					TE::consent( 'terms', __( 'Terms of participation', 'sw-forms' ), __( 'I accept the terms of participation and the cancellation policy.', 'sw-forms' ) ),
					// An explicitly voluntary consent: it is not required, so the form stays usable without it.
					TE::consent(
						'photo_consent',
						__( 'Photos', 'sw-forms' ),
						__( 'I agree that photos of me may be published. This is voluntary and you can withdraw it at any time.', 'sw-forms' ),
						array( 'required' => false )
					),
					self::privacy_note(),
				),
				'settings' => TE::step_settings( __( 'Participant', 'sw-forms' ) ),
			),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function room_choice(): array {
		return TE::select(
			'room',
			__( 'Room preference', 'sw-forms' ),
			array(
				TE::choice( 'single', __( 'Single room', 'sw-forms' ) ),
				TE::choice( 'double', __( 'Two-bed room', 'sw-forms' ) ),
				TE::choice( 'shared', __( 'Shared room', 'sw-forms' ) ),
			)
		);
	}
	/**
	 * Shared privacy notice for the event starters; the legal wording belongs to the site operator.
	 *
	 * @return array<string, mixed>
	 */
	private static function privacy_note(): array {
		return TE::note(
			'privacy_note',
			'<strong>' . esc_html__( 'Data protection', 'sw-forms' ) . '</strong> '
			. esc_html__( 'We process your entries to organise the event and may pass them to the people who need them (catering, transport, group leaders). Replace this text with your own privacy notice before publishing.', 'sw-forms' )
		);
	}
}
