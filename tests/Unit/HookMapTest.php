<?php
/**
 * Tests for working out the capability each WordPress event proves.
 *
 * @package AryanX\RoleRightSizer
 */

namespace AryanX\RoleRightSizer\Tests\Unit;

use AryanX\RoleRightSizer\Usage\HookMap;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

final class HookMapTest extends PluginTestCase {

	protected function setUp(): void {
		parent::setUp();

		$this->current_user = 2;
		Functions\when( 'get_post_type_object' )->alias(
			static function ( $type ) {
				return 'page' === $type ? new \WP_Post_Type( 'page' ) : new \WP_Post_Type();
			}
		);
		Functions\when( 'wp_is_post_revision' )->justReturn( false );
		Functions\when( 'wp_is_post_autosave' )->justReturn( false );
	}

	private function post( array $fields ): \WP_Post {
		return new \Role_Right_Sizer_Test_Post(
			$fields + array(
				'ID'          => 10,
				'post_type'   => 'post',
				'post_author' => 2,
				'post_status' => 'draft',
			)
		);
	}

	public function test_edit_caps_mirror_core(): void {
		$cap = ( new \WP_Post_Type() )->cap;

		$this->assertSame( array( 'edit_posts' ), HookMap::edit_caps( $cap, 2, 2, 'draft' ) );
		$this->assertSame( array( 'edit_published_posts' ), HookMap::edit_caps( $cap, 2, 2, 'publish' ) );
		$this->assertSame( array( 'edit_posts' ), HookMap::edit_caps( $cap, 9, 2, 'new' ) );
		$this->assertSame( array( 'edit_others_posts', 'edit_published_posts' ), HookMap::edit_caps( $cap, 9, 2, 'future' ) );
		$this->assertSame( array( 'edit_others_posts', 'edit_private_posts' ), HookMap::edit_caps( $cap, 9, 2, 'private' ) );
		$this->assertSame( array( 'edit_others_posts' ), HookMap::edit_caps( $cap, 0, 2, 'draft' ) );
	}

	public function test_delete_caps_mirror_core(): void {
		$cap = ( new \WP_Post_Type( 'page' ) )->cap;

		$this->assertSame( array( 'delete_pages' ), HookMap::delete_caps( $cap, 2, 2, 'draft' ) );
		$this->assertSame( array( 'delete_published_pages' ), HookMap::delete_caps( $cap, 2, 2, 'publish' ) );
		$this->assertSame( array( 'delete_others_pages', 'delete_private_pages' ), HookMap::delete_caps( $cap, 9, 2, 'private' ) );
	}

	public function test_publishing_and_then_saving_uses_the_status_before_the_save(): void {
		$map  = new HookMap();
		$post = $this->post(
			array(
				'post_status' => 'publish',
				'post_author' => 9,
			)
		);

		$this->assertSame( array( 'publish_posts' ), $map->post_status( 'publish', 'draft', $post ) );
		// Someone else's draft became published: editing it needed edit_others_posts, not edit_published_posts.
		$this->assertSame( array( 'edit_others_posts' ), $map->post_saved( 10, $post, true ) );
		// Without a transition the post's own status counts.
		$this->assertSame( array( 'edit_others_posts', 'edit_published_posts' ), $map->post_saved( 10, $post, true ) );
		$this->assertSame( array(), $map->post_status( 'publish', 'publish', $post ) );
		// A brand-new post only needs edit_posts.
		$this->assertSame( array( 'edit_posts' ), $map->post_saved( 11, $this->post( array( 'ID' => 11 ) ), false ) );
	}

	public function test_ignored_post_types_take_a_filter(): void {
		$map = new HookMap();
		$this->assertSame( array(), $map->post_saved( 10, $this->post( array( 'post_type' => 'revision' ) ), true ) );

		Filters\expectApplied( 'role_right_sizer_ignored_post_types' )->once()->andReturn( array( 'post' ) );
		$this->assertSame( array(), $map->post_saved( 10, $this->post( array() ), true ) );
		$this->assertSame( array(), $map->post_saved( 10, 'not a post', true ) );
	}

	public function test_trashing_a_post_reads_the_status_it_had(): void {
		Functions\when( 'get_post' )->justReturn(
			$this->post(
				array(
					'post_status' => 'trash',
					'post_author' => 2,
				)
			)
		);
		Functions\when( 'get_post_meta' )->justReturn( 'publish' );

		$this->assertSame( array( 'delete_published_posts' ), ( new HookMap() )->post_deleted( 10 ) );
	}

