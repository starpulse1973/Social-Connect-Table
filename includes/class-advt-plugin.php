<?php
/**
 * Main plugin controller — singleton that bootstraps admin and public-facing features.
 *
 * @package AdvancedWPTables
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Advt_Plugin {

    /**
     * Singleton instance.
     */
    private static ?self $instance = null;

    /**
     * Get the singleton instance.
     */
    public static function get_instance(): self {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor to enforce singleton.
     */
    private function __construct() {}

    /**
     * Boot all sub-modules.
     */
    public function run(): void {
        // Check for DB upgrades.
        $this->maybe_upgrade_db();

        // REST API — registered on rest_api_init so it fires in all contexts
        // (admin, frontend, REST, AJAX).
        $rest_api = new Advt_Rest_Api();
        $rest_api->register_hooks();

        // Admin-only hooks.
        if ( is_admin() ) {
            $admin = new Advt_Admin();
            $admin->register_hooks();

            $import_export = new Advt_Import_Export();
            $import_export->register_hooks();
        }

        // Public (frontend) hooks — always loaded so shortcodes work in REST/AJAX contexts too.
        $frontend = new Advt_Frontend();
        $frontend->register_hooks();
    }

    /**
     * Run database migrations when the stored DB version is older than the current one.
     */
    private function maybe_upgrade_db(): void {
        $installed_version = get_option( 'advt_db_version', '0' );

        if ( version_compare( $installed_version, ADVT_DB_VERSION, '<' ) ) {
            $installer = new Advt_Installer();
            $installer->activate();
        }
    }
}
