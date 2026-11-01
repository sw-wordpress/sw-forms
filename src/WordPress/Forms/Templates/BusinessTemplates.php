<?php
/**
 * Starter documents of the "Company" category (docs/schema.md).
 *
 * Uploads always keep the engine's server-side checks (extension allowlist, byte size, count,
 * real HTTP provenance); the settings below only narrow what the visitor may send.
 *
 * @package SWForms
 * @since 0.7.0
 */

declare(strict_types=1);
namespace SWForms\WordPress\Forms\Templates;

use SWForms\WordPress\Forms\TemplateElements as TE;

final class BusinessTemplates {

	/**
	 * @return list<array<string, mixed>>
	 */
	public static function all(): array {
		return array( self::job_application(), self::quote_request(), self::support_ticket(), self::lead() );
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function job_application(): array {
		return array(
			'id'          => 'job-application',
			'version'     => '1.0.0',
			'icon'        => 'r_work_history',
			'title'       => __( 'Job application', 'sw-forms' ),
			'description' => __( 'Contact data, vacancy, CV and cover letter as PDF uploads, plus an application-specific privacy notice.', 'sw-forms' ),
			'tags'        => array( 'application', 'jobs', 'recruiting', 'cv', 'upload', 'multi-step' ),
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
					TE::step( 'step_application', __( 'Application', 'sw-forms' ) ),
					TE::select(
						'position',
						__( 'Vacancy', 'sw-forms' ),
						array(
							TE::choice( 'dev', __( 'Developer', 'sw-forms' ) ),
							TE::choice( 'support', __( 'Support', 'sw-forms' ) ),
							TE::choice( 'admin', __( 'Administration', 'sw-forms' ) ),
							TE::choice( 'initiative', __( 'Speculative application', 'sw-forms' ) ),
						),
						array(),
						array( 'required' => true )
					),
					TE::text(
						'position_focus',
						__( 'Which area do you want to work in?', 'sw-forms' ),
						array( 'maxLength' => 200 ),
						array(
							'required' => true,
							'logic'    => TE::show( array( TE::rule( 'position', 'is', 'initiative' ) ) ),
						)
					),
					TE::half_row(
						'row_experience',
						TE::select(
							'experience',
							__( 'Professional experience', 'sw-forms' ),
							array(
								TE::choice( 'none', __( 'None', 'sw-forms' ) ),
								TE::choice( 'one_to_three', __( '1–3 years', 'sw-forms' ) ),
								TE::choice( 'three_to_five', __( '3–5 years', 'sw-forms' ) ),
								TE::choice( 'over_five', __( 'More than 5 years', 'sw-forms' ) ),
							)
						),
						TE::date( 'entry_date', __( 'Earliest start date', 'sw-forms' ), array( 'minDate' => 'today' ) )
					),
					TE::row(
						'row_salary',
						TE::cell( 6, TE::amount( 'salary', __( 'Salary expectation', 'sw-forms' ), array( 'min' => 0 ), array( 'description' => __( 'Annual gross in the currency of the vacancy.', 'sw-forms' ) ) ) ),
						TE::cell( 6 )
					),
					TE::upload(
						'cv',
						__( 'Curriculum vitae (PDF)', 'sw-forms' ),
						array(
							'extensions' => 'pdf',
							'maxSize'    => 10,
						),
						array(
							'required'    => true,
							'description' => __( 'One PDF, at most 10 MB. Files are stored on the server, so keep personal data in mind.', 'sw-forms' ),
						)
					),
					TE::upload(
						'cover_letter',
						__( 'Cover letter (PDF, optional)', 'sw-forms' ),
						array(
							'extensions' => 'pdf',
							'maxSize'    => 10,
						)
					),
					TE::link( 'website', __( 'Portfolio or website', 'sw-forms' ), array( 'maxLength' => 2000 ) ),
					TE::step( 'step_confirm', __( 'Confirm', 'sw-forms' ) ),
					TE::note( 'confirm_hint', __( 'Use Back to review your entries. The confirmation lists everything you submitted, including the uploaded files.', 'sw-forms' ) ),
					self::application_privacy_note(),
				),
				'settings' => TE::step_settings( __( 'Personal details', 'sw-forms' ) ),
			),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function quote_request(): array {
		return array(
			'id'          => 'quote-request',
			'version'     => '1.0.0',
			'icon'        => 'r_request_quote',
			'title'       => __( 'Request a quote', 'sw-forms' ),
			'description' => __( 'Client type unlocks the company field, services as cards and a project description with a minimum length.', 'sw-forms' ),
			'tags'        => array( 'quote', 'inquiry', 'b2b', 'project', 'sales', 'multi-step' ),
			'document'    => array(
				'fields'   => array(
					TE::radio(
						'client_type',
						__( 'Who is requesting?', 'sw-forms' ),
						array(
							TE::choice( 'private', __( 'Private customer', 'sw-forms' ) ),
							TE::choice( 'company', __( 'Company', 'sw-forms' ) ),
						),
						array( 'presentation' => 'buttons' ),
						array( 'required' => true )
					),
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
					TE::text(
						'company',
						__( 'Company', 'sw-forms' ),
						array(
							'maxLength'    => 200,
							'autocomplete' => 'organization',
						),
						array(
							'required' => true,
							'logic'    => TE::show( array( TE::rule( 'client_type', 'is', 'company' ) ) ),
						)
					),
					TE::half_row(
						'row_email',
						TE::email( 'email', __( 'E-mail', 'sw-forms' ), array( 'autocomplete' => 'email' ), array( 'required' => true ) ),
						TE::telephone( 'phone', __( 'Telephone', 'sw-forms' ), array( 'autocomplete' => 'tel' ) )
					),
					TE::step( 'step_project', __( 'Project', 'sw-forms' ) ),
					TE::checkbox_group(
						'services',
						__( 'What do you need?', 'sw-forms' ),
						array(
							TE::choice( 'consulting', __( 'Consulting', 'sw-forms' ) ),
							TE::choice( 'implementation', __( 'Implementation', 'sw-forms' ) ),
							TE::choice( 'design', __( 'Design', 'sw-forms' ) ),
							TE::choice( 'maintenance', __( 'Maintenance', 'sw-forms' ) ),
							TE::choice( 'training', __( 'Training', 'sw-forms' ) ),
						),
						array( 'presentation' => 'buttons' ),
						array( 'required' => true )
					),
					TE::textarea(
						'project_description',
						__( 'Project description', 'sw-forms' ),
						array(
							'minLength' => 20,
							'maxLength' => 4000,
						),
						array(
							'required'    => true,
							'description' => __( 'At least 20 characters: goal, scope and rough timing.', 'sw-forms' ),
						)
					),
					TE::half_row(
						'row_budget',
						TE::select(
							'budget',
							__( 'Budget range', 'sw-forms' ),
							array(
								TE::choice( 'under_1000', __( 'Up to 1,000', 'sw-forms' ) ),
								TE::choice( '1000_5000', __( '1,000 to 5,000', 'sw-forms' ) ),
								TE::choice( '5000_20000', __( '5,000 to 20,000', 'sw-forms' ) ),
								TE::choice( 'over_20000', __( 'More than 20,000', 'sw-forms' ) ),
								TE::choice( 'open', __( 'Not decided yet', 'sw-forms' ) ),
							)
						),
						TE::date( 'start_date', __( 'Preferred start', 'sw-forms' ), array( 'minDate' => 'today' ) )
					),
					TE::upload(
						'attachments',
						__( 'Attachments (optional)', 'sw-forms' ),
						array(
							'multiple'   => true,
							'extensions' => 'pdf,png,jpg,zip',
							'maxSize'    => 10,
							'maxFiles'   => 5,
						),
						array( 'description' => __( 'Up to five files, each at most 10 MB.', 'sw-forms' ) )
					),
					TE::step( 'step_finish', __( 'Round-up', 'sw-forms' ) ),
					TE::note( 'confirm_hint', __( 'Use Back to review your entries. The confirmation lists everything you submitted.', 'sw-forms' ) ),
					TE::textarea( 'remarks', __( 'Anything else?', 'sw-forms' ), array( 'maxLength' => 2000 ) ),
					self::privacy_note(),
				),
				'settings' => TE::step_settings( __( 'Customer', 'sw-forms' ) ),
			),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function support_ticket(): array {
		return array(
			'id'          => 'support-ticket',
			'version'     => '1.0.0',
			'icon'        => 'r_support_agent',
			'title'       => __( 'Support ticket', 'sw-forms' ),
			'description' => __( 'Area, problem type and priority; error reports ask for reproduction steps.', 'sw-forms' ),
			'tags'        => array( 'support', 'ticket', 'bug', 'help', 'upload' ),
			'document'    => array(
				'fields'   => array(
					TE::half_row(
						'row_name',
						TE::text(
							'name',
							__( 'Name', 'sw-forms' ),
							array(
								'maxLength'    => 150,
								'autocomplete' => 'name',
							)
						),
						TE::email( 'email', __( 'E-mail', 'sw-forms' ), array( 'autocomplete' => 'email' ), array( 'required' => true ) )
					),
					TE::half_row(
						'row_area',
						TE::select(
							'area',
							__( 'Product or area', 'sw-forms' ),
							array(
								TE::choice( 'website', __( 'Website', 'sw-forms' ) ),
								TE::choice( 'shop', __( 'Shop', 'sw-forms' ) ),
								TE::choice( 'account', __( 'Account', 'sw-forms' ) ),
								TE::choice( 'billing', __( 'Billing', 'sw-forms' ) ),
							),
							array( 'searchable' => true ),
							array( 'required' => true )
						),
						TE::select(
							'problem_type',
							__( 'Type of problem', 'sw-forms' ),
							array(
								TE::choice( 'error', __( 'Error', 'sw-forms' ) ),
								TE::choice( 'question', __( 'How-to question', 'sw-forms' ) ),
								TE::choice( 'feature', __( 'Feature request', 'sw-forms' ) ),
								TE::choice( 'other', __( 'Other', 'sw-forms' ) ),
							),
							array(),
							array( 'required' => true )
						)
					),
					TE::radio(
						'priority',
						__( 'Priority', 'sw-forms' ),
						array(
							TE::choice( 'low', __( 'Low', 'sw-forms' ) ),
							TE::choice( 'normal', __( 'Normal', 'sw-forms' ) ),
							TE::choice( 'high', __( 'High', 'sw-forms' ) ),
						),
						array( 'presentation' => 'buttons' ),
						array(
							'required'    => true,
							'description' => __( 'High means something is unusable for several people right now.', 'sw-forms' ),
						)
					),
					TE::row( 'row_version', TE::cell( 6, TE::text( 'version', __( 'Affected version', 'sw-forms' ), array( 'maxLength' => 60 ) ) ), TE::cell( 6 ) ),
					TE::textarea(
						'description',
						__( 'What happened?', 'sw-forms' ),
						array(
							'minLength' => 20,
							'maxLength' => 4000,
						),
						array(
							'required'    => true,
							'description' => __( 'At least 20 characters, please.', 'sw-forms' ),
						)
					),
					TE::textarea(
						'reproduce',
						__( 'Steps to reproduce', 'sw-forms' ),
						array( 'maxLength' => 3000 ),
						array(
							'required' => true,
							'logic'    => TE::show( array( TE::rule( 'problem_type', 'is', 'error' ) ) ),
						)
					),
					TE::textarea(
						'expected',
						__( 'What did you expect instead?', 'sw-forms' ),
						array( 'maxLength' => 2000 ),
						array( 'logic' => TE::show( array( TE::rule( 'problem_type', 'is', 'error' ) ) ) )
					),
					TE::text(
						'problem_other',
						__( 'Which type of problem is it?', 'sw-forms' ),
						array( 'maxLength' => 200 ),
						array(
							'required' => true,
							'logic'    => TE::show( array( TE::rule( 'problem_type', 'is', 'other' ) ) ),
						)
					),
					TE::upload(
						'screenshot',
						__( 'Screenshot or log file (optional)', 'sw-forms' ),
						array(
							'multiple'   => true,
							'extensions' => 'pdf,png,jpg,txt',
							'maxSize'    => 10,
							'maxFiles'   => 3,
						),
						array( 'description' => __( 'PDF, PNG, JPG or TXT, at most 10 MB per file. Export log files as TXT.', 'sw-forms' ) )
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
	private static function lead(): array {
		return array(
			'id'          => 'lead',
			'version'     => '1.0.0',
			'icon'        => 'r_manage_search',
			'title'       => __( 'Lead qualification', 'sw-forms' ),
			'description' => __( 'Contact, needs and a meeting wish: the appointment block only appears when a talk is wanted.', 'sw-forms' ),
			'tags'        => array( 'lead', 'sales', 'crm', 'b2b', 'consulting', 'multi-step' ),
			'document'    => array(
				'fields'   => array(
					TE::text(
						'name',
						__( 'Name', 'sw-forms' ),
						array(
							'maxLength'    => 150,
							'autocomplete' => 'name',
						),
						array( 'required' => true )
					),
					TE::half_row(
						'row_email',
						TE::email( 'email', __( 'Work e-mail', 'sw-forms' ), array( 'autocomplete' => 'email' ), array( 'required' => true ) ),
						TE::text(
							'company',
							__( 'Company', 'sw-forms' ),
							array(
								'maxLength'    => 200,
								'autocomplete' => 'organization',
							),
							array( 'required' => true )
						)
					),
					TE::text(
						'role',
						__( 'Your role', 'sw-forms' ),
						array(
							'maxLength'    => 150,
							'autocomplete' => 'organization-title',
						)
					),
					TE::step( 'step_needs', __( 'Needs', 'sw-forms' ) ),
					TE::checkbox_group(
						'interests',
						__( 'What is this about?', 'sw-forms' ),
						array(
							TE::choice( 'processes', __( 'Processes', 'sw-forms' ) ),
							TE::choice( 'software', __( 'Software', 'sw-forms' ) ),
							TE::choice( 'marketing', __( 'Marketing', 'sw-forms' ) ),
							TE::choice( 'other', __( 'Other', 'sw-forms' ) ),
						),
						array( 'presentation' => 'buttons' ),
						array( 'required' => true )
					),
					TE::text(
						'interest_other',
						__( 'What else?', 'sw-forms' ),
						array( 'maxLength' => 200 ),
						array(
							'required' => true,
							'logic'    => TE::show( array( TE::rule( 'interests', 'contains', 'other' ) ) ),
						)
					),
					TE::half_row(
						'row_size',
						TE::select(
							'company_size',
							__( 'Company size', 'sw-forms' ),
							array(
								TE::choice( 's', __( '1–10 people', 'sw-forms' ) ),
								TE::choice( 'm', __( '11–50 people', 'sw-forms' ) ),
								TE::choice( 'l', __( '51–250 people', 'sw-forms' ) ),
								TE::choice( 'xl', __( 'More than 250 people', 'sw-forms' ) ),
							)
						),
						TE::select(
							'budget',
							__( 'Budget range', 'sw-forms' ),
							array(
								TE::choice( 'under_1000', __( 'Up to 1,000', 'sw-forms' ) ),
								TE::choice( '1000_5000', __( '1,000 to 5,000', 'sw-forms' ) ),
								TE::choice( '5000_20000', __( '5,000 to 20,000', 'sw-forms' ) ),
								TE::choice( 'over_20000', __( 'More than 20,000', 'sw-forms' ) ),
								TE::choice( 'open', __( 'Not decided yet', 'sw-forms' ) ),
							)
						)
					),
					TE::radio(
						'timeline',
						__( 'When should it start?', 'sw-forms' ),
						array(
							TE::choice( 'now', __( 'Right away', 'sw-forms' ) ),
							TE::choice( 'three_months', __( 'Within three months', 'sw-forms' ) ),
							TE::choice( 'six_months', __( 'Within six months', 'sw-forms' ) ),
							TE::choice( 'open', __( 'Still open', 'sw-forms' ) ),
						),
						array( 'presentation' => 'buttons' )
					),
					TE::step( 'step_meeting', __( 'Meeting', 'sw-forms' ) ),
					TE::radio(
						'meeting',
						__( 'Do you want a consultation?', 'sw-forms' ),
						array(
							TE::choice( 'yes', __( 'Yes', 'sw-forms' ) ),
							TE::choice( 'no', __( 'No', 'sw-forms' ) ),
						),
						array( 'presentation' => 'buttons' ),
						array( 'required' => true )
					),
					TE::half_row(
						'row_appointment',
						TE::date(
							'meeting_date',
							__( 'Preferred date', 'sw-forms' ),
							array( 'minDate' => 'today' ),
							array( 'logic' => TE::show( array( TE::rule( 'meeting', 'is', 'yes' ) ) ) )
						),
						TE::time(
							'meeting_time',
							__( 'Preferred time', 'sw-forms' ),
							array( 'timeStep' => 1800 ),
							array( 'logic' => TE::show( array( TE::rule( 'meeting', 'is', 'yes' ) ) ) )
						)
					),
					TE::textarea( 'details', __( 'Anything we should prepare?', 'sw-forms' ), array( 'maxLength' => 2000 ) ),
					self::privacy_note(),
				),
				'settings' => TE::step_settings( __( 'Contact', 'sw-forms' ) ),
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
			. esc_html__( 'We use your entries to answer the request. Replace this text with your own privacy notice and add a link to it before publishing.', 'sw-forms' )
		);
	}

	/**
	 * Applications need their own retention wording (legal hold after rejection).
	 *
	 * @return array<string, mixed>
	 */
	private static function application_privacy_note(): array {
		return TE::note(
			'application_privacy_note',
			'<strong>' . esc_html__( 'Data protection for applications', 'sw-forms' ) . '</strong> '
			. esc_html__( 'We store your application only for this application process and delete it afterwards, unless a longer legal retention period applies. Replace this text with your own notice before publishing.', 'sw-forms' )
		);
	}
}
