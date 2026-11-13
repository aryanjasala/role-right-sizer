<?php
/**
 * Abilities: recommendations and changes for AI agents and other Abilities API clients.
 *
 * @package AryanX\Axle\RoleRightSizer
 */

namespace AryanX\Axle\RoleRightSizer;

use AryanX\Axle\RoleRightSizer\Admin\Screen;
use AryanX\Axle\RoleRightSizer\Usage\Store;

defined( 'ABSPATH' ) || exit;

/**
 * Registers Role Right-Sizer's abilities on WordPress 6.9 and later.
 *
 * An agent can read the recommendations and one user's usage, apply a
 * recommendation and undo a change. They need the same capability as the
 * screen, and the same protections apply: no changing your own role, the
 * last administrator stays, excluded users and roles are left alone.
 */
final class Abilities implements Service {

	public const CATEGORY = 'role-right-sizer';

	/**
	 * Settings and limits.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Recorded usage.
	 *
	 * @var Store
	 */
	private Store $store;

	/**
	 * Works out recommendations.
	 *
	 * @var Recommender
	 */
	private Recommender $recommender;

	/**
	 * Saved recommendations.
	 *
	 * @var Report
	 */
	private Report $report;

	/**
	 * Change log.
	 *
	 * @var Changes
	 */
	private Changes $changes;

	/**
	 * Applies and undoes changes.
	 *
	 * @var Applier
	 */
	private Applier $applier;

	/**
	 * Trials of a smaller role.
	 *
	 * @var Trials
	 */
	private Trials $trials;

	/**
	 * Access reviews.
	 *
	 * @var Reviews
	 */
	private Reviews $reviews;

	/**
	 * Sets up the abilities.
	 *
	 * @param Settings    $settings    Settings and limits.
	 * @param Store       $store       Recorded usage.
	 * @param Recommender $recommender Works out recommendations.
	 * @param Report      $report      Saved recommendations.
	 * @param Changes     $changes     Change log.
	 * @param Applier     $applier     Applies and undoes changes.
	 * @param Trials      $trials      Trials of a smaller role.
	 * @param Reviews     $reviews     Access reviews.
	 */
	public function __construct( Settings $settings, Store $store, Recommender $recommender, Report $report, Changes $changes, Applier $applier, Trials $trials, Reviews $reviews ) {
		$this->trials      = $trials;
		$this->reviews     = $reviews;
		$this->settings    = $settings;
		$this->store       = $store;
		$this->recommender = $recommender;
		$this->report      = $report;
		$this->changes     = $changes;
		$this->applier     = $applier;
	}

