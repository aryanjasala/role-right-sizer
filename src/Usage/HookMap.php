<?php
/**
 * Which WordPress events prove which capability was used.
 *
 * @package AryanX\Axle\RoleRightSizer
 */

namespace AryanX\Axle\RoleRightSizer\Usage;

use AryanX\Axle\RoleRightSizer\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The default map of hooks to capabilities, and the resolvers that work out
 * the exact capability from a hook's arguments.
 *
 * Each resolver gets the hook's arguments and returns the primitive
 * capabilities the current user needed for it, mirroring how core's
 * `map_meta_cap()` decides. Whether the user really has them is checked later.
 */
final class HookMap {

	/**
	 * Post status before the save in progress, by post ID.
	 *
	 * @var array<int, string>
	 */
	private array $before = array();

	/**
	 * The default map: hook name => capability, list of capabilities, or a resolver.
	 *
	 * @return array<string, string|string[]|callable>
	 */
	public function defaults(): array {
		return array(
			'transition_post_status'    => array( $this, 'post_status' ),
			'save_post'                 => array( $this, 'post_saved' ),
			'wp_trash_post'             => array( $this, 'post_deleted' ),
			'before_delete_post'        => array( $this, 'post_deleted' ),
			'add_attachment'            => 'upload_files',
			'activated_plugin'          => 'activate_plugins',
			'deactivated_plugin'        => 'activate_plugins',
			'upgrader_process_complete' => array( $this, 'upgraded' ),
			'switch_theme'              => 'switch_themes',
			'user_register'             => array( $this, 'user_created' ),
			'profile_update'            => array( $this, 'user_edited' ),
			'delete_user'               => array( $this, 'user_deleted' ),
			'remove_user_from_blog'     => array( $this, 'user_removed' ),
			'set_user_role'             => array( $this, 'role_changed' ),
			'allowed_options'           => array( $this, 'options_saved' ),
			'created_term'              => array( $this, 'term_created' ),
			'edit_terms'                => array( $this, 'term_edited' ),
			'pre_delete_term'           => array( $this, 'term_deleted' ),
			'transition_comment_status' => 'moderate_comments',
			'customize_save_after'      => array( $this, 'customized' ),
			'export_wp'                 => 'export',
			'import_start'              => 'import',
			'wp_update_nav_menu'        => 'edit_theme_options',
		);
	}

	/**
	 * Post types whose saves and deletes say nothing about a person's work.
	 *
	 * @return string[]
	 */
	public static function ignored_post_types(): array {
		/**
		 * Filters the post types whose saves and deletes are not recorded.
		 *
		 * @param string[] $post_types Default revisions, oEmbed caches, Customizer changesets, privacy requests and menu items (menu saves are recorded on their own).
		 */
		return Settings::strings( apply_filters( 'role_right_sizer_ignored_post_types', array( 'revision', 'oembed_cache', 'customize_changeset', 'user_request', 'nav_menu_item' ) ) );
	}

	/**
	 * `transition_post_status`: publishing or scheduling needs the type's publish capability.
	 *
	 * Also remembers the old status, which `save_post` needs and can no longer see.
	 *
	 * @param mixed $new_status New status.
	 * @param mixed $old_status Old status.
	 * @param mixed $post       The post.
	 * @return string[]
	 */
	public function post_status( $new_status = '', $old_status = '', $post = null ): array {
		$type = $this->type_of( $post );
		if ( ! $type ) {
			return array();
		}

		$this->before[ (int) $post->ID ] = (string) $old_status;

		$live = array( 'publish', 'future' );
		if ( in_array( $new_status, $live, true ) && ! in_array( $old_status, $live, true ) ) {
			return array( (string) $type->cap->publish_posts );
		}

		return array();
	}

	/**
	 * `save_post`: the edit capabilities the post needed before this save.
	 *
	 * @param mixed $post_id Post ID.
	 * @param mixed $post    The post.
	 * @param mixed $update  Whether an existing post was updated.
	 * @return string[]
	 */
	public function post_saved( $post_id = 0, $post = null, $update = false ): array {
		$type = $this->type_of( $post );
		if ( ! $type || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) {
			return array();
		}

		$status = $update ? ( $this->before[ (int) $post_id ] ?? (string) $post->post_status ) : 'new';
		unset( $this->before[ (int) $post_id ] );

		return self::edit_caps( $type->cap, (int) $post->post_author, get_current_user_id(), $status );
	}

