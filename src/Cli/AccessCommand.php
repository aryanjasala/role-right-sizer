<?php
/**
 * `wp role-right-sizer access` commands: "Why can't they?" and the fix.
 *
 * @package AryanX\Axle\RoleRightSizer
 */

namespace AryanX\Axle\RoleRightSizer\Cli;

use AryanX\Axle\RoleRightSizer\Abilities;
use AryanX\Axle\RoleRightSizer\Applier;
use AryanX\Axle\RoleRightSizer\Probe\Prober;

defined( 'ABSPATH' ) || exit;

/**
 * Checks what stops a person, and gives them the access that is missing.
 */
final class AccessCommand {

	/**
	 * Runs the checks.
	 *
	 * @var Prober
	 */
	private Prober $prober;

	/**
	 * Gives access.
	 *
	 * @var Applier
	 */
	private Applier $applier;

	/**
	 * Sets up the command.
	 *
	 * @param Prober  $prober  Runs the checks.
	 * @param Applier $applier Gives access.
	 */
	public function __construct( Prober $prober, Applier $applier ) {
		$this->prober  = $prober;
		$this->applier = $applier;
	}

	/**
	 * Tries an admin screen or a REST route as a person and says what stopped it.
	 *
	 * The person is not signed in. A REST write is checked for permission and then stopped, so nothing is saved.
	 *
	 * ## OPTIONS
	 *
	 * <user>
	 * : User ID, username or email address.
	 *
	 * [--screen=<screen>]
	 * : An admin screen of this site, such as upload.php or admin.php?page=my-plugin.
	 *
	 * [--route=<route>]
	 * : A REST route of this site, such as /wp/v2/media.
	 *
	 * [--method=<method>]
	 * : GET, POST, PUT, PATCH or DELETE. For routes only.
	 * ---
	 * default: GET
	 * ---
	 *
	 * [--format=<format>]
	 * : Render the result as text or json.
	 * ---
	 * default: text
	 * options:
	 *   - text
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp role-right-sizer access check pat --route=/wp/v2/media --method=POST
	 *     wp role-right-sizer access check pat --screen=plugins.php --format=json
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function check( array $args, array $assoc_args ): void {
		$user = Abilities::find_user( (string) ( $args[0] ?? '' ) );
		if ( ! $user ) {
			\WP_CLI::error( 'No such user.' );
		}
		if ( isset( $assoc_args['screen'] ) === isset( $assoc_args['route'] ) ) {
			\WP_CLI::error( 'Give either --screen or --route.' );
		}

		$kind   = isset( $assoc_args['route'] ) ? 'rest' : 'screen';
		$target = (string) $assoc_args[ 'rest' === $kind ? 'route' : 'screen' ];
		$result = $this->prober->run( $user->ID, $kind, $target, (string) ( $assoc_args['method'] ?? 'GET' ) );
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}

		$fixes = array();
		foreach ( 'refused' === $result['verdict'] && '' !== $result['cap'] ? Prober::fixes( $user, $result['cap'] ) : array() as $fix ) {
			$fixes[] = array(
				'type'   => $fix['type'],
				'value'  => $fix['value'],
				'gained' => $fix['gained'],
			);
		}
		$result['roles_with_cap'] = '' !== $result['cap'] ? Prober::roles_with( $result['cap'] ) : array();
		$result['fixes']          = $fixes;

		if ( 'json' === ( $assoc_args['format'] ?? 'text' ) ) {
			\WP_CLI::line( (string) wp_json_encode( $result ) );
			return;
		}

		\WP_CLI::line( sprintf( '%s: %s (status %d)', $user->user_login, $result['verdict'], $result['status'] ) );
		if ( '' !== $result['cap'] ) {
			\WP_CLI::line( sprintf( 'Missing capability: %s', $result['cap'] ) );
			\WP_CLI::line( sprintf( 'Roles that have it: %s', $result['roles_with_cap'] ? implode( ', ', $result['roles_with_cap'] ) : 'none' ) );
		} elseif ( 'refused' === $result['verdict'] ) {
			\WP_CLI::line( $result['outright'] ? 'WordPress refuses this outright, whatever the role.' : 'Refused, but no capability check failed.' );
		}
		foreach ( $fixes as $fix ) {
			\WP_CLI::line( sprintf( 'Fix: wp role-right-sizer access fix %s --%s=%s', $user->user_login, $fix['type'], $fix['value'] ) );
		}
	}

	/**
	 * Gives a person one capability, or one more role, so a refused action works. Goes in the change log.
	 *
	 * ## OPTIONS
	 *
	 * <user>
	 * : User ID, username or email address.
	 *
	 * [--cap=<capability>]
	 * : Give this capability to the person only.
	 *
	 * [--role=<role>]
	 * : Add this role on top of the person's roles.
	 *
	 * [--dry-run]
	 * : Say what would be given and change nothing.
	 *
	 * [--yes]
	 * : Skip the confirmation.
	 *
	 * ## EXAMPLES
	 *
	 *     wp role-right-sizer access fix pat --cap=upload_files
	 *     wp role-right-sizer access fix pat --role=author --dry-run
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function fix( array $args, array $assoc_args ): void {
		$user = Abilities::find_user( (string) ( $args[0] ?? '' ) );
		if ( ! $user ) {
			\WP_CLI::error( 'No such user.' );
		}
		if ( isset( $assoc_args['cap'] ) === isset( $assoc_args['role'] ) ) {
			\WP_CLI::error( 'Give either --cap or --role.' );
		}

		$type  = isset( $assoc_args['cap'] ) ? 'cap' : 'role';
		$value = (string) $assoc_args[ $type ];
		if ( 'role' === $type ) {
			$value = sanitize_key( $value );
		}

		if ( ! empty( $assoc_args['dry-run'] ) ) {
			\WP_CLI::line( sprintf( 'Would give %s the %s %s.', $user->user_login, $value, $type ) );
			\WP_CLI::success( 'Dry run: nothing was changed.' );
			return;
		}

		\WP_CLI::confirm( sprintf( 'Give %s the %s %s?', $user->user_login, $value, $type ), $assoc_args );
		$change = $this->applier->apply_fix( $user->ID, $type, $value );
		if ( is_wp_error( $change ) ) {
			\WP_CLI::error( $change->get_error_message() );
		}

		\WP_CLI::success( sprintf( 'Change %d: gave %s the %s %s. Undo with: wp role-right-sizer undo %d', $change['id'], $user->user_login, $value, $type, $change['id'] ) );
	}
}