	/**
	 * Hooks into the Abilities API when WordPress has it.
	 */
	public function register(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		add_action( 'wp_abilities_api_categories_init', array( $this, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
		// What an approver sees when Agent Governance holds one of these calls.
		add_filter( 'agent_governance_dry_run', array( $this, 'dry_run' ), 10, 2 );
	}

	/**
	 * Registers the category.
	 */
	public function register_category(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'Role Right-Sizer', 'role-right-sizer' ),
				'description' => __( 'See which accounts have more access than they use, give them a smaller role, and undo it.', 'role-right-sizer' ),
			)
		);
	}

	/**
	 * Registers the abilities.
	 */
	public function register_abilities(): void {
		$can_manage = static function (): bool {
			return current_user_can( Screen::capability() );
		};
		$caps       = array(
			'type'  => 'array',
			'items' => array( 'type' => 'string' ),
		);
		$change     = array(
			'type'       => 'object',
			'properties' => array(
				'id'      => array( 'type' => 'integer' ),
				'batch'   => array( 'type' => 'integer' ),
				'kind'    => array( 'type' => 'string' ),
				'user_id' => array( 'type' => 'integer' ),
				'before'  => $caps,
				'after'   => $caps,
				'role'    => array( 'type' => 'string' ),
				'cap'     => array( 'type' => 'string' ),
				'lost'    => $caps,
			),
		);

		$this->ability(
			'get-recommendations',
			__( 'Get role recommendations', 'role-right-sizer' ),
			__( 'Accounts that could have a smaller role, based on what they used, dormant accounts, accounts that never logged in since tracking began, and capabilities on roles nobody used. Empty while the plugin is still learning.', 'role-right-sizer' ),
			array(),
			array(
				'type'       => 'object',
				'properties' => array(
					'learning'      => array( 'type' => 'boolean' ),
					'learning_ends' => array(
						'type'        => 'string',
						'description' => 'ISO 8601, UTC.',
					),
					'built'         => array( 'type' => 'string' ),
					'users'         => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'user_id'     => array( 'type' => 'integer' ),
								'login'       => array( 'type' => 'string' ),
								'type'        => array(
									'type' => 'string',
									'enum' => array( 'downgrade', 'dormant', 'never' ),
								),
								'current'     => $caps,
								'proposed'    => array(
									'type'        => 'string',
									'description' => 'Role name. Empty means no role on this site.',
								),
								'lost'        => $caps,
								'last_active' => array( 'type' => 'string' ),
							),
						),
					),
					'roles'         => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'role'         => array( 'type' => 'string' ),
								'cap'          => array( 'type' => 'string' ),
								'users'        => array( 'type' => 'integer' ),
								'owner'        => array(
									'type'        => 'string',
									'description' => 'The plugin, post type or taxonomy the capability belongs to, when it can be told.',
								),
								'owner_active' => array(
									'type'        => 'boolean',
									'description' => 'Whether that owner is active. Removing its capabilities can break it.',
								),
							),
						),
					),
				),
			),
			array( $this, 'get_recommendations' ),
			$can_manage,
			array(
				'readonly'   => true,
				'idempotent' => true,
			)
		);

		$this->ability(
			'get-user-usage',
			__( 'Get what a user used', 'role-right-sizer' ),
			__( 'The capabilities a user used with first and last use, the abilities they ran, their last login and activity, and the recommendation for them.', 'role-right-sizer' ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'user' => array(
						'type'        => array( 'integer', 'string' ),
						'description' => __( 'User ID, username or email address.', 'role-right-sizer' ),
					),
				),
				'required'             => array( 'user' ),
				'additionalProperties' => false,
			),
			array(
				'type'       => 'object',
				'properties' => array(
					'user_id'        => array( 'type' => 'integer' ),
					'login'          => array( 'type' => 'string' ),
					'roles'          => $caps,
					'last_login'     => array( 'type' => 'string' ),
					'last_active'    => array( 'type' => 'string' ),
					'capabilities'   => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'name'       => array( 'type' => 'string' ),
								'first_used' => array( 'type' => 'string' ),
								'last_used'  => array( 'type' => 'string' ),
								'count'      => array( 'type' => 'integer' ),
								'in_window'  => array( 'type' => 'boolean' ),
							),
						),
					),
					'abilities'      => array( 'type' => 'array' ),
					'recommendation' => array( 'type' => array( 'object', 'null' ) ),
				),
			),
			array( $this, 'get_user_usage' ),
			function ( $input = null ) use ( $can_manage ): bool {
				$user = self::find_user( ( (array) $input )['user'] ?? '' );

				return $can_manage() && ( ! $user || Access::can_view_user( $user->ID ) );
			},
			array(
				'readonly'   => true,
				'idempotent' => true,
			)
		);

		$this->ability(
			'apply-recommendation',
			__( 'Apply a role recommendation', 'role-right-sizer' ),
			__( 'Gives a user the recommended role, or removes an unused capability from a role. Pass user_id, or role and capability. The change is logged and can be undone with undo-change. Refused for your own account, the last administrator and anything on the exclude list.', 'role-right-sizer' ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'user_id'    => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
					'role'       => array( 'type' => 'string' ),
					'capability' => array( 'type' => 'string' ),
				),
				'additionalProperties' => false,
			),
			array(
				'type'       => 'object',
				'properties' => array(
					'batch'   => array( 'type' => 'integer' ),
					'changes' => array(
						'type'  => 'array',
						'items' => $change,
					),
				),
			),
			array( $this, 'apply_recommendation' ),
			function ( $input = null ) use ( $can_manage ): bool {
				$user_id = (int) ( ( (array) $input )['user_id'] ?? 0 );

				return $can_manage() && ( ! $user_id || Access::can_manage_user( $user_id ) );
			},
			array(
				'readonly'    => false,
				'destructive' => true,
				'idempotent'  => false,
			)
		);

		$this->ability(
			'undo-change',
			__( 'Undo a role change', 'role-right-sizer' ),
			__( 'Puts back the roles or the capability a change took away. With batch true, undoes every change applied together with it.', 'role-right-sizer' ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'change_id' => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
					'batch'     => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
				'required'             => array( 'change_id' ),
				'additionalProperties' => false,
			),
			array(
				'type'       => 'object',
				'properties' => array(
					'undone' => array(
						'type'  => 'array',
						'items' => $change,
					),
				),
			),
			array( $this, 'undo_change' ),
			function ( $input = null ) use ( $can_manage ): bool {
				$change = $this->changes->get( (int) ( ( (array) $input )['change_id'] ?? 0 ) );

				return $can_manage() && ( ! $change || ! isset( $change['user_id'] ) || Access::can_manage_user( (int) $change['user_id'] ) );
			},
			array(
				'readonly'    => false,
				'destructive' => true,
				'idempotent'  => false,
			)
		);

		$trial_schema     = array(
			'type'       => 'object',
			'properties' => array(
				'id'           => array( 'type' => 'integer' ),
				'user_id'      => array( 'type' => 'integer' ),
				'login'        => array( 'type' => 'string' ),
				'status'       => array(
					'type' => 'string',
					'enum' => array( 'active', 'ended', 'kept', 'restored' ),
				),
				'started'      => array( 'type' => 'string' ),
				'ends'         => array( 'type' => 'string' ),
				'days_left'    => array( 'type' => 'integer' ),
				'before'       => $caps,
				'during'       => $caps,
				'change_id'    => array( 'type' => 'integer' ),
				'requested'    => array(
					'type'        => 'string',
					'description' => 'When the person last asked for their access back. Empty if they never did.',
				),
				'denial_count' => array( 'type' => 'integer' ),
				'denials'      => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'label' => array( 'type' => 'string' ),
							'kind'  => array( 'type' => 'string' ),
							'cap'   => array( 'type' => 'string' ),
							'count' => array( 'type' => 'integer' ),
							'last'  => array( 'type' => 'string' ),
						),
					),
				),
			),
		);
		$trial_id         = array(
			'type'                 => 'object',
			'properties'           => array(
				'trial_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			),
			'required'             => array( 'trial_id' ),
			'additionalProperties' => false,
		);
		$trial_out        = array(
			'type'       => 'object',
			'properties' => array( 'trial' => $trial_schema ),
		);
		$can_manage_trial = function ( $input = null ) use ( $can_manage ): bool {
			$trial = $this->trials->get( (int) ( ( (array) $input )['trial_id'] ?? 0 ) );

			return $can_manage() && ( ! $trial || Access::can_manage_user( (int) $trial['user_id'] ) );
		};

		$this->ability(
			'get-changes',
			__( 'Get the change log', 'role-right-sizer' ),
			__( 'The role changes Role Right-Sizer made, newest first, with what each one replaced and whether it was undone. Changes to accounts you may not edit are left out.', 'role-right-sizer' ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'limit' => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 200,
						'default' => 50,
					),
				),
				'additionalProperties' => false,
				'default'              => array(),
			),
			array(
				'type'       => 'object',
				'properties' => array(
					'changes' => array(
						'type'  => 'array',
						'items' => array_merge(
							$change,
							array(
								'properties' => array_merge(
									$change['properties'],
									array(
										'time'   => array( 'type' => 'string' ),
										'undone' => array(
											'type'        => 'string',
											'description' => 'When it was undone. Empty if it was not.',
										),
									)
								),
							)
						),
					),
				),
			),
			array( $this, 'get_changes' ),
			$can_manage,
			array(
				'readonly'   => true,
				'idempotent' => true,
			)
		);

		$this->ability(
			'list-trials',
			__( 'List role trials', 'role-right-sizer' ),
			__( 'Trials of a smaller role: who is on one, how many days are left and what the person could not do. Trials of accounts you may not edit are left out.', 'role-right-sizer' ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'status' => array(
						'type'        => 'string',
						'enum'        => array( 'open', 'active', 'ended', 'kept', 'restored' ),
						'description' => 'open means active or ended. Leave out for every trial.',
					),
				),
				'additionalProperties' => false,
				'default'              => array(),
			),
			array(
				'type'       => 'object',
				'properties' => array(
					'trials' => array(
						'type'  => 'array',
						'items' => $trial_schema,
					),
				),
			),
			array( $this, 'list_trials' ),
			$can_manage,
			array(
				'readonly'   => true,
				'idempotent' => true,
			)
		);

		$this->ability(
			'list-reviews',
			__( 'List access reviews', 'role-right-sizer' ),
			__( 'Access reviews with their due date and how many accounts have a Keep, Reduce or Remove decision. Read only: agents cannot start, decide or finish a review.', 'role-right-sizer' ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'status' => array(
						'type' => 'string',
						'enum' => array( 'open', 'finished' ),
					),
				),
				'additionalProperties' => false,
				'default'              => array(),
			),
			array(
				'type'       => 'object',
				'properties' => array(
					'reviews' => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'id'       => array( 'type' => 'integer' ),
								'name'     => array( 'type' => 'string' ),
								'status'   => array( 'type' => 'string' ),
								'started'  => array( 'type' => 'string' ),
								'due'      => array( 'type' => 'string' ),
								'finished' => array( 'type' => 'string' ),
								'accounts' => array( 'type' => 'integer' ),
								'decided'  => array( 'type' => 'integer' ),
								'keep'     => array( 'type' => 'integer' ),
								'reduce'   => array( 'type' => 'integer' ),
								'remove'   => array( 'type' => 'integer' ),
								'batch'    => array( 'type' => 'integer' ),
							),
						),
					),
				),
			),
			array( $this, 'list_reviews' ),
			$can_manage,
			array(
				'readonly'   => true,
				'idempotent' => true,
			)
		);

		$this->ability(
			'export-review',
			__( 'Export an access review', 'role-right-sizer' ),
			__( 'The evidence for one review as CSV or a printable HTML report: who reviewed, when, each decision and note, and what changed. Accounts you may not edit are left out.', 'role-right-sizer' ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'review_id' => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
					'format'    => array(
						'type'    => 'string',
						'enum'    => array( 'csv', 'html' ),
						'default' => 'csv',
					),
				),
				'required'             => array( 'review_id' ),
				'additionalProperties' => false,
			),
			array(
				'type'       => 'object',
				'properties' => array(
					'format'  => array( 'type' => 'string' ),
					'content' => array( 'type' => 'string' ),
				),
			),
			array( $this, 'export_review' ),
			$can_manage,
			array(
				'readonly'   => true,
				'idempotent' => true,
			)
		);

		$this->ability(
			'start-trial',
			__( 'Start a role trial', 'role-right-sizer' ),
			__( 'Gives a user their recommended smaller role for a number of days and records what they could not do. The change is logged, and restore-trial gives the old role back. Refused for your own account, the last administrator, anything on the exclude list and accounts that are not active.', 'role-right-sizer' ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'user_id' => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
					'days'    => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'maximum'     => 365,
						'description' => 'Defaults to 14.',
					),
				),
				'required'             => array( 'user_id' ),
				'additionalProperties' => false,
			),
			$trial_out,
			array( $this, 'start_trial' ),
			function ( $input = null ) use ( $can_manage ): bool {
				return $can_manage() && Access::can_manage_user( (int) ( ( (array) $input )['user_id'] ?? 0 ) );
			},
			array(
				'readonly'    => false,
				'destructive' => true,
				'idempotent'  => false,
			)
		);

		$this->ability(
			'restore-trial',
			__( 'Restore the role after a trial', 'role-right-sizer' ),
			__( 'Gives the person their old role back and ends the trial.', 'role-right-sizer' ),
			$trial_id,
			$trial_out,
			array( $this, 'restore_trial' ),
			$can_manage_trial,
			array(
				'readonly'    => false,
				'destructive' => true,
				'idempotent'  => false,
			)
		);

		$this->ability(
			'extend-trial',
			__( 'Extend a role trial', 'role-right-sizer' ),
			__( 'Gives a trial more days, counted from now when its time is up.', 'role-right-sizer' ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'trial_id' => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
					'days'     => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'maximum'     => 365,
						'description' => 'Defaults to 14.',
					),
				),
				'required'             => array( 'trial_id' ),
				'additionalProperties' => false,
			),
			$trial_out,
			array( $this, 'extend_trial' ),
			$can_manage_trial,
			array(
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => false,
			)
		);

		$this->ability(
			'keep-trial',
			__( 'Make a role trial permanent', 'role-right-sizer' ),
			__( 'Ends the trial and keeps the smaller role. The change stays in the log, where undo-change can still put the old role back.', 'role-right-sizer' ),
			$trial_id,
			$trial_out,
			array( $this, 'keep_trial' ),
			$can_manage_trial,
			array(
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => false,
			)
		);
	}

	/**
	 * Ability: the access reviews.
	 *
	 * Agents can read reviews and export the evidence. Deciding, finishing and
	 * discarding are for people, so there is no ability for them.
	 *
	 * @param mixed $input Ability input.
	 * @return array
	 */
	public function list_reviews( $input = null ): array {
		$status  = (string) ( ( (array) $input )['status'] ?? '' );
		$reviews = array();
		foreach ( $this->reviews->all() as $review ) {
			if ( '' !== $status && $review['status'] !== $status ) {
				continue;
			}
			$progress  = Reviews::progress( $review );
			$reviews[] = array(
				'id'       => (int) $review['id'],
				'name'     => (string) $review['name'],
				'status'   => (string) $review['status'],
				'started'  => self::iso( (int) $review['started'] ),
				'due'      => self::iso( (int) $review['due'] ),
				'finished' => self::iso( (int) $review['finished'] ),
				'accounts' => $progress['total'],
				'decided'  => $progress['decided'],
				'keep'     => $progress['keep'],
				'reduce'   => $progress['reduce'],
				'remove'   => $progress['remove'],
				'batch'    => (int) $review['batch'],
			);
		}

		return array( 'reviews' => $reviews );
	}

	/**
	 * Ability: the evidence of one review.
	 *
	 * @param mixed $input Ability input.
	 * @return array|\WP_Error
	 */
	public function export_review( $input = null ) {
		$input  = (array) $input;
		$review = $this->reviews->get( (int) ( $input['review_id'] ?? 0 ) );
		if ( ! $review ) {
			return new \WP_Error( 'role_right_sizer_review_not_found', __( 'That review does not exist.', 'role-right-sizer' ), array( 'status' => 404 ) );
		}

		$html = 'html' === ( $input['format'] ?? 'csv' );

		return array(
			'format'  => $html ? 'html' : 'csv',
			'content' => $html ? $this->reviews->html( $review ) : $this->reviews->csv( $review ),
		);
	}

	/**
	 * Ability: the recommendations.
	 *
	 * @return array
	 */
	public function get_recommendations(): array {
		$learning = $this->settings->is_learning();
		if ( ! $learning && $this->report->is_stale() ) {
			$this->report->rebuild();
		}
		$report = $this->report->get();

		$users = array();
		foreach ( $learning ? array() : Access::visible_recommendations( $report['users'] ) as $recommendation ) {
			$user    = get_userdata( (int) $recommendation['user_id'] );
			$users[] = array(
				'user_id'     => (int) $recommendation['user_id'],
				'login'       => $user ? $user->user_login : '',
				'type'        => (string) $recommendation['type'],
				'current'     => array_values( (array) $recommendation['current'] ),
				'proposed'    => (string) $recommendation['proposed'],
				'lost'        => array_values( (array) $recommendation['lost'] ),
				'last_active' => self::iso( max( (int) $recommendation['last_active'], (int) $recommendation['last_login'] ) ),
			);
		}

		$roles = array();
		foreach ( $learning ? array() : $report['roles'] as $recommendation ) {
			$owner   = is_array( $recommendation['owner'] ?? null ) ? $recommendation['owner'] : null;
			$roles[] = array(
				'role'         => (string) $recommendation['role'],
				'cap'          => (string) $recommendation['cap'],
				'users'        => (int) $recommendation['users'],
				'owner'        => $owner ? (string) $owner['name'] : '',
				'owner_active' => Report::has_active_owner( $recommendation ),
			);
		}

		return array(
			'learning'      => $learning,
			'learning_ends' => self::iso( $this->settings->learning_ends() ),
			'built'         => self::iso( $report['built'] ),
			'users'         => $users,
			'roles'         => $roles,
		);
	}

	/**
	 * Ability: one user's usage.
	 *
	 * @param mixed $input Ability input.
	 * @return array|\WP_Error
	 */
	public function get_user_usage( $input = null ) {
		$user = self::find_user( ( (array) $input )['user'] ?? '' );
		if ( ! $user ) {
			return new \WP_Error( 'role_right_sizer_not_found', __( 'That user does not exist.', 'role-right-sizer' ), array( 'status' => 404 ) );
		}
		if ( ! Access::can_view_user( $user->ID ) ) {
			return new \WP_Error( 'role_right_sizer_forbidden', __( 'Sorry, you are not allowed to see this user.', 'role-right-sizer' ), array( 'status' => 403 ) );
		}

		$since = time() - $this->settings->window_days() * DAY_IN_SECONDS;
		$list  = static function ( array $record ) use ( $since ): array {
			$items = array();
			foreach ( $record as $name => $times ) {
				$items[] = array(
					'name'       => (string) $name,
					'first_used' => self::iso( $times[0] ),
					'last_used'  => self::iso( $times[1] ),
					'count'      => $times[2],
					'in_window'  => $times[1] >= $since,
				);
			}

			return $items;
		};

		return array(
			'user_id'        => $user->ID,
			'login'          => $user->user_login,
			'roles'          => array_values( (array) $user->roles ),
			'last_login'     => self::iso( $this->store->last_login( $user->ID ) ),
			'last_active'    => self::iso( $this->store->last_active( $user->ID ) ),
			'capabilities'   => $list( $this->store->usage( $user->ID ) ),
			'abilities'      => $list( $this->store->abilities( $user->ID ) ),
			'recommendation' => $this->recommender->for_user( $user, time(), Recommender::admin_count() ),
		);
	}

	/**
	 * Ability: applies one recommendation.
	 *
	 * @param mixed $input Ability input.
	 * @return array|\WP_Error
	 */
	public function apply_recommendation( $input = null ) {
		$input = (array) $input;
		$user  = (int) ( $input['user_id'] ?? 0 );
		$role  = (string) ( $input['role'] ?? '' );
		$cap   = (string) ( $input['capability'] ?? '' );

		if ( ! $user && ( '' === $role || '' === $cap ) ) {
			return new \WP_Error( 'role_right_sizer_input', __( 'Pass user_id, or role and capability.', 'role-right-sizer' ), array( 'status' => 400 ) );
		}

		$result = $this->applier->apply_many( $user ? array( $user ) : array(), $user ? array() : array( $role . '|' . $cap ) );
		if ( $result['errors'] ) {
			$error = reset( $result['errors'] );

			return new \WP_Error( $error->get_error_code(), $error->get_error_message(), array( 'status' => 400 ) );
		}

		return array(
			'batch'   => $result['batch'],
			'changes' => $result['changes'],
		);
	}

	/**
	 * Ability: undoes a change or its batch.
	 *
	 * @param mixed $input Ability input.
	 * @return array|\WP_Error
	 */
	public function undo_change( $input = null ) {
		$input = (array) $input;
		$id    = (int) ( $input['change_id'] ?? 0 );

		if ( ! empty( $input['batch'] ) ) {
			$change = $this->changes->get( $id );
			$result = $change ? $this->applier->undo_batch( (int) $change['batch'] ) : array(
				'undone' => array(),
				'errors' => array( new \WP_Error( 'role_right_sizer_not_found', __( 'That change is not in the log.', 'role-right-sizer' ) ) ),
			);
			if ( ! $result['undone'] && $result['errors'] ) {
				$error = reset( $result['errors'] );

				return new \WP_Error( $error->get_error_code(), $error->get_error_message(), array( 'status' => 400 ) );
			}

			return array( 'undone' => $result['undone'] );
		}

		$result = $this->applier->undo( $id );
		if ( is_wp_error( $result ) ) {
			return new \WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 400 ) );
		}

		return array( 'undone' => array( $result ) );
	}

	/**
	 * Ability: the change log.
	 *
	 * @param mixed $input Ability input.
	 * @return array
	 */
	public function get_changes( $input = null ): array {
		$limit   = Settings::clamp( ( (array) $input )['limit'] ?? 50, 50, 1, 200 );
		$changes = array();
		foreach ( $this->changes->all() as $change ) {
			if ( isset( $change['user_id'] ) && ! Access::can_view_user( (int) $change['user_id'] ) ) {
				continue;
			}
			$changes[] = array(
				'id'      => (int) $change['id'],
				'batch'   => (int) $change['batch'],
				'kind'    => (string) $change['kind'],
				'user_id' => (int) ( $change['user_id'] ?? 0 ),
				'before'  => array_values( (array) ( $change['before'] ?? array() ) ),
				'after'   => array_values( (array) ( $change['after'] ?? array() ) ),
				'role'    => (string) ( $change['role'] ?? '' ),
				'cap'     => (string) ( $change['cap'] ?? '' ),
				'lost'    => array_values( (array) $change['lost'] ),
				'time'    => self::iso( (int) $change['time'] ),
				'undone'  => self::iso( (int) $change['undone'] ),
			);
			if ( count( $changes ) >= $limit ) {
				break;
			}
		}

		return array( 'changes' => $changes );
	}

	/**
	 * Ability: the trials.
	 *
	 * @param mixed $input Ability input.
	 * @return array
	 */
	public function list_trials( $input = null ): array {
		$status = (string) ( ( (array) $input )['status'] ?? '' );
		$trials = array();
		foreach ( $this->trials->all() as $trial ) {
			$open = in_array( $trial['status'], array( 'active', 'ended' ), true );
			if ( ( 'open' === $status && ! $open ) || ( '' !== $status && 'open' !== $status && $trial['status'] !== $status ) ) {
				continue;
			}
			if ( ! Access::can_view_user( (int) $trial['user_id'] ) ) {
				continue;
			}
			$trials[] = self::trial_output( $trial );
		}

		return array( 'trials' => $trials );
	}

	/**
	 * Ability: starts a trial.
	 *
	 * @param mixed $input Ability input.
	 * @return array|\WP_Error
	 */
	public function start_trial( $input = null ) {
		$input  = (array) $input;
		$result = $this->trials->start( (int) ( $input['user_id'] ?? 0 ), (int) ( $input['days'] ?? 0 ) );
		if ( is_wp_error( $result ) ) {
			return new \WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 400 ) );
		}
		$this->report->rebuild();

		return array( 'trial' => self::trial_output( $result ) );
	}

	/**
	 * Ability: restores the role after a trial.
	 *
	 * @param mixed $input Ability input.
	 * @return array|\WP_Error
	 */
	public function restore_trial( $input = null ) {
		return $this->trial_result( $this->trials->restore( (int) ( ( (array) $input )['trial_id'] ?? 0 ) ) );
	}

	/**
	 * Ability: extends a trial.
	 *
	 * @param mixed $input Ability input.
	 * @return array|\WP_Error
	 */
	public function extend_trial( $input = null ) {
		$input = (array) $input;

		return $this->trial_result( $this->trials->extend( (int) ( $input['trial_id'] ?? 0 ), (int) ( $input['days'] ?? 0 ) ) );
	}

	/**
	 * Ability: makes a trial permanent.
	 *
	 * @param mixed $input Ability input.
	 * @return array|\WP_Error
	 */
	public function keep_trial( $input = null ) {
		return $this->trial_result( $this->trials->keep( (int) ( ( (array) $input )['trial_id'] ?? 0 ) ) );
	}

	/**
	 * A trial result as ability output, or its error as a 400.
	 *
	 * @param array|\WP_Error $result The trial, or why not.
	 * @return array|\WP_Error
	 */
	private function trial_result( $result ) {
		if ( is_wp_error( $result ) ) {
			return new \WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 400 ) );
		}

		return array( 'trial' => self::trial_output( $result ) );
	}

	/**
	 * A trial as ability output.
	 *
	 * @param array $trial The trial.
	 * @return array
	 */
	private static function trial_output( array $trial ): array {
		$denials = array();
		foreach ( (array) $trial['denials'] as $denial ) {
			$denials[] = array(
				'label' => (string) $denial['label'],
				'kind'  => (string) $denial['kind'],
				'cap'   => (string) $denial['cap'],
				'count' => (int) $denial['count'],
				'last'  => self::iso( (int) $denial['last'] ),
			);
		}

		return array(
			'id'           => (int) $trial['id'],
			'user_id'      => (int) $trial['user_id'],
			'login'        => (string) $trial['login'],
			'status'       => (string) $trial['status'],
			'started'      => self::iso( (int) $trial['started'] ),
			'ends'         => self::iso( (int) $trial['ends'] ),
			'days_left'    => Trials::days_left( $trial ),
			'before'       => array_values( (array) $trial['before'] ),
			'during'       => array_values( (array) $trial['after'] ),
			'change_id'    => (int) $trial['change_id'],
			'requested'    => self::iso( (int) $trial['requested'] ),
			'denial_count' => Trials::denial_total( $trial ),
			'denials'      => $denials,
		);
	}

	/**
	 * The preview Agent Governance shows before someone approves a held call
	 * to apply-recommendation or undo-change. Works it out without changing anything.
	 *
	 * @param mixed $preview Null, or a preview another plugin made.
	 * @param mixed $call    source, target, method, kind and input.
	 * @return mixed
	 */
	public function dry_run( $preview, $call ) {
		if ( null !== $preview || ! is_array( $call ) || 'ability' !== ( $call['source'] ?? '' ) ) {
			return $preview;
		}

		$input = is_array( $call['input'] ?? null ) ? $call['input'] : array();
		switch ( (string) ( $call['target'] ?? '' ) ) {
			case self::CATEGORY . '/apply-recommendation':
				return $this->preview_apply( $input );
			case self::CATEGORY . '/undo-change':
				return $this->preview_undo( $input );
			case self::CATEGORY . '/start-trial':
				return $this->preview_start_trial( $input );
			case self::CATEGORY . '/restore-trial':
				return $this->preview_restore_trial( $input );
		}

		return $preview;
	}

	/**
	 * Preview of apply-recommendation.
	 *
	 * @param array $input Ability input.
	 * @return array
	 */
	private function preview_apply( array $input ): array {
		$user_id = (int) ( $input['user_id'] ?? 0 );
		if ( $user_id ) {
			$user           = get_userdata( $user_id );
			$recommendation = $user ? $this->recommender->for_user( $user, time(), Recommender::admin_count() ) : null;
			if ( ! $user || ! $recommendation ) {
				return array( 'summary' => __( 'There is nothing to change for this user, so this call will fail.', 'role-right-sizer' ) );
			}

			return array(
				'summary' => sprintf(
					/* translators: 1: user name, 2: username, 3: roles now, 4: recommended role, 5: number of capabilities. */
					_n( 'Moves %1$s (%2$s) from %3$s to %4$s. They lose %5$s capability. Undo puts it back.', 'Moves %1$s (%2$s) from %3$s to %4$s. They lose %5$s capabilities. Undo puts them back.', count( $recommendation['lost'] ), 'role-right-sizer' ),
					$user->display_name,
					$user->user_login,
					Screen::roles_label( $recommendation['current'] ),
					Screen::role_label( $recommendation['proposed'] ),
					number_format_i18n( count( $recommendation['lost'] ) )
				),
				'object'  => array(
					'type'  => 'user',
					'id'    => $user->ID,
					'label' => $user->display_name,
					'url'   => (string) get_edit_user_link( $user->ID ),
				),
				'changes' => array(
					array(
						'field'  => __( 'Role', 'role-right-sizer' ),
						'before' => Screen::roles_label( $recommendation['current'] ),
						'after'  => Screen::role_label( $recommendation['proposed'] ),
					),
					array(
						'field'  => __( 'Capabilities lost', 'role-right-sizer' ),
						'before' => '',
						'after'  => implode( ', ', $recommendation['lost'] ),
					),
				),
			);
		}

		$role = (string) ( $input['role'] ?? '' );
		$cap  = (string) ( $input['capability'] ?? '' );
		if ( '' === $role || '' === $cap ) {
			return array( 'summary' => __( 'Pass user_id, or role and capability.', 'role-right-sizer' ) );
		}
		if ( in_array( $role, Recommender::protected_roles(), true ) ) {
			return array( 'summary' => __( 'Capabilities are never removed from the administrator role or a role that can manage options, so this call will fail.', 'role-right-sizer' ) );
		}

		$owner = '';
		foreach ( $this->report->roles() as $recommendation ) {
			if ( $recommendation['role'] === $role && $recommendation['cap'] === $cap ) {
				$owner = Screen::owner_label( is_array( $recommendation['owner'] ?? null ) ? $recommendation['owner'] : null );
			}
		}

		return array(
			'summary' => trim(
				sprintf(
					/* translators: 1: capability, 2: role. */
					__( 'Removes %1$s from the %2$s role, for everyone with the role. Undo puts it back.', 'role-right-sizer' ),
					$cap,
					Screen::role_label( $role )
				) . ' ' . $owner
			),
			'changes' => array(
				array(
					/* translators: %s: role. */
					'field'  => sprintf( __( 'Capabilities of %s', 'role-right-sizer' ), Screen::role_label( $role ) ),
					'before' => $cap,
					'after'  => '',
				),
			),
		);
	}

	/**
	 * Preview of undo-change.
	 *
	 * @param array $input Ability input.
	 * @return array
	 */
	private function preview_undo( array $input ): array {
		$change = $this->changes->get( (int) ( $input['change_id'] ?? 0 ) );
		if ( ! $change ) {
			return array( 'summary' => __( 'That change is not in the log, so this call will fail.', 'role-right-sizer' ) );
		}

		$list = ! empty( $input['batch'] ) ? $this->changes->batch( (int) $change['batch'] ) : array( $change );
		$rows = array();
		foreach ( $list as $item ) {
			if ( $item['undone'] ) {
				continue;
			}
			if ( 'unused_cap' === $item['kind'] ) {
				$rows[] = array(
					/* translators: %s: role. */
					'field'  => sprintf( __( 'Capabilities of %s', 'role-right-sizer' ), Screen::role_label( (string) $item['role'] ) ),
					'before' => '',
					'after'  => (string) $item['cap'],
				);
			} else {
				$user   = get_userdata( (int) $item['user_id'] );
				$rows[] = array(
					/* translators: %s: user name. */
					'field'  => sprintf( __( 'Role of %s', 'role-right-sizer' ), $user ? $user->display_name : (string) $item['login'] ),
					'before' => Screen::roles_label( $user ? array_values( (array) $user->roles ) : (array) $item['after'] ),
					'after'  => Screen::roles_label( (array) $item['before'] ),
				);
			}
		}

		if ( ! $rows ) {
			return array( 'summary' => __( 'That change was already undone, so this call will fail.', 'role-right-sizer' ) );
		}

		return array(
			/* translators: %s: number of changes. */
			'summary' => sprintf( _n( 'Undoes %s Role Right-Sizer change and puts back what it took away.', 'Undoes %s Role Right-Sizer changes and puts back what they took away.', count( $rows ), 'role-right-sizer' ), number_format_i18n( count( $rows ) ) ),
			'changes' => $rows,
		);
	}

	/**
	 * Preview of start-trial.
	 *
	 * @param array $input Ability input.
	 * @return array
	 */
	private function preview_start_trial( array $input ): array {
		$user           = get_userdata( (int) ( $input['user_id'] ?? 0 ) );
		$recommendation = $user ? $this->recommender->for_user( $user, time(), Recommender::admin_count() ) : null;
		if ( ! $user || ! $recommendation || ! in_array( $recommendation['type'], $this->trials->types(), true ) ) {
			return array( 'summary' => __( 'This person cannot be put on a trial, so this call will fail.', 'role-right-sizer' ) );
		}

		$days = (int) ( $input['days'] ?? 0 ) > 0 ? (int) $input['days'] : $this->trials->days();

		return array(
			'summary' => sprintf(
				/* translators: 1: user name, 2: username, 3: roles now, 4: recommended role, 5: number of days. */
				_n( 'Moves %1$s (%2$s) from %3$s to %4$s for %5$s day. Whatever they cannot do is recorded. Restoring gives the old role back.', 'Moves %1$s (%2$s) from %3$s to %4$s for %5$s days. Whatever they cannot do is recorded. Restoring gives the old role back.', $days, 'role-right-sizer' ),
				$user->display_name,
				$user->user_login,
				Screen::roles_label( $recommendation['current'] ),
				Screen::role_label( $recommendation['proposed'] ),
				number_format_i18n( $days )
			),
			'object'  => array(
				'type'  => 'user',
				'id'    => $user->ID,
				'label' => $user->display_name,
				'url'   => (string) get_edit_user_link( $user->ID ),
			),
			'changes' => array(
				array(
					'field'  => __( 'Role', 'role-right-sizer' ),
					'before' => Screen::roles_label( $recommendation['current'] ),
					'after'  => Screen::role_label( $recommendation['proposed'] ),
				),
			),
		);
	}

	/**
	 * Preview of restore-trial.
	 *
	 * @param array $input Ability input.
	 * @return array
	 */
	private function preview_restore_trial( array $input ): array {
		$trial = $this->trials->get( (int) ( $input['trial_id'] ?? 0 ) );
		if ( ! $trial || ! in_array( $trial['status'], array( 'active', 'ended' ), true ) ) {
			return array( 'summary' => __( 'That trial is not open, so this call will fail.', 'role-right-sizer' ) );
		}

		$user = get_userdata( (int) $trial['user_id'] );

		return array(
			/* translators: %s: user name. */
			'summary' => sprintf( __( 'Gives %s their old role back and ends the trial.', 'role-right-sizer' ), $user ? $user->display_name : (string) $trial['login'] ),
			'changes' => array(
				array(
					'field'  => __( 'Role', 'role-right-sizer' ),
					'before' => Screen::roles_label( $user ? array_values( (array) $user->roles ) : (array) $trial['after'] ),
					'after'  => Screen::roles_label( (array) $trial['before'] ),
				),
			),
		);
	}

	/**
	 * A user by ID, username or email address.
	 *
	 * @param mixed $key ID, username or email.
	 * @return \WP_User|null
	 */
	public static function find_user( $key ): ?\WP_User {
		$key = trim( (string) $key );
		if ( '' === $key ) {
			return null;
		}

		if ( ctype_digit( $key ) ) {
			$user = get_userdata( (int) $key );
		} elseif ( false !== strpos( $key, '@' ) ) {
			$user = get_user_by( 'email', $key );
		} else {
			$user = get_user_by( 'login', $key );
		}

		return $user instanceof \WP_User ? $user : null;
	}

	/**
	 * A time as ISO 8601, or '' for never.
	 *
	 * @param int $time Unix time.
	 * @return string
	 */
	private static function iso( int $time ): string {
		return $time ? gmdate( 'c', $time ) : '';
	}

	/**
	 * Registers one ability in the Role Right-Sizer category.
	 *
	 * @param string   $name        Name without the namespace.
	 * @param string   $label       Label.
	 * @param string   $description What it does, for people and agents.
	 * @param array    $input       Input schema, or empty for none.
	 * @param array    $output      Output schema.
	 * @param callable $execute     Execute callback.
	 * @param callable $permission  Permission callback.
	 * @param array    $annotations readonly, destructive, idempotent.
	 */
	private function ability( string $name, string $label, string $description, array $input, array $output, callable $execute, callable $permission, array $annotations ): void {
		$args = array(
			'label'               => $label,
			'description'         => $description,
			'category'            => self::CATEGORY,
			'output_schema'       => $output,
			'execute_callback'    => $execute,
			'permission_callback' => $permission,
			'meta'                => array(
				'annotations'  => $annotations,
				'show_in_rest' => true,
				// Clients such as the MCP adapter only list abilities marked public:
				// `public` from WordPress 7.1, `mcp.public` for the adapter on 6.9 and 7.0.
				'public'       => true,
				'mcp'          => array( 'public' => true ),
			),
		);
		if ( $input ) {
			$args['input_schema'] = $input;
		}

		wp_register_ability( self::CATEGORY . '/' . $name, $args );
	}
}
