<?php
/**
 * Plugin bootstrap.
 *
 * @package AryanX\Axle\RoleRightSizer
 */

namespace AryanX\Axle\RoleRightSizer;

use AryanX\Axle\RoleRightSizer\Admin\NetworkScreen;
use AryanX\Axle\RoleRightSizer\Admin\Screen;
use AryanX\Axle\RoleRightSizer\Cli\Command;
use AryanX\Axle\RoleRightSizer\Cli\TrialCommand;
use AryanX\Axle\RoleRightSizer\Health\SiteHealth;
use AryanX\Axle\RoleRightSizer\Trial\Watcher;
use AryanX\Axle\RoleRightSizer\Usage\AdminScreens;
use AryanX\Axle\RoleRightSizer\Usage\HookMap;
use AryanX\Axle\RoleRightSizer\Usage\Recorder;
use AryanX\Axle\RoleRightSizer\Usage\RestWatcher;
use AryanX\Axle\RoleRightSizer\Usage\Store;

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
	 * When each role gained each capability.
	 *
	 * @var CapabilityHistory
	 */
	private CapabilityHistory $history;

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
	 * Starts tracking on activation, and notes the capabilities every role has
	 * now, so the ones plugins add later count as new.
	 */
	public static function activate(): void {
		$settings = new Settings();
		$settings->tracking_started();
		( new CapabilityHistory( $settings ) )->current();
	}

	/**
	 * Stops the background jobs on deactivation. Recorded usage stays.
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( Report::CRON );
		wp_clear_scheduled_hook( Report::DAILY );
		wp_clear_scheduled_hook( Trials::CRON );
	}

	/**
	 * Builds the shared objects.
	 */
	private function __construct() {
		$this->settings    = new Settings();
		$this->store       = new Store();
		$this->recorder    = new Recorder( $this->store, $this->settings, new HookMap() );
		$this->history     = new CapabilityHistory( $this->settings );
		$this->recommender = new Recommender( $this->settings, $this->store );
		$this->report      = new Report( $this->settings, $this->recommender, $this->store, $this->history );
		$this->changes     = new Changes();
		$this->applier     = new Applier( $this->settings, $this->recommender, $this->report, $this->changes );
		$this->trials      = new Trials( $this->settings, $this->applier, $this->recommender, $this->report );
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
	 * When each role gained each capability.
	 *
	 * @return CapabilityHistory
	 */
	public function history(): CapabilityHistory {
		return $this->history;
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
	 * Trials of a smaller role.
	 *
	 * @return Trials
	 */
	public function trials(): Trials {
		return $this->trials;
	}

	/**
	 * Registers the services.
	 */
	private function register(): void {
		$this->settings->tracking_started();

		$services = array(
			$this->recorder,
			$this->history,
			new AdminScreens( $this->recorder ),
			new RestWatcher( $this->recorder ),
			$this->report,
			$this->trials,
			new Watcher( $this->trials ),
			new Screen( $this->settings, $this->store, $this->recommender, $this->report, $this->changes, $this->applier, $this->trials ),
			new NetworkScreen( $this->store ),
			new SiteHealth( $this->settings, $this->report, $this->trials ),
			new Privacy( $this->store, $this->changes, $this->trials ),
			new Abilities( $this->settings, $this->store, $this->recommender, $this->report, $this->changes, $this->applier, $this->trials ),
		);

		foreach ( $services as $service ) {
			$service->register();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'role-right-sizer', new Command( $this->settings, $this->store, $this->recommender, $this->report, $this->changes, $this->applier ) );
			\WP_CLI::add_command( 'role-right-sizer trial', new TrialCommand( $this->trials ) );
		}
	}
}