	/**
	 * `wp_trash_post` and `before_delete_post`: the delete capabilities the post needed.
	 *
	 * @param mixed $post_id Post ID.
	 * @return string[]
	 */
	public function post_deleted( $post_id = 0 ): array {
		$post = get_post( (int) $post_id );
		$type = $this->type_of( $post );
		if ( ! $type ) {
			return array();
		}

		$status = (string) $post->post_status;
		if ( 'trash' === $status ) {
			$status = (string) get_post_meta( $post->ID, '_wp_trash_meta_status', true );
		}

		return self::delete_caps( $type->cap, (int) $post->post_author, get_current_user_id(), $status );
	}

	/**
	 * `upgrader_process_complete`: installing or updating plugins, themes, core or translations.
	 *
	 * @param mixed $upgrader   The upgrader.
	 * @param mixed $hook_extra Type and action.
	 * @return string[]
	 */
	public function upgraded( $upgrader = null, $hook_extra = array() ): array {
		$hook_extra = (array) $hook_extra;
		$action     = 'install' === ( $hook_extra['action'] ?? '' ) ? 'install' : 'update';

		switch ( $hook_extra['type'] ?? '' ) {
			case 'plugin':
				return array( $action . '_plugins' );
			case 'theme':
				return array( $action . '_themes' );
			case 'core':
				return array( 'update_core' );
			case 'translation':
				return array( 'install_languages' );
		}

		return array();
	}

	/**
	 * `user_register`: someone created an account for another person.
	 *
	 * @param mixed $user_id New user.
	 * @return string[]
	 */
	public function user_created( $user_id = 0 ): array {
		return $this->someone_else( $user_id ) ? array( 'create_users' ) : array();
	}

	/**
	 * `profile_update`: someone edited another person's account.
	 *
	 * @param mixed $user_id Edited user.
	 * @return string[]
	 */
	public function user_edited( $user_id = 0 ): array {
		return $this->someone_else( $user_id ) ? array( 'edit_users' ) : array();
	}

	/**
	 * `delete_user`: someone deleted another person's account.
	 *
	 * @param mixed $user_id Deleted user.
	 * @return string[]
	 */
	public function user_deleted( $user_id = 0 ): array {
		return $this->someone_else( $user_id ) ? array( 'delete_users' ) : array();
	}

	/**
	 * `remove_user_from_blog`: someone removed another person from a site.
	 *
	 * @param mixed $user_id Removed user.
	 * @return string[]
	 */
	public function user_removed( $user_id = 0 ): array {
		return $this->someone_else( $user_id ) ? array( 'remove_users' ) : array();
	}

	/**
	 * `set_user_role`: someone changed another person's role.
	 *
	 * A brand-new account gets its first role through the same hook, with no
	 * old roles. That is account creation, so it is left to `user_register`.
	 *
	 * @param mixed $user_id   User.
	 * @param mixed $role      New role.
	 * @param mixed $old_roles Roles before.
	 * @return string[]
	 */
	public function role_changed( $user_id = 0, $role = '', $old_roles = array() ): array {
		return $this->someone_else( $user_id ) && ! empty( $old_roles ) ? array( 'promote_users' ) : array();
	}

