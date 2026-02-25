<?php
/**
 * Plugin Name:       소셜 커넥트 테이블
 * Plugin URI:        https://socialconnect.co.kr/
 * Description:       웹페이지에서 표 안에 뭔가를 정리해서 보여주기를 너무 좋아하는 한국형 웹사이트 제작을 도와주는 워드프레스 테이블 플러그인입니다.
 * Version:           1.0.5
 * Requires at least: 6.2
 * Requires PHP:      8.0
 * Author:            Social Bridge Dev. Team
 * Author URI:        https://socialbridge.co.kr/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       advanced-wp-tables
 * Domain Path:       /languages
 */

declare(strict_types=1);

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Plugin constants.
 */
define( 'ADVT_VERSION', '1.0.5' );
define( 'ADVT_PLUGIN_FILE', __FILE__ );
define( 'ADVT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ADVT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'ADVT_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'ADVT_DB_VERSION', '1.0.0' );

/**
 * Composer autoloader (PhpSpreadsheet, etc.).
 */
if ( file_exists( ADVT_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
    require_once ADVT_PLUGIN_DIR . 'vendor/autoload.php';
}

/**
 * Autoloader for plugin classes.
 *
 * Maps class names like Advt_Admin_Controller to includes/class-advt-admin-controller.php
 */
spl_autoload_register( function ( string $class_name ): void {
    // Only autoload classes with our prefix.
    if ( strpos( $class_name, 'Advt_' ) !== 0 ) {
        return;
    }

    $file_name = 'class-' . strtolower( str_replace( '_', '-', $class_name ) ) . '.php';
    $file_path = ADVT_PLUGIN_DIR . 'includes/' . $file_name;

    if ( file_exists( $file_path ) ) {
        require_once $file_path;
    }
} );

/**
 * Activation hook — create custom database tables and set options.
 */
function advt_activate(): void {
    $installer = new Advt_Installer();
    $installer->activate();
}
register_activation_hook( __FILE__, 'advt_activate' );

/**
 * Deactivation hook — cleanup transients, scheduled events, etc.
 */
function advt_deactivate(): void {
    $installer = new Advt_Installer();
    $installer->deactivate();
}
register_deactivation_hook( __FILE__, 'advt_deactivate' );

/**
 * Boot the plugin after all plugins have loaded.
 */
function advt_init(): void {
    // Load text domain for translations.
    load_plugin_textdomain( 'advanced-wp-tables', false, dirname( ADVT_PLUGIN_BASENAME ) . '/languages' );

    // Initialize the main plugin controller.
    $plugin = Advt_Plugin::get_instance();
    $plugin->run();
}
add_action( 'plugins_loaded', 'advt_init' );
