<?php
/**
 * Starter documents of the "Community and clubs" category (docs/schema.md).
 *
 * @package SWForms
 * @since 0.7.0
 */

declare(strict_types=1);
namespace SWForms\WordPress\Forms\Templates;

use SWForms\WordPress\Forms\TemplateElements as TE;

final class CommunityTemplates {

	/**
	 * @return list<array<string, mixed>>
	 */
	public static function all(): array {
		return array( self::survey(), self::club_membership(), self::donation() );
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function survey(): array {
		return array(
			'id'          => 'survey',
			'version'     => '2.0.0',
			'icon'        => 'r_poll',
			'title'       => __( 'Survey', 'sw-forms' ),
			'description' => __( 'Two steps: rating and scales first, then the optional wish for a reply which unlocks the e-mail field.', 'sw-forms' ),
			'tags'        => array( 'survey', 'questionnaire', 'rating', 'scale', 'opinions', 'multi-step' ),
			'document'    => array(
				'fields'   => array(
					TE::checkbox_group(
						'topics',
						__( 'Which topics interest you?', 'sw-forms' ),
						array(
							TE::choice( 'training', __( 'Training', 'sw-forms' ) ),
							TE::choice( 'tools', __( 'Tools', 'sw-forms' ) ),
							TE::choice( 'community', __( 'Community', 'sw-forms' ) ),
							TE::choice( 'other', __( 'Other', 'sw-forms' ) ),
						),
						array( 'presentation' => 'buttons' )
					),
					TE::text(
						'topic_other',
						__( 'Which other topic?', 'sw-forms' ),
						array( 'maxLength' => 200 ),
						array( 'logic' => TE::show( array( TE::rule( 'topics', 'contains', 'other' ) ) ) )
					),
					TE::radio(
						'main_interest',
						__( 'What matters most to you?', 'sw-forms' ),
						array(
							TE::choice( 'quality', __( 'Quality', 'sw-forms' ) ),
							TE::choice( 'speed', __( 'Speed', 'sw-forms' ) ),
							TE::choice( 'price', __( 'Price', 'sw-forms' ) ),
							TE::choice( 'support', __( 'Support', 'sw-forms' ) ),
						),
						array( 'presentation' => 'buttons' ),
						array( 'required' => true )
					),
					TE::rating( 'satisfaction', __( 'How satisfied are you?', 'sw-forms' ), array( 'max' => 5 ), array( 'required' => true ) ),
					TE::scale(
						'criteria',
						__( 'Rate one criterion on a scale', 'sw-forms' ),
						array(
							'min' => 1,
							'max' => 10,
						),
						array( 'description' => __( 'One point is the weakest, ten the best result.', 'sw-forms' ) )
					),
					TE::textarea(
						'dissatisfied_reason',
						__( 'What is the main reason for your rating?', 'sw-forms' ),
						array( 'maxLength' => 3000 ),
						// `lt 3` covers the two lowest ratings, because scores are integers.
						array( 'logic' => TE::show( array( TE::rule( 'satisfaction', 'lt', '3' ) ) ) )
					),
					TE::textarea( 'likes', __( 'What do you like most?', 'sw-forms' ), array( 'maxLength' => 3000 ) ),
					TE::textarea( 'improve', __( 'What would you change?', 'sw-forms' ), array( 'maxLength' => 3000 ) ),
					TE::step( 'step_finish', __( 'Finish', 'sw-forms' ) ),
					TE::textarea( 'remarks', __( 'Anything else?', 'sw-forms' ), array( 'maxLength' => 3000 ) ),
					TE::radio(
						'reply_wanted',
						__( 'Would you like a reply?', 'sw-forms' ),
						array(
							TE::choice( 'yes', __( 'Yes', 'sw-forms' ) ),
							TE::choice( 'no', __( 'No', 'sw-forms' ) ),
						),
						array( 'presentation' => 'buttons' )
					),
					TE::email(
						'email',
						__( 'E-mail', 'sw-forms' ),
						array( 'autocomplete' => 'email' ),
						array(
							'required' => true,
							'logic'    => TE::show( array( TE::rule( 'reply_wanted', 'is', 'yes' ) ) ),
						)
					),
					self::privacy_note(),
				),
				'settings' => TE::step_settings( __( 'Your opinion', 'sw-forms' ) ),
			),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function club_membership(): array {
		return array(
			'id'          => 'club-membership',
			'version'     => '1.0.0',
			'icon'        => 'r_groups',
			'title'       => __( 'Club membership', 'sw-forms' ),
			'description' => __( 'Address in two columns, membership cards and a repeatable group for further family members.', 'sw-forms' ),
			'tags'        => array( 'club', 'association', 'membership', 'family', 'volunteering', 'multi-step' ),
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
						TE::birthdate( 'birthdate', __( 'Date of birth', 'sw-forms' ) )
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
					TE::half_row(
						'row_email',
						TE::email( 'email', __( 'E-mail', 'sw-forms' ), array( 'autocomplete' => 'email' ), array( 'required' => true ) ),
						TE::telephone( 'phone', __( 'Telephone', 'sw-forms' ), array( 'autocomplete' => 'tel' ) )
					),
					TE::step( 'step_membership', __( 'Membership', 'sw-forms' ) ),
					TE::radio(
						'membership_type',
						__( 'Type of membership', 'sw-forms' ),
						array(
							TE::choice( 'single', __( 'Individual membership', 'sw-forms' ) ),
							TE::choice( 'family', __( 'Family membership', 'sw-forms' ) ),
							TE::choice( 'supporting', __( 'Supporting membership', 'sw-forms' ) ),
						),
						array( 'presentation' => 'buttons' ),
						array( 'required' => true )
					),
					TE::half_row(
						'row_entry_date',
						TE::date( 'entry_date', __( 'Desired start date', 'sw-forms' ), array( 'minDate' => 'today' ) ),
						TE::select(
							'department',
							__( 'Department or group', 'sw-forms' ),
							array(
								TE::choice( 'gymnastics', __( 'Gymnastics', 'sw-forms' ) ),
								TE::choice( 'football', __( 'Football', 'sw-forms' ) ),
								TE::choice( 'hiking', __( 'Hiking', 'sw-forms' ) ),
							)
						)
					),
					TE::repeater(
						'family_members',
						__( 'Further family members', 'sw-forms' ),
						array(
							TE::text( 'member_given', __( 'First name', 'sw-forms' ), array( 'maxLength' => 100 ), array( 'required' => true ) ),
							TE::text( 'member_family', __( 'Last name', 'sw-forms' ), array( 'maxLength' => 100 ), array( 'required' => true ) ),
							TE::birthdate( 'member_birthdate', __( 'Date of birth', 'sw-forms' ) ),
						),
						array( 'maxRows' => 8 ),
						array(
							'description' => __( 'Add one row per person; the entries are stored together under this group.', 'sw-forms' ),
							'logic'       => TE::show( array( TE::rule( 'membership_type', 'is', 'family' ) ) ),
						)
					),
					TE::step( 'step_confirm', __( 'Confirm', 'sw-forms' ) ),
					TE::note(
						'fee_note',
						'<strong>' . esc_html__( 'Contribution', 'sw-forms' ) . '</strong> '
						. esc_html__( 'Replace this text with the contribution of the selected membership type and the due dates.', 'sw-forms' )
					),
					TE::note(
						'bylaws_note',
						'<strong>' . esc_html__( 'Statute and order', 'sw-forms' ) . '</strong> '
						. sprintf(
							/* translators: %s: placeholder that a club replaces with a real link. */
							esc_html__( 'Read the statute and the access rules. Replace this text with your own wording and add a link: %s', 'sw-forms' ),
							'<a href="https://example.org/statute">' . esc_html__( 'Statute in full text', 'sw-forms' ) . '</a>'
						)
					),
					TE::consent( 'declarations', __( 'Declarations', 'sw-forms' ), __( 'I accept the statute and the contribution order, and I confirm that the data above is correct.', 'sw-forms' ) ),
					TE::consent(
						'sebpa_consent',
						__( 'Direct debit authorisation', 'sw-forms' ),
						__( 'I authorise the club to collect the contribution by direct debit. Only add this field when your accounting really uses SEPA.', 'sw-forms' ),
						array( 'required' => false )
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
	private static function donation(): array {
		return array(
			'id'          => 'donation',
			'version'     => '1.0.0',
			'icon'        => 'r_favorite',
			'title'       => __( 'Donation pledge', 'sw-forms' ),
			'description' => __( 'Amount and rhythm as cards; the address only appears when a donation receipt is wanted.', 'sw-forms' ),
			'tags'        => array( 'donation', 'charity', 'nonprofit', 'money', 'receipt', 'multi-step' ),
			'document'    => array(
				'fields'   => array(
					TE::amount(
						'amount',
						__( 'Amount', 'sw-forms' ) . ' (EUR)',
						array( 'min' => 1 ),
						array(
							'required'    => true,
							'description' => __( 'At least 1.00. Enter the amount as a plain number, for example 25 or 12.50.', 'sw-forms' ),
						)
					),
					TE::radio(
						'rhythm',
						__( 'How often?', 'sw-forms' ),
						array(
							TE::choice( 'once', __( 'One time', 'sw-forms' ) ),
							TE::choice( 'monthly', __( 'Monthly', 'sw-forms' ) ),
							TE::choice( 'yearly', __( 'Yearly', 'sw-forms' ) ),
						),
						array( 'presentation' => 'buttons' ),
						array(
							'required'    => true,
							'description' => __( 'This form records the pledge only. Recurring payments need a separate payment or direct debit integration.', 'sw-forms' ),
						)
					),
					TE::select(
						'purpose',
						__( 'What should the donation support?', 'sw-forms' ),
						array(
							TE::choice( 'general', __( 'Where it is needed most', 'sw-forms' ) ),
							TE::choice( 'projects', __( 'Current projects', 'sw-forms' ) ),
							TE::choice( 'education', __( 'Educational work', 'sw-forms' ) ),
							TE::choice( 'other', __( 'Something else', 'sw-forms' ) ),
						)
					),
					TE::text(
						'purpose_other',
						__( 'Your own purpose', 'sw-forms' ),
						array( 'maxLength' => 200 ),
						array(
							'required' => true,
							'logic'    => TE::show( array( TE::rule( 'purpose', 'is', 'other' ) ) ),
						)
					),
					TE::step( 'step_donor', __( 'About you', 'sw-forms' ) ),
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
					TE::email( 'email', __( 'E-mail', 'sw-forms' ), array( 'autocomplete' => 'email' ), array( 'description' => __( 'We send the confirmation of receipt to this address.', 'sw-forms' ) ) ),
					TE::radio(
						'receipt',
						__( 'Do you need a donation receipt?', 'sw-forms' ),
						array(
							TE::choice( 'yes', __( 'Yes', 'sw-forms' ) ),
							TE::choice( 'no', __( 'No', 'sw-forms' ) ),
						),
						array( 'presentation' => 'buttons' ),
						array( 'required' => true )
					),
					// A receipt needs the donor address, so all three parts are required when visible.
					TE::text(
						'street',
						__( 'Street and house number', 'sw-forms' ),
						array(
							'maxLength'    => 200,
							'autocomplete' => 'street-address',
						),
						array(
							'required' => true,
							'logic'    => TE::show( array( TE::rule( 'receipt', 'is', 'yes' ) ) ),
						)
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
							array(
								'required' => true,
								'logic'    => TE::show( array( TE::rule( 'receipt', 'is', 'yes' ) ) ),
							)
						),
						TE::text(
							'city',
							__( 'City', 'sw-forms' ),
							array(
								'maxLength'    => 120,
								'autocomplete' => 'address-level2',
							),
							array(
								'required' => true,
								'logic'    => TE::show( array( TE::rule( 'receipt', 'is', 'yes' ) ) ),
							)
						)
					),
					TE::step( 'step_confirm', __( 'Confirm', 'sw-forms' ) ),
					TE::note( 'confirm_hint', __( 'Use Back to review your entries. After submitting you will see a summary of everything you entered.', 'sw-forms' ) ),
					TE::note(
						'payment_note',
						'<strong>' . esc_html__( 'How to pay', 'sw-forms' ) . '</strong> '
						. esc_html__( 'This pledge does not transfer money. Replace this text with your bank details or a payment link before publishing.', 'sw-forms' )
					),
					self::privacy_note(),
				),
				'settings' => TE::step_settings( __( 'Donation', 'sw-forms' ) ),
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
			. esc_html__( 'We process your entries to run the club or the donation. Replace this text with your own privacy notice and add a link to it before publishing.', 'sw-forms' )
		);
	}
}
