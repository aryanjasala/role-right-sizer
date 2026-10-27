<?php
/**
 * Tests for when roles gained capabilities, and who a capability belongs to.
 *
 * @package AryanX\Axle\RoleRightSizer
 */

namespace AryanX\Axle\RoleRightSizer\Tests\Unit;

use AryanX\Axle\RoleRightSizer\CapabilityHistory;
use AryanX\Axle\RoleRightSizer\CapabilityOwners;
use AryanX\Axle\RoleRightSizer\Settings;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

final class CapabilityHistoryTest extends PluginTestCase {

	private function history(): CapabilityHistory {
		return new CapabilityHistory( new Settings() );
	}

	/**
	 * Roles as core stores them in the roles option.
	 *
	 * @param array<string, string[]> $roles Role => granted capabilities.
	 * @return array
	 */
	private static function stored( array $roles ): array {
		$stored = array();
		foreach ( $roles as $name => $caps ) {
			$stored[ $name ] = array(
				'name'         => ucfirst( $name ),
				'capabilities' => array_fill_keys( $caps, true ),
			);
		}
		return $stored;
	}

	public function test_it_watches_the_roles_option(): void {
		$history = $this->history();
		$history->register();

		$this->assertNotFalse( has_action( 'added_option', array( $history, 'option_added' ) ) );
		$this->assertNotFalse( has_action( 'updated_option', array( $history, 'option_updated' ) ) );
		$this->assertSame( 'wp_user_roles', CapabilityHistory::roles_option() );
	}

	public function test_a_fresh_install_counts_what_roles_have_from_the_start_of_tracking(): void {
		$this->tracking_started( 0 );
		$started = $this->options[ Settings::STARTED ];

		$history = $this->history()->current();

		$this->assertSame( array( $started, '' ), $history['editor']['edit_others_posts'] );
		$this->assertSame( $history, $this->options[ CapabilityHistory::OPTION ] );
	}

	public function test_on_a_site_that_tracked_before_the_history_existed_capabilities_count_from_now(): void {
		$this->tracking_started( 40 );

		$history = $this->history()->current();

		$this->assertEqualsWithDelta( time(), $history['editor']['edit_others_posts'][0], 5 );
	}

	public function test_a_capability_a_role_gains_later_counts_from_then_and_one_taken_away_is_forgotten(): void {
		$this->tracking_started( 40 );
		$this->had( 'editor', array( 'edit_posts', 'old_cap' ), 40 );
		$history = $this->history();

		$history->option_updated(
			'wp_user_roles',
			self::stored( array( 'editor' => array( 'edit_posts', 'old_cap' ) ) ),
			self::stored( array( 'editor' => array( 'edit_posts', 'view_shop_reports' ) ) )
		);

		$saved = $this->options[ CapabilityHistory::OPTION ];
		$this->assertEqualsWithDelta( time() - 40 * DAY_IN_SECONDS, $saved['editor']['edit_posts'][0], 5 );
		$this->assertEqualsWithDelta( time(), $saved['editor']['view_shop_reports'][0], 5 );
		$this->assertArrayNotHasKey( 'old_cap', $saved['editor'] );

		// Other options are ignored.
		$history->option_updated( 'blogname', 'a', 'b' );
		$this->assertSame( $saved, $this->options[ CapabilityHistory::OPTION ] );
	}

	public function test_the_first_change_sets_a_baseline_from_the_roles_before_it(): void {
		$this->tracking_started( 0 );
		$started = $this->options[ Settings::STARTED ];

		$this->history()->option_added( 'wp_user_roles', self::stored( array( 'shop_editor' => array( 'read' ) ) ) );
		$this->assertEqualsWithDelta( time(), $this->options[ CapabilityHistory::OPTION ]['shop_editor']['read'][0], 5 );

		unset( $this->options[ CapabilityHistory::OPTION ] );
		$this->history()->option_updated(
			'wp_user_roles',
			self::stored( array( 'shop_editor' => array( 'read' ) ) ),
			self::stored( array( 'shop_editor' => array( 'read', 'export_orders' ) ) )
		);
		$saved = $this->options[ CapabilityHistory::OPTION ];
		$this->assertSame( $started, $saved['shop_editor']['read'][0] );
		$this->assertArrayHasKey( 'export_orders', $saved['shop_editor'] );
	}