	public function test_upgrades_installs_and_translations(): void {
		$map = new HookMap();

		$this->assertSame( array( 'update_plugins' ), $map->upgraded( null, array( 'type' => 'plugin', 'action' => 'update' ) ) );
		$this->assertSame( array( 'install_themes' ), $map->upgraded( null, array( 'type' => 'theme', 'action' => 'install' ) ) );
		$this->assertSame( array( 'update_core' ), $map->upgraded( null, array( 'type' => 'core' ) ) );
		$this->assertSame( array( 'install_languages' ), $map->upgraded( null, array( 'type' => 'translation' ) ) );
		$this->assertSame( array(), $map->upgraded( null, array() ) );
	}

	public function test_user_management_counts_only_on_someone_else(): void {
		$map = new HookMap();

		$this->assertSame( array( 'create_users' ), $map->user_created( 7 ) );
		$this->assertSame( array( 'edit_users' ), $map->user_edited( 7 ) );
		$this->assertSame( array(), $map->user_edited( 2 ) );
		$this->assertSame( array( 'delete_users' ), $map->user_deleted( 7 ) );
		$this->assertSame( array( 'remove_users' ), $map->user_removed( 7 ) );
		$this->assertSame( array( 'promote_users' ), $map->role_changed( 7, 'editor', array( 'author' ) ) );
		// A new account gets its first role with no old roles: that is creating, not promoting.
		$this->assertSame( array(), $map->role_changed( 7, 'subscriber', array() ) );

		$this->current_user = 0;
		$this->assertSame( array(), $map->user_created( 7 ) );
	}

	public function test_options_saves_need_a_valid_nonce_and_use_the_page_capability(): void {
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_verify_nonce' )->alias(
			static function ( $nonce, $action ) {
				return 'good' === $nonce && 'privacy-options' === $action;
			}
		);
		$map = new HookMap();

		$_POST = array();
		$this->assertSame( array(), $map->options_saved() );

		$_POST = array(
			'option_page' => 'privacy',
			'_wpnonce'    => 'bad',
			'action'      => 'update',
		);
		$this->assertSame( array(), $map->options_saved() );

		$_POST['_wpnonce'] = 'good';
		Filters\expectApplied( 'option_page_capability_privacy' )->once()->with( 'manage_options' )->andReturn( 'manage_privacy_options' );
		$this->assertSame( array( 'manage_privacy_options' ), $map->options_saved() );
		$_POST = array();
	}

	public function test_terms_and_the_customizer_map_to_primitives(): void {
		Functions\when( 'get_taxonomy' )->alias(
			static function ( $taxonomy ) {
				return 'category' === $taxonomy ? (object) array(
					'cap' => (object) array(
						'edit_terms'   => 'edit_categories',
						'delete_terms' => 'delete_categories',
					),
				) : false;
			}
		);
		Functions\when( 'map_meta_cap' )->alias(
			static function ( $cap ) {
				return in_array( $cap, array( 'edit_categories', 'delete_categories' ), true ) ? array( 'manage_categories' ) : ( 'customize' === $cap ? array( 'edit_theme_options' ) : array( $cap ) );
			}
		);
		$map = new HookMap();

		$this->assertSame( array( 'manage_categories' ), $map->term_created( 3, 3, 'category' ) );
		$this->assertSame( array( 'manage_categories' ), $map->term_edited( 3, 'category' ) );
		$this->assertSame( array( 'manage_categories' ), $map->term_deleted( 3, 'category' ) );
		$this->assertSame( array(), $map->term_edited( 3, 'nav_menu' ) );
		$this->assertSame( array( 'edit_theme_options' ), $map->customized() );
	}

	public function test_the_default_map_covers_the_listed_hooks(): void {
		$map = ( new HookMap() )->defaults();

		foreach ( array( 'transition_post_status', 'save_post', 'before_delete_post', 'activated_plugin', 'upgrader_process_complete', 'switch_theme', 'user_register', 'profile_update', 'delete_user', 'set_user_role', 'allowed_options', 'edit_terms', 'transition_comment_status', 'add_attachment', 'customize_save_after', 'export_wp', 'import_start', 'wp_update_nav_menu' ) as $hook ) {
			$this->assertArrayHasKey( $hook, $map, $hook );
		}
		$this->assertSame( 'activate_plugins', $map['deactivated_plugin'] );
		$this->assertSame( 'moderate_comments', $map['transition_comment_status'] );
	}
}
