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
	 * Runner component.
	 *
	 * @var WSS_Runner
	 */
	private $runner;

	/**
	 * Constructor: wire admin hooks.
	 *
	 * @param WSS_Settings $settings Settings component.
	 * @param WSS_Runner   $runner   Runner component.
	 */
	public function __construct( WSS_Settings $settings, WSS_Runner $runner ) {
		$this->settings = $settings;
		$this->runner   = $runner;

		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_notices', array( $this, 'render_notices' ) );
		add_action( 'wp_ajax_wss_feed_columns', array( $this, 'ajax_feed_columns' ) );
		add_action( 'admin_post_wss_start_fetch', array( $this, 'handle_start_fetch' ) );
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
		wp_enqueue_script( 'wss-admin', WSS_URL . 'assets/js/admin.js', array(), WSS_VERSION, true );

		wp_localize_script(
			'wss-admin',
			'wssAdmin',
			array(
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'columnsNonce' => wp_create_nonce( 'wss_feed_columns' ),
				'i18n'         => array(
					'loading' => __( 'Loading columns…', 'woo-stock-sync' ),
					'loaded'  => __( 'Columns loaded.', 'woo-stock-sync' ),
					'error'   => __( 'Could not load columns. Check the feed source in Settings.', 'woo-stock-sync' ),
				),
			)
		);
	}

	/**
	 * Ajax: return the feed's column names for the current (saved or submitted) source.
	 *
	 * @return void
	 */
	public function ajax_feed_columns() {
		check_ajax_referer( 'wss_feed_columns', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error(
				array(
					'code'    => 'forbidden',
					'message' => __( 'You do not have permission to do this.', 'woo-stock-sync' ),
				),
				403
			);
		}

		$settings = wss_get_settings();
		$post     = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each field sanitized individually below.

		if ( isset( $post['source_type'] ) ) {
			$settings['source_type'] = ( 'url' === $post['source_type'] ) ? 'url' : 'upload';
		}
		if ( isset( $post['feed_url'] ) ) {
			$settings['feed_url'] = esc_url_raw( trim( $post['feed_url'] ) );
		}
		if ( isset( $post['auth_header_name'] ) ) {
			$settings['auth_header_name'] = sanitize_text_field( $post['auth_header_name'] );
		}
		if ( isset( $post['auth_header_value'] ) && '' !== trim( $post['auth_header_value'] ) ) {
			$settings['auth_header_value'] = sanitize_text_field( $post['auth_header_value'] );
		}

		$feed    = new WSS_Feed();
		$columns = $feed->list_columns( $settings );

		if ( is_wp_error( $columns ) ) {
			wp_send_json_error(
				array(
					'code'    => $columns->get_error_code(),
					'message' => $columns->get_error_message(),
				)
			);
		}

		wp_send_json_success( array( 'columns' => array_values( $columns ) ) );
	}

	/**
	 * Admin-post: create a run and queue its fetch, then redirect to the new run.
	 *
	 * @return void
	 */
	public function handle_start_fetch() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'woo-stock-sync' ) );
		}

		check_admin_referer( 'wss_start_fetch', 'wss_start_fetch_nonce' );

		$run_id = $this->runner->begin_run( 'manual', get_current_user_id() );

		if ( is_wp_error( $run_id ) ) {
			$this->redirect_notice( $run_id->get_error_code(), 'error' );
		}

		wp_safe_redirect(
			self::page_url(
				array(
					'run'             => (int) $run_id,
					'wss_notice'      => 'run_started',
					'wss_notice_type' => 'success',
				)
			)
		);
		exit;
	}

	/**
	 * Redirect to a plugin screen with a notice.
	 *
	 * @param string $key  Notice key.
	 * @param string $type Notice type (success|warning|error).
	 * @param array  $args Extra query args.
	 * @return void
	 */
	private function redirect_notice( $key, $type = 'success', array $args = array() ) {
		$args = array_merge(
			$args,
			array(
				'wss_notice'      => $key,
				'wss_notice_type' => $type,
			)
		);

		wp_safe_redirect( self::page_url( $args ) );
		exit;
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
		$this->render_fetch_button();
		echo '<hr class="wp-header-end" />';
		self::render_tabs( 'runs' );

		require_once WSS_PATH . 'includes/class-wss-runs-table.php';
		$table = new WSS_Runs_Table();
		$table->prepare_items();

		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '" />';
		$table->display();
		echo '</form>';

		echo '</div>';
	}

	/**
	 * Render the "Fetch and preview" primary action form.
	 *
	 * @return void
	 */
	private function render_fetch_button() {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="wss-fetch-form">';
		echo '<input type="hidden" name="action" value="wss_start_fetch" />';
		wp_nonce_field( 'wss_start_fetch', 'wss_start_fetch_nonce' );
		echo '<button type="submit" class="page-title-action">' . esc_html__( 'Fetch and preview', 'woo-stock-sync' ) . '</button>';
		echo '</form>';
	}

	/**
	 * Placeholder run detail screen (filled in a later commit).
	 *
	 * @param int $run_id Run ID.
	 * @return void
	 */
	private function render_run_detail( $run_id ) {
		$run = wss_get_run( $run_id );

		if ( ! $run ) {
			echo '<div class="wrap wss-wrap">';
			echo '<h1 class="wp-heading-inline">' . esc_html__( 'Sync Run', 'woo-stock-sync' ) . '</h1>';
			echo '<div class="notice notice-error"><p>' . esc_html__( 'That sync run could not be found.', 'woo-stock-sync' ) . '</p></div>';
			echo '</div>';
			return;
		}

		$allowed       = array( 'planned', 'no_change', 'skipped', 'failed', 'applied' );
		$status_filter = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter.
		if ( ! in_array( $status_filter, $allowed, true ) ) {
			$status_filter = '';
		}

		require_once WSS_PATH . 'includes/class-wss-rows-table.php';
		$table = new WSS_Rows_Table( $run_id, $status_filter );
		$table->prepare_items();

		require WSS_PATH . 'templates/admin/run-detail.php';
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
			'invalid_settings' => __( 'Configure the feed source and column mapping in Settings first.', 'woo-stock-sync' ),
			'run_started'      => __( 'Sync started. The preview will appear here shortly.', 'woo-stock-sync' ),
			'lock_held'        => __( 'A sync is already running. Wait for it to finish before starting another.', 'woo-stock-sync' ),
			'db_error'         => __( 'The sync could not be started. Please try again.', 'woo-stock-sync' ),
		);
	}
}
