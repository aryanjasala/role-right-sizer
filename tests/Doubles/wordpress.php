<?php
/**
 * Stand-ins for the WordPress user and role classes Role Right-Sizer works with.
 * The shared stubs in tests/Stubs come from the repo template; these are this plugin's own.
 *
 * @package AryanX\Axle\RoleRightSizer
 */

// phpcs:ignoreFile

if ( ! class_exists( 'WP_User' ) ) {
	/**
	 * A user with roles that can change.
	 */
	class WP_User {
		/** @var int */
		public $ID = 0;
		/** @var string[] */
		public $roles = array();
		/** @var string */
		public $user_login = '';
		/** @var string */
		public $display_name = '';
		/** @var string */
		public $user_registered = '';
		/** @var array[] Every role change, in order. */
		public $calls = array();

		public function __construct( $id = 0, array $fields = array() ) {
			$this->ID = (int) $id;
			foreach ( $fields as $key => $value ) {
				$this->$key = $value;
			}
		}

		public function set_role( $role ) {
			$this->calls[] = array( 'set_role', $role );
			$this->roles   = '' === $role ? array() : array( $role );
		}

		public function add_role( $role ) {
			$this->calls[] = array( 'add_role', $role );
			$this->roles[] = $role;
		}
	}
}

if ( ! class_exists( 'WP_Role' ) ) {
	/**
	 * A role whose capabilities can change.
	 */
	class WP_Role {
		/** @var string */
		public $name;
		/** @var array<string, bool> */
		public $capabilities;

		public function __construct( $name, array $capabilities ) {
			$this->name         = $name;
			$this->capabilities = $capabilities;
		}

		public function add_cap( $cap, $grant = true ) {
			$this->capabilities[ $cap ] = $grant;
		}

		public function remove_cap( $cap ) {
			unset( $this->capabilities[ $cap ] );
		}

		public function has_cap( $cap ) {
			return ! empty( $this->capabilities[ $cap ] );
		}
	}
}

if ( ! class_exists( 'WP_User_Query' ) ) {
	/**
	 * Pages through a fixed list of users.
	 */
	class WP_User_Query {
		/** @var WP_User[] Users every query pages through. */
		public static $users = array();
		/** @var array[] Arguments of every query. */
		public static $made = array();
		/** @var WP_User[] */
		private $results;

		public function __construct( array $args ) {
			self::$made[]  = $args;
			$roles         = (array) ( $args['role__in'] ?? array() );
			$not           = (array) ( $args['role__not_in'] ?? array() );
			$matching      = array_values(
				array_filter(
					self::$users,
					static function ( $user ) use ( $roles, $not ) {
						return ( ! $roles || array_intersect( $user->roles, $roles ) ) && ! array_intersect( $user->roles, $not );
					}
				)
			);
			$this->results = array_slice( $matching, ( ( $args['paged'] ?? 1 ) - 1 ) * $args['number'], $args['number'] );
			$this->total   = count( $matching );
		}

		/** @var int */
		public $total = 0;

		public function get_results() {
			return $this->results;
		}

		public function get_total() {
			return $this->total;
		}
	}
}

if ( ! class_exists( 'WP_Post_Type' ) ) {
	/**
	 * A post type with its capability names.
	 */
	class WP_Post_Type {
		/** @var object */
		public $cap;

		public function __construct( $prefix = 'post' ) {
			$plural = $prefix . 's';
			$this->cap = (object) array(
				'edit_posts'             => 'edit_' . $plural,
				'edit_others_posts'      => 'edit_others_' . $plural,
				'edit_published_posts'   => 'edit_published_' . $plural,
				'edit_private_posts'     => 'edit_private_' . $plural,
				'publish_posts'          => 'publish_' . $plural,
				'delete_posts'           => 'delete_' . $plural,
				'delete_others_posts'    => 'delete_others_' . $plural,
				'delete_published_posts' => 'delete_published_' . $plural,
				'delete_private_posts'   => 'delete_private_' . $plural,
			);
		}
	}
}

if ( ! class_exists( 'WP_Screen' ) ) {
	/**
	 * An admin screen.
	 */
	class WP_Screen {
		/** @var string */
		public $id = '';
	}
}

if ( ! class_exists( 'WP_HTTP_Response' ) ) {
	/**
	 * A response with a status.
	 */
	class WP_HTTP_Response {
		/** @var int */
		private $status;

		public function __construct( $data = null, $status = 200 ) {
			$this->status = $status;
		}

		public function get_status() {
			return $this->status;
		}
	}
}

/**
 * A REST request with a method. The shared stub has parameters only.
 */
class Role_Right_Sizer_Test_Request extends WP_REST_Request {
	/** @var string */
	private $method;

	public function __construct( $method = 'POST' ) {
		parent::__construct( array() );
		$this->method = $method;
	}

	public function get_method() {
		return $this->method;
	}
}

/**
 * A post with the fields the recorder reads. The shared stub declares only ID and content.
 */
class Role_Right_Sizer_Test_Post extends WP_Post {
	/** @var string */
	public $post_type = 'post';
	/** @var int */
	public $post_author = 0;
	/** @var string */
	public $post_status = 'draft';
}