	/**
	 * `allowed_options` in wp-admin/options.php: a settings screen was saved.
	 *
	 * The filter runs on every visit to options.php, so this checks that the
	 * request is a save with a valid nonce, as options.php does next.
	 *
	 * @return string[]
	 */
	public function options_saved(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- The nonce is verified right here, before anything is recorded.
		if ( ! isset( $_POST['option_page'], $_POST['_wpnonce'], $_POST['action'] ) || 'update' !== $_POST['action'] ) {
			return array();
		}

		$page = sanitize_text_field( wp_unslash( $_POST['option_page'] ) );
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), $page . '-options' ) ) {
			return array();
		}
		// phpcs:enable

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's own filter, read the way options.php reads it.
		$cap = apply_filters( "option_page_capability_{$page}", 'manage_options' );

		return is_string( $cap ) && '' !== $cap ? array( $cap ) : array();
	}

	/**
	 * `created_term`: the taxonomy's edit capability.
	 *
	 * @param mixed $term_id  Term ID.
	 * @param mixed $tt_id    Term taxonomy ID.
	 * @param mixed $taxonomy Taxonomy.
	 * @return string[]
	 */
	public function term_created( $term_id = 0, $tt_id = 0, $taxonomy = '' ): array {
		return $this->term_caps( (string) $taxonomy, 'edit_terms' );
	}

	/**
	 * `edit_terms`: the taxonomy's edit capability.
	 *
	 * @param mixed $term_id  Term ID.
	 * @param mixed $taxonomy Taxonomy.
	 * @return string[]
	 */
	public function term_edited( $term_id = 0, $taxonomy = '' ): array {
		return $this->term_caps( (string) $taxonomy, 'edit_terms' );
	}

	/**
	 * `pre_delete_term`: the taxonomy's delete capability.
	 *
	 * @param mixed $term_id  Term ID.
	 * @param mixed $taxonomy Taxonomy.
	 * @return string[]
	 */
	public function term_deleted( $term_id = 0, $taxonomy = '' ): array {
		return $this->term_caps( (string) $taxonomy, 'delete_terms' );
	}

	/**
	 * `customize_save_after`: what the `customize` capability maps to.
	 *
	 * @return string[]
	 */
	public function customized(): array {
		return map_meta_cap( 'customize', get_current_user_id() );
	}

	/**
	 * The primitive capabilities to edit a post, as core's `map_meta_cap()` decides them.
	 *
	 * @param object $cap     Post type capabilities.
	 * @param int    $author  Post author.
	 * @param int    $user_id Acting user.
	 * @param string $status  Post status before the change, or `new`.
	 * @return string[]
	 */
	public static function edit_caps( $cap, int $author, int $user_id, string $status ): array {
		$live = in_array( $status, array( 'publish', 'future' ), true );

		if ( 'new' === $status || ( $author && $author === $user_id ) ) {
			return array( (string) ( $live ? $cap->edit_published_posts : $cap->edit_posts ) );
		}

		$caps = array( (string) $cap->edit_others_posts );
		if ( $live ) {
			$caps[] = (string) $cap->edit_published_posts;
		} elseif ( 'private' === $status ) {
			$caps[] = (string) $cap->edit_private_posts;
		}

		return $caps;
	}

	/**
	 * The primitive capabilities to delete a post, as core's `map_meta_cap()` decides them.
	 *
	 * @param object $cap     Post type capabilities.
	 * @param int    $author  Post author.
	 * @param int    $user_id Acting user.
	 * @param string $status  Post status, or the status it had before the trash.
	 * @return string[]
	 */
	public static function delete_caps( $cap, int $author, int $user_id, string $status ): array {
		$live = in_array( $status, array( 'publish', 'future' ), true );

		if ( $author && $author === $user_id ) {
			return array( (string) ( $live ? $cap->delete_published_posts : $cap->delete_posts ) );
		}

		$caps = array( (string) $cap->delete_others_posts );
		if ( $live ) {
			$caps[] = (string) $cap->delete_published_posts;
		} elseif ( 'private' === $status ) {
			$caps[] = (string) $cap->delete_private_posts;
		}

		return $caps;
	}

	/**
	 * The post type object of a post that counts, or null.
	 *
	 * @param mixed $post Post.
	 * @return \WP_Post_Type|null
	 */
	private function type_of( $post ): ?\WP_Post_Type {
		if ( ! $post instanceof \WP_Post || in_array( $post->post_type, self::ignored_post_types(), true ) || wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
			return null;
		}

		$type = get_post_type_object( $post->post_type );

		return $type instanceof \WP_Post_Type ? $type : null;
	}

	/**
	 * A taxonomy capability, mapped to primitives.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @param string $which    edit_terms or delete_terms.
	 * @return string[]
	 */
	private function term_caps( string $taxonomy, string $which ): array {
		$tax = get_taxonomy( $taxonomy );
		if ( ! $tax || 'nav_menu' === $taxonomy ) {
			return array();
		}

		return map_meta_cap( (string) $tax->cap->$which, get_current_user_id() );
	}

	/**
	 * Whether a logged-in user acted on someone else's account.
	 *
	 * @param mixed $user_id The account acted on.
	 * @return bool
	 */
	private function someone_else( $user_id ): bool {
		$current = get_current_user_id();

		return $current > 0 && (int) $user_id !== $current;
	}
}
