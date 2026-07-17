<?php
/**
 * Admin menu, run screens, admin-post and ajax handlers, product lock checkbox.
 *
 * @package WooStockSync
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the Stock Sync admin surface and handles its requests.
 */
class WSS_Admin {

	/**
	 * Admin page slug (submenu under WooCommerce).
	 */
	const PAGE = 'wss-stock-sync';

	/**
	 * Settings component.
	 *
	 * @var WSS_Settings
	 */
	private $settings;

	/**
	 * Constructor: wire admin hooks.
	 *
	 * @param WSS_Settings $settings Settings component.
	 */
	public function __construct( WSS_Settings $settings ) {
		$this->settings = $settings;

		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_notices', array( $this, 'render_notices' ) );
	}

	/**
	 * Register the Stock Sync submenu under WooCommerce.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Stock Sync', 'woo-stock-sync' ),
			__( 'Stock Sync', 'woo-stock-sync' ),
			'manage_woocommerce',
			self::PAGE,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue the admin stylesheet and script on the plugin's screens only.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( 'woocommerce_page_' . self::PAGE !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style( 'wss-admin', WSS_URL . 'assets/css/admin.css', array(), WSS_VERSION );
		wp_enqueue_script( 'wss-admin', WSS_URL . 'assets/js/admin.js', array( 'jquery' ), WSS_VERSION, true );
	}

	/**
	 * Dispatch the plugin page to the correct screen.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'woo-stock-sync' ) );
		}

		$run_id = isset( $_GET['run'] ) ? absint( wp_unslash( $_GET['run'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen routing.
		$tab    = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'runs'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen routing.

		if ( $run_id > 0 ) {
			$this->render_run_detail( $run_id );
			return;
		}

		if ( 'settings' === $tab ) {
			$this->settings->render();
			return;
		}

		$this->render_runs_screen();
	}

	/**
	 * Render the top nav tabs shared by the plugin screens.
	 *
	 * @param string $active Active tab key (runs|settings).
	 * @return void
	 */
	public static function render_tabs( $active ) {
		$tabs = array(
			'runs'     => __( 'Runs', 'woo-stock-sync' ),
			'settings' => __( 'Settings', 'woo-stock-sync' ),
		);

		echo '<nav class="nav-tab-wrapper wss-tabs">';
		foreach ( $tabs as $key => $label ) {
			$url = ( 'runs' === $key ) ? self::page_url() : self::page_url( array( 'tab' => 'settings' ) );
			printf(
				'<a href="%s" class="nav-tab%s">%s</a>',
				esc_url( $url ),
				( $key === $active ) ? ' nav-tab-active' : '',
				esc_html( $label )
			);
		}
		echo '</nav>';
	}

	/**
	 * Placeholder runs list screen (filled in a later commit).
	 *
	 * @return void
	 */
	private function render_runs_screen() {
		echo '<div class="wrap wss-wrap">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Stock Sync', 'woo-stock-sync' ) . '</h1>';
		self::render_tabs( 'runs' );
		echo '</div>';
	}

	/**
	 * Placeholder run detail screen (filled in a later commit).
	 *
	 * @param int $run_id Run ID.
	 * @return void
	 */
	private function render_run_detail( $run_id ) {
		echo '<div class="wrap wss-wrap">';
		printf(
			'<h1 class="wp-heading-inline">%s</h1>',
			esc_html( sprintf( /* translators: %d: run ID. */ __( 'Sync Run #%d', 'woo-stock-sync' ), $run_id ) )
		);
		echo '</div>';
	}

	/**
	 * Build a URL to a plugin screen.
	 *
	 * @param array $args Extra query args to merge.
	 * @return string
	 */
	public static function page_url( array $args = array() ) {
		$args = array_merge( array( 'page' => self::PAGE ), $args );

		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * Render admin notices produced by the PRG redirect pattern.
	 *
	 * @return void
	 */
	public function render_notices() {
		if ( ! isset( $_GET['wss_notice'] ) || ! current_user_can( 'manage_woocommerce' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice display.
			return;
		}

		$key  = sanitize_key( wp_unslash( $_GET['wss_notice'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice display.
		$type = isset( $_GET['wss_notice_type'] ) ? sanitize_key( wp_unslash( $_GET['wss_notice_type'] ) ) : 'success'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice display.

		$messages = self::notice_messages();
		if ( ! isset( $messages[ $key ] ) ) {
			return;
		}

		$classes = array(
			'success' => 'notice-success',
			'warning' => 'notice-warning',
			'error'   => 'notice-error',
		);
		$class   = isset( $classes[ $type ] ) ? $classes[ $type ] : 'notice-info';

		printf(
			'<div class="notice %s is-dismissible"><p>%s</p></div>',
			esc_attr( $class ),
			esc_html( $messages[ $key ] )
		);
	}

	/**
	 * Map notice keys to human-readable messages.
	 *
	 * @return array
	 */
	private static function notice_messages() {
		return array(
			'settings_saved'   => __( 'Settings saved.', 'woo-stock-sync' ),
			'invalid_settings' => __( 'Settings could not be saved. Please correct the highlighted fields.', 'woo-stock-sync' ),
		);
	}
}
