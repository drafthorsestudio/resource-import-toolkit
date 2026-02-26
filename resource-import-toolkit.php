<?php
/**
 * Plugin Name: ORN Resource Import Toolkit
 * Description: All-in-one toolkit for migrating resources from a legacy system: match consultants, import resource posts, attach files, and assign taxonomy terms &amp; audiences.
 * Version: 3.1.0
 * Author: KC Web Programmers
 * License: GPL v2 or later
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'RIT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'RIT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Load tool classes.
require_once RIT_PLUGIN_DIR . 'includes/class-rit-consultant-matcher.php';
require_once RIT_PLUGIN_DIR . 'includes/class-rit-resource-importer.php';
require_once RIT_PLUGIN_DIR . 'includes/class-rit-attachment-importer.php';
require_once RIT_PLUGIN_DIR . 'includes/class-rit-taxonomy-assigner.php';

class Resource_Import_Toolkit {

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'register_menus' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_styles' ) );

        // Form handlers.
        add_action( 'admin_post_rit_consultant_match', array( 'RIT_Consultant_Matcher', 'handle_form' ) );
        add_action( 'admin_post_rit_resource_import', array( 'RIT_Resource_Importer', 'handle_form' ) );

        // Attachment Importer uses AJAX batch processing.
        RIT_Attachment_Importer::register_ajax();

        // Taxonomy Assigner uses AJAX batch processing.
        RIT_Taxonomy_Assigner::register_ajax();
    }

    /**
     * Register top-level menu and submenu pages.
     */
    public function register_menus() {
        // Top-level menu — points to the first submenu.
        add_menu_page(
            'Resource Import Toolkit',
            'Resource Toolkit',
            'manage_options',
            'rit-consultant-matcher',
            array( 'RIT_Consultant_Matcher', 'render_page' ),
            'dashicons-database-import',
            80
        );

        // Submenu: Consultant Matcher (replaces the auto-created parent submenu).
        add_submenu_page(
            'rit-consultant-matcher',
            'Consultant Matcher',
            '1. Consultant Matcher',
            'manage_options',
            'rit-consultant-matcher',
            array( 'RIT_Consultant_Matcher', 'render_page' )
        );

        // Submenu: Resource Importer.
        add_submenu_page(
            'rit-consultant-matcher',
            'Resource Importer',
            '2. Resource Importer',
            'manage_options',
            'rit-resource-importer',
            array( 'RIT_Resource_Importer', 'render_page' )
        );

        // Submenu: Attachment Importer.
        add_submenu_page(
            'rit-consultant-matcher',
            'Attachment Importer',
            '3. Attachment Importer',
            'manage_options',
            'rit-attachment-importer',
            array( 'RIT_Attachment_Importer', 'render_page' )
        );

        // Submenu: Taxonomy Assigner.
        add_submenu_page(
            'rit-consultant-matcher',
            'Taxonomy Assigner',
            '4. Taxonomy Assigner',
            'manage_options',
            'rit-taxonomy-assigner',
            array( 'RIT_Taxonomy_Assigner', 'render_page' )
        );
    }

    /**
     * Shared admin styles for all toolkit pages.
     */
    public function enqueue_styles( $hook ) {
        // Match any of our submenu hooks.
        $our_pages = array(
            'toplevel_page_rit-consultant-matcher',
            'resource-toolkit_page_rit-resource-importer',
            'resource-toolkit_page_rit-attachment-importer',
            'resource-toolkit_page_rit-taxonomy-assigner',
        );
        if ( ! in_array( $hook, $our_pages, true ) ) {
            return;
        }

        wp_add_inline_style( 'wp-admin', '
            .rit-wrap { max-width: 780px; }
            .rit-wrap .card { padding: 20px; margin-top: 15px; }
            .rit-results { margin-top: 20px; }
            .rit-stats-table td { padding: 6px 12px; }
            .rit-stats-table td:first-child { font-weight: 600; }
            .rit-log { max-height: 300px; overflow-y: auto; background: #f6f6f6; padding: 10px; font-family: monospace; font-size: 12px; line-height: 1.6; margin-top: 10px; }
            .rit-log .error { color: #d63638; }
            .rit-log .skip { color: #996800; }
            .rit-log .ok { color: #00a32a; }
        ' );
    }

}

new Resource_Import_Toolkit();
