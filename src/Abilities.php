<?php
/**
 * Abilities: recommendations and changes for AI agents and other Abilities API clients.
 *
 * @package AryanX\RoleRightSizer
 */

namespace AryanX\RoleRightSizer;

use AryanX\RoleRightSizer\Admin\Screen;
use AryanX\RoleRightSizer\Usage\Store;

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
	 * Sets up the abilities.
	 *
	 * @param Settings    $settings    Settings and limits.
	 * @param Store       $store       Recorded usage.
	 * @param Recommender $recommender Works out recommendations.
	 * @param Report      $report      Saved recommendations.
	 * @param Changes     $changes     Change log.
	 * @param Applier     $applier     Applies and undoes changes.
	 */
	public function __construct( Settings $settings, Store $store, Recommender $recommender, Report $report, Changes $changes, Applier $applier ) {
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
								'role'  => array( 'type' => 'string' ),
								'cap'   => array( 'type' => 'string' ),
								'users' => array( 'type' => 'integer' ),
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
			$can_manage,
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
			$can_manage,
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
			$can_manage,
			array(
				'readonly'    => false,
				'destructive' => true,
				'idempotent'  => false,
			)
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
		foreach ( $learning ? array() : $report['users'] as $recommendation ) {
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

		return array(
			'learning'      => $learning,
			'learning_ends' => self::iso( $this->settings->learning_ends() ),
			'built'         => self::iso( $report['built'] ),
			'users'         => $users,
			'roles'         => $learning ? array() : $report['roles'],
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
