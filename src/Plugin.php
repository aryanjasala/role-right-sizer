<?php
/**
 * Plugin bootstrap.
 *
 * @package AryanX\RoleRightSizer
 */

namespace AryanX\RoleRightSizer;

use AryanX\RoleRightSizer\Admin\NetworkScreen;
use AryanX\RoleRightSizer\Admin\Screen;
use AryanX\RoleRightSizer\Cli\Command;
use AryanX\RoleRightSizer\Health\SiteHealth;
use AryanX\RoleRightSizer\Usage\AdminScreens;
use AryanX\RoleRightSizer\Usage\HookMap;
use AryanX\RoleRightSizer\Usage\Recorder;
use AryanX\RoleRightSizer\Usage\RestWatcher;
use AryanX\RoleRightSizer\Usage\Store;

defined( 'ABSPATH' ) || exit;

/**
 * Creates the shared objects once and registers their hooks.
 */
final class Plugin {

	/**
	 * The running instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

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
	 * Records capability use.
	 *
	 * @var Recorder
	 */
	private Recorder $recorder;

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
	 * Starts the plugin. Runs on `plugins_loaded`.
	 */
	public static function boot(): void {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->register();
		}
	}

	/**
	 * The running instance.
	 *
	 * @return Plugin|null
	 */
	public static function instance(): ?Plugin {
		return self::$instance;
	}

	/**
	 * Starts tracking on activation.
	 */
	public static function activate(): void {
		( new Settings() )->tracking_started();
	}

	/**
	 * Stops the background jobs on deactivation. Recorded usage stays.
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( Report::CRON );
		wp_clear_scheduled_hook( Report::DAILY );
	}

	/**
	 * Builds the shared objects.
	 */
	private function __construct() {
		$this->settings    = new Settings();
		$this->store       = new Store();
		$this->recorder    = new Recorder( $this->store, $this->settings, new HookMap() );
		$this->recommender = new Recommender( $this->settings, $this->store );
		$this->report      = new Report( $this->settings, $this->recommender, $this->store );
		$this->changes     = new Changes();
		$this->applier     = new Applier( $this->settings, $this->recommender, $this->report, $this->changes );
	}

	/**
	 * Settings and limits.
	 *
	 * @return Settings
	 */
	public function settings(): Settings {
		return $this->settings;
	}

	/**
	 * Recorded usage.
	 *
	 * @return Store
	 */
	public function store(): Store {
		return $this->store;
	}

	/**
	 * Records capability use, for other plugins that want to report their own.
	 *
	 * @return Recorder
	 */
	public function recorder(): Recorder {
		return $this->recorder;
	}

	/**
	 * Works out recommendations.
	 *
	 * @return Recommender
	 */
	public function recommender(): Recommender {
		return $this->recommender;
	}

	/**
	 * Saved recommendations.
	 *
	 * @return Report
	 */
	public function report(): Report {
		return $this->report;
	}

	/**
	 * Change log.
	 *
	 * @return Changes
	 */
	public function changes(): Changes {
		return $this->changes;
	}

	/**
	 * Applies and undoes changes.
	 *
	 * @return Applier
	 */
	public function applier(): Applier {
		return $this->applier;
	}

	/**
	 * Registers the services.
	 */
	private function register(): void {
		$this->settings->tracking_started();

		$services = array(
			$this->recorder,
			new AdminScreens( $this->recorder ),
			new RestWatcher( $this->recorder ),
			$this->report,
			new Screen( $this->settings, $this->store, $this->recommender, $this->report, $this->changes, $this->applier ),
			new NetworkScreen( $this->store ),
			new SiteHealth( $this->settings, $this->report ),
			new Privacy( $this->store, $this->changes ),
			new Abilities( $this->settings, $this->store, $this->recommender, $this->report, $this->changes, $this->applier ),
		);

		foreach ( $services as $service ) {
			$service->register();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'role-right-sizer', new Command( $this->settings, $this->store, $this->recommender, $this->report, $this->changes, $this->applier ) );
		}
	}
}
