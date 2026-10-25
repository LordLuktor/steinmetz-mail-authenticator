<?php
/**
 * Plugin Name: Steinmetz Mail Authenticator
 * Description: Walks site owners through setting up SPF, DKIM, and DMARC for their domain so receipts and notifications land in the inbox instead of spam - with built-in DNS and authentication testing.
 * Version: 0.1.1
 * Author: Scott Steinmetz
 * Author URI: https://scottsteinmetz.biz
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: steinmetz-mail-authenticator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SMA_VERSION', '0.1.1' );
define( 'SMA_PLUGIN_FILE', __FILE__ );
define( 'SMA_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SMA_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SMA_OPTION_KEY', 'sma_settings' );

require_once SMA_PLUGIN_DIR . 'includes/class-sma-providers.php';
require_once SMA_PLUGIN_DIR . 'includes/class-sma-record-generator.php';
require_once SMA_PLUGIN_DIR . 'includes/class-sma-dns-checker.php';
require_once SMA_PLUGIN_DIR . 'includes/class-sma-auth-results.php';
require_once SMA_PLUGIN_DIR . 'includes/class-sma-admin.php';
require_once SMA_PLUGIN_DIR . 'includes/class-sma-ajax.php';

/**
 * Core bootstrap.
 */
final class SMA_Plugin {

	/**
	 * Wire up the admin-side hooks. This plugin has no front-end footprint.
	 */
	public static function init() {
		if ( ! is_admin() ) {
			return;
		}

		SMA_Admin::init();
		SMA_Ajax::init();
	}

	/**
	 * Seed default settings on activation.
	 */
	public static function activate() {
		if ( false === get_option( SMA_OPTION_KEY ) ) {
			add_option(
				SMA_OPTION_KEY,
				array(
					'domain'        => SMA_Record_Generator::get_site_domain(),
						'spf_include_custom' => '',
					'provider'      => '',
					'extra_records' => array(),
					'report_email'  => get_option( 'admin_email' ),
					'dmarc_policy'  => 'none',
					'spf_qualifier' => '~all',
				)
			);
		}
	}
}

register_activation_hook( __FILE__, array( 'SMA_Plugin', 'activate' ) );
add_action( 'plugins_loaded', array( 'SMA_Plugin', 'init' ) );
