<?php
/**
 * Tests for how usage is stored and throttled.
 *
 * @package AryanX\RoleRightSizer
 */

namespace AryanX\RoleRightSizer\Tests\Unit;

use AryanX\RoleRightSizer\Usage\Store;

final class StoreTest extends PluginTestCase {

	public function test_merge_adds_new_names_and_updates_old_ones_once_per_interval(): void {
		$now = 1000000;
		list( $record, $changed ) = Store::merge( array(), array( 'edit_posts', 'edit_posts', '' ), $now, 3600 );
		$this->assertTrue( $changed );
		$this->assertSame( array( 'edit_posts' => array( $now, $now, 1 ) ), $record );

		list( $same, $changed ) = Store::merge( $record, array( 'edit_posts' ), $now + 3599, 3600 );
		$this->assertFalse( $changed );
		$this->assertSame( $record, $same );

		list( $later, $changed ) = Store::merge( $record, array( 'edit_posts', 'publish_posts' ), $now + 3600, 3600 );
		$this->assertTrue( $changed );
		$this->assertSame( array( $now, $now + 3600, 2 ), $later['edit_posts'] );
		$this->assertSame( array( $now + 3600, $now + 3600, 1 ), $later['publish_posts'] );
	}

	public function test_add_usage_writes_only_when_something_changed(): void {
		$store = new Store();
		$this->assertTrue( $store->add_usage( 5, array( 'edit_posts' ), time(), 3600 ) );
		$this->assertArrayHasKey( 'edit_posts', $this->meta[5]['wp_role_right_sizer_usage'] );

		unset( $this->meta[5]['wp_role_right_sizer_usage']['nothing'] );
		$this->assertFalse( $store->add_usage( 5, array( 'edit_posts' ), time(), 3600 ) );
		$this->assertTrue( $store->add_abilities( 5, array( 'site/ability' ), time(), 3600 ) );
		$this->assertSame( array( 'site/ability' ), array_keys( $store->abilities( 5 ) ) );
	}

	public function test_used_since_keeps_the_window_and_clean_drops_junk(): void {
		$this->meta[5]['wp_role_right_sizer_usage'] = array(
			'old'    => array( 1, 100, 1 ),
			'recent' => array( 1, 5000, 3 ),
			'broken' => 'x',
			7        => array( 1, 1, 1 ),
		);
		$store = new Store();

		$this->assertSame( array( 'recent' => 5000 ), $store->used_since( 5, 1000 ) );
		$this->assertSame( array( 'old', 'recent' ), array_keys( $store->usage( 5 ) ) );
	}

	public function test_activity_is_throttled_and_login_is_network_wide(): void {
		$store = new Store();
		$now   = time();

		$this->assertTrue( $store->touch( 5, $now, 3600 ) );
		$this->assertFalse( $store->touch( 5, $now + 10, 3600 ) );
		$this->assertTrue( $store->touch( 5, $now + 3600, 3600 ) );

		$store->login( 5, $now );
		$this->assertSame( $now, $this->meta[5]['role_right_sizer_last_login'] );
		$this->assertSame( $now + 3600, $store->last_seen( 5 ) );
	}

	public function test_forget_deletes_everything_for_the_user(): void {
		$store = new Store();
		$store->add_usage( 5, array( 'edit_posts' ), time(), 0 );
		$store->login( 5, time() );

		$this->assertTrue( $store->forget( 5 ) );
		$this->assertSame( array(), $this->meta[5] );
		$this->assertFalse( $store->forget( 5 ) );
	}
}