	public function test_the_plugin_that_added_a_capability_is_read_from_the_call_stack(): void {
		defined( 'WP_PLUGIN_DIR' ) || define( 'WP_PLUGIN_DIR', dirname( __DIR__ ) . '/fixtures/plugins' );
		$this->assertSame( dirname( __DIR__ ) . '/fixtures/plugins', WP_PLUGIN_DIR );
		$this->tracking_started( 40 );
		$this->had( 'shop_editor', array( 'read' ), 40 );
		$history = $this->history();
		$plugin  = require WP_PLUGIN_DIR . '/acme-shop/acme-shop.php';

		$plugin(
			static function () use ( $history ) {
				$history->option_updated(
					'wp_user_roles',
					self::stored( array( 'shop_editor' => array( 'read' ) ) ),
					self::stored( array( 'shop_editor' => array( 'read', 'manage_acme' ) ) )
				);
			}
		);

		$saved = $this->options[ CapabilityHistory::OPTION ];
		$this->assertSame( 'acme-shop', $saved['shop_editor']['manage_acme'][1] );
		$this->assertSame( '', $saved['shop_editor']['read'][1] );
		$this->assertSame( 'acme-shop', CapabilityHistory::added_by( $saved, 'shop_editor', 'manage_acme' ) );
		$this->assertSame( 0, CapabilityHistory::first_seen( $saved, 'shop_editor', 'nope' ) );

		// Outside a plugin file, nobody is named.
		$this->assertSame( '', CapabilityHistory::caller() );
	}

	public function test_owners_from_an_active_plugin_by_name_and_a_registered_taxonomy(): void {
		$this->options['active_plugins'] = array( 'agent-governance/agent-governance.php', 'abc/abc.php' );
		Functions\when( 'get_plugins' )->justReturn(
			array(
				'agent-governance/agent-governance.php' => array( 'Name' => 'Agent Governance' ),
				'abc/abc.php'                           => array( 'Name' => 'ABC' ),
				'woocommerce/woocommerce.php'           => array( 'Name' => 'WooCommerce' ),
			)
		);
		Functions\when( 'get_taxonomies' )->justReturn(
			array(
				'product_cat' => (object) array(
					'label' => 'Product categories',
					'cap'   => (object) array( 'manage_terms' => 'manage_product_terms' ),
				),
			)
		);
		$owners = new CapabilityOwners();

		$this->assertSame( array( 'type' => 'plugin', 'id' => 'agent-governance/agent-governance.php', 'name' => 'Agent Governance', 'active' => true ), $owners->owner( 'editor', 'agent_governance_approve' ) );
		$this->assertSame( 'taxonomy', $owners->owner( 'editor', 'manage_product_terms' )['type'] );
		// WooCommerce is installed but not active: its name in a capability says nothing.
		$this->assertNull( $owners->owner( 'editor', 'manage_woocommerce' ) );
		// Folder names shorter than four letters are too vague to match.
		$this->assertNull( $owners->owner( 'editor', 'abc_reports' ) );
		// Recorded at the time: installed but inactive.
		$this->assertSame( array( 'type' => 'plugin', 'id' => 'woocommerce/woocommerce.php', 'name' => 'WooCommerce', 'active' => false ), $owners->owner( 'editor', 'manage_woocommerce', 'woocommerce' ) );
	}

	public function test_the_owner_takes_a_filter_and_bad_values_mean_unknown(): void {
		$owners = new CapabilityOwners();

		Filters\expectApplied( 'role_right_sizer_capability_owner' )->once()->with( null, 'view_reports', 'shop_editor' )->andReturn( array( 'name' => 'My Shop', 'active' => 1 ) );
		$this->assertSame( array( 'type' => 'plugin', 'id' => '', 'name' => 'My Shop', 'active' => true ), $owners->owner( 'shop_editor', 'view_reports' ) );

		Filters\expectApplied( 'role_right_sizer_capability_owner' )->once()->andReturn( array( 'name' => '' ) );
		$this->assertNull( $owners->owner( 'shop_editor', 'view_reports' ) );

		$this->assertSame( '', CapabilityOwners::key( null ) );
		$this->assertSame( 'plugin:My Shop', CapabilityOwners::key( CapabilityOwners::normalize( array( 'name' => 'My Shop' ) ) ) );
	}
}
