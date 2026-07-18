<?php
/**
 * Plugin orchestrator.
 *
 * @package WooStockSync
 */

defined( 'ABSPATH' ) || exit;

/**
 * Singleton that wires the plugin's components together on load.
 */
class WSS_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var WSS_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Settings component.
	 *
	 * @var WSS_Settings|null
	 */
	private $settings = null;

	/**
	 * Admin component.
	 *
	 * @var WSS_Admin|null
	 */
	private $admin = null;

	/**
	 * Runner component.
	 *
	 * @var WSS_Runner|null
	 */
	private $runner = null;

	/**
	 * Get (and lazily build) the singleton instance.
	 *
	 * @return WSS_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Wire the plugin's components.
	 */
	private function __construct() {
		// Apply pending schema migrations on load in admin and CLI contexts only.
		if ( is_admin() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			WSS_Install::maybe_upgrade();
		}

		// The runner registers Action Scheduler callbacks, which fire outside admin too.
		$this->runner = new WSS_Runner();

		if ( is_admin() ) {
			$this->settings = new WSS_Settings();
			$this->admin    = new WSS_Admin( $this->settings, $this->runner );
		}
	}

	/**
	 * Get the settings component.
	 *
	 * @return WSS_Settings|null
	 */
	public function settings() {
		return $this->settings;
	}

	/**
	 * Get the runner component.
	 *
	 * @return WSS_Runner|null
	 */
	public function runner() {
		return $this->runner;
	}
}
