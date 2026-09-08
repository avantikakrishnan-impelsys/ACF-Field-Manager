<?php
/**
 * Plugin Name: ACF Field Manager
 * Description: Browse Page -> ACF Field Group -> Field -> Slot, see current values live, and replace them — no pre-configuration needed. Reads your ACF structure fresh every time.
 * Version: 2.5.2
 * Author: Custom Build
 * Requires PHP: 7.4
 *
 * REQUIRES: Advanced Custom Fields (or ACF Pro) active, since this plugin
 * reads/writes ACF fields via ACF's own API (get_field_object, update_field).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

define( 'BSM_VERSION', '2.5.2' );
define( 'BSM_PATH', plugin_dir_path( __FILE__ ) );
define( 'BSM_URL', plugin_dir_url( __FILE__ ) );

// Bail early with an admin notice if ACF isn't active — this plugin is useless without it.
add_action( 'admin_init', function () {
	if ( ! function_exists( 'get_field' ) || ! function_exists( 'update_field' ) || ! function_exists( 'get_field_object' ) ) {
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-error"><p><strong>ACF Field Manager</strong> requires Advanced Custom Fields (ACF or ACF Pro) to be installed and active.</p></div>';
		} );
	}
} );

require_once BSM_PATH . 'includes/class-bsm-dashboard.php';
require_once BSM_PATH . 'includes/class-bsm-ajax.php';

/**
 * Boot the plugin.
 */
final class Blog_Slot_Manager {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		BSM_Dashboard::init();
		BSM_Ajax::init();
	}

	public function register_menu() {
		add_menu_page(
			'ACF Field Manager',
			'ACF Field Manager',
			'edit_pages',
			'bsm-dashboard',
			array( 'BSM_Dashboard', 'render' ),
			'dashicons-images-alt2',
			25
		);
	}

	public function enqueue_assets( $hook ) {
		if ( strpos( $hook, 'bsm-dashboard' ) === false ) {
			return;
		}

		// Select2 powers the searchable pickers (page picker, blog-post picker).
		wp_enqueue_style( 'bsm-select2', 'https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css', array(), '4.0.13' );
		wp_enqueue_script( 'bsm-select2', 'https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js', array( 'jquery' ), '4.0.13', true );

		// WP's built-in media library, so image fields can be replaced manually too, not just via blog auto-fill.
		wp_enqueue_media();

		wp_enqueue_style( 'bsm-admin', BSM_URL . 'assets/css/admin.css', array(), BSM_VERSION );
		wp_enqueue_script( 'bsm-admin', BSM_URL . 'assets/js/admin.js', array( 'jquery', 'bsm-select2' ), BSM_VERSION, true );

		wp_localize_script( 'bsm-admin', 'BSM', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'bsm_nonce' ),
		) );
	}
}

add_action( 'plugins_loaded', array( 'Blog_Slot_Manager', 'instance' ) );
