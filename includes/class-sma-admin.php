<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings page: lets the site owner choose their email provider, paste in
 * any DKIM records the provider gave them, and review the DNS records that
 * need to be published.
 */
class SMA_Admin {

	const NONCE_ACTION = 'sma_save_settings';

	/**
	 * Wire up admin hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_save_settings' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/**
	 * Register the settings page under Settings.
	 */
	public static function add_menu() {
		add_options_page(
			__( 'Mail Authenticator', 'steinmetz-mail-authenticator' ),
			__( 'Mail Authenticator', 'steinmetz-mail-authenticator' ),
			'manage_options',
			'steinmetz-mail-authenticator',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Enqueue the admin CSS/JS, only on this plugin's settings page.
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public static function enqueue_assets( $hook ) {
		if ( 'settings_page_steinmetz-mail-authenticator' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'sma-admin',
			SMA_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			SMA_VERSION
		);

		wp_enqueue_script(
			'sma-admin',
			SMA_PLUGIN_URL . 'assets/js/admin.js',
			array(),
			SMA_VERSION,
			true
		);

		wp_localize_script(
			'sma-admin',
			'smaAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( SMA_Ajax::NONCE_ACTION ),
				'i18n'    => array(
					'copy'         => __( 'Copy', 'steinmetz-mail-authenticator' ),
					'copied'       => __( 'Copied!', 'steinmetz-mail-authenticator' ),
					'checking'     => __( 'Checking...', 'steinmetz-mail-authenticator' ),
					'recheck'      => __( 'Recheck DNS status', 'steinmetz-mail-authenticator' ),
					'sending'      => __( 'Sending...', 'steinmetz-mail-authenticator' ),
					'sendTest'     => __( 'Send test email', 'steinmetz-mail-authenticator' ),
					'analyzing'    => __( 'Analyzing...', 'steinmetz-mail-authenticator' ),
					'analyze'      => __( 'Analyze headers', 'steinmetz-mail-authenticator' ),
					'errorGeneric' => __( 'Something went wrong. Please try again.', 'steinmetz-mail-authenticator' ),
					'enterEmail'   => __( 'Please enter an email address to send the test to.', 'steinmetz-mail-authenticator' ),
					'pasteHeaders' => __( 'Please paste the email headers first.', 'steinmetz-mail-authenticator' ),
					'statusMatch'    => __( 'Found', 'steinmetz-mail-authenticator' ),
					'statusMismatch' => __( 'Found, but different', 'steinmetz-mail-authenticator' ),
					'statusMissing'  => __( 'Missing', 'steinmetz-mail-authenticator' ),
					'statusUnknown'  => __( 'Not checked yet', 'steinmetz-mail-authenticator' ),
					'currentValue'   => __( 'Currently set to:', 'steinmetz-mail-authenticator' ),
					'checkedBy'      => __( 'Checked by:', 'steinmetz-mail-authenticator' ),
					'spfLabel'       => __( 'SPF', 'steinmetz-mail-authenticator' ),
					'dkimLabel'      => __( 'DKIM', 'steinmetz-mail-authenticator' ),
					'dmarcLabel'     => __( 'DMARC', 'steinmetz-mail-authenticator' ),
					'noResult'       => __( 'No result', 'steinmetz-mail-authenticator' ),
				),
			)
		);
	}

	/**
	 * The saved settings, merged with defaults.
	 *
	 * @return array
	 */
	public static function get_settings() {
		$settings = get_option( SMA_OPTION_KEY, array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		return wp_parse_args( $settings, self::get_defaults() );
	}

	/**
	 * Default settings, mirroring SMA_Plugin::activate().
	 *
	 * @return array
	 */
	private static function get_defaults() {
		return array(
			'domain'        => SMA_Record_Generator::get_site_domain(),
			'spf_include_custom' => '',
			'provider'      => '',
			'extra_records' => array(),
			'report_email'  => get_option( 'admin_email' ),
			'dmarc_policy'  => 'none',
			'spf_qualifier' => '~all',
		);
	}

	/**
	 * Handle the settings form submission (Post/Redirect/Get).
	 */
	public static function maybe_save_settings() {
		if ( ! isset( $_POST['sma_save'] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'steinmetz-mail-authenticator' ) );
		}

		check_admin_referer( self::NONCE_ACTION, 'sma_nonce' );

		$settings = self::get_settings();

		// Email domain (optional override of the site's own domain).
		$domain = isset( $_POST['sma_domain'] ) ? strtolower( trim( sanitize_text_field( wp_unslash( $_POST['sma_domain'] ) ) ) ) : '';
		$domain = preg_replace( '#^https?://#', '', $domain );
		$domain = rtrim( (string) $domain, '/' );
		if ( 0 === strpos( $domain, 'www.' ) ) {
			$domain = substr( $domain, 4 );
		}
		if ( '' !== $domain && ! preg_match( '/^[a-z0-9]([a-z0-9.\-]*[a-z0-9])?\.[a-z]{2,}$/', $domain ) ) {
			$domain = '';
		}
		$settings['domain'] = $domain;

		// Email provider.
		$provider             = isset( $_POST['sma_provider'] ) ? sanitize_key( wp_unslash( $_POST['sma_provider'] ) ) : '';
		$settings['provider'] = array_key_exists( $provider, SMA_Providers::get_all() ) ? $provider : '';

		// SPF include for "Other" providers, e.g. "_spf.example.com" (an "include:" prefix is tolerated).
		$custom = isset( $_POST['sma_spf_include_custom'] ) ? strtolower( trim( sanitize_text_field( wp_unslash( $_POST['sma_spf_include_custom'] ) ) ) ) : '';
		$custom = preg_replace( '/^include:/', '', $custom );
		$settings['spf_include_custom'] = preg_match( '/^[a-z0-9_]([a-z0-9.\-_]*[a-z0-9])?\.[a-z]{2,}$/', $custom ) ? $custom : '';

		// DMARC report email.
		$report_email             = isset( $_POST['sma_report_email'] ) ? sanitize_email( wp_unslash( $_POST['sma_report_email'] ) ) : '';
		$settings['report_email'] = is_email( $report_email ) ? $report_email : get_option( 'admin_email' );

		// DMARC policy.
		$policy                   = isset( $_POST['sma_dmarc_policy'] ) ? sanitize_key( wp_unslash( $_POST['sma_dmarc_policy'] ) ) : 'none';
		$settings['dmarc_policy'] = in_array( $policy, array( 'none', 'quarantine', 'reject' ), true ) ? $policy : 'none';

		// SPF "all" qualifier.
		$qualifier                 = isset( $_POST['sma_spf_qualifier'] ) ? sanitize_text_field( wp_unslash( $_POST['sma_spf_qualifier'] ) ) : '~all';
		$settings['spf_qualifier'] = in_array( $qualifier, array( '~all', '-all' ), true ) ? $qualifier : '~all';

		// Extra (DKIM / provider-supplied) records.
		$types  = isset( $_POST['sma_extra_type'] ) ? (array) wp_unslash( $_POST['sma_extra_type'] ) : array();
		$hosts  = isset( $_POST['sma_extra_host'] ) ? (array) wp_unslash( $_POST['sma_extra_host'] ) : array();
		$values = isset( $_POST['sma_extra_value'] ) ? (array) wp_unslash( $_POST['sma_extra_value'] ) : array();

		$extra = array();
		$count = max( count( $types ), count( $hosts ), count( $values ) );

		for ( $i = 0; $i < $count; $i++ ) {
			$host  = isset( $hosts[ $i ] ) ? sanitize_text_field( $hosts[ $i ] ) : '';
			$value = isset( $values[ $i ] ) ? sanitize_text_field( $values[ $i ] ) : '';

			if ( '' === $host && '' === $value ) {
				continue;
			}

			$type = isset( $types[ $i ] ) ? strtoupper( sanitize_text_field( $types[ $i ] ) ) : 'TXT';
			$type = in_array( $type, array( 'TXT', 'CNAME' ), true ) ? $type : 'TXT';

			$extra[] = array(
				'type'  => $type,
				'host'  => $host,
				'value' => $value,
			);
		}
		$settings['extra_records'] = $extra;

		update_option( SMA_OPTION_KEY, $settings );

		set_transient(
			'sma_admin_notice_' . get_current_user_id(),
			array(
				'type'    => 'success',
				'message' => __( 'Settings saved.', 'steinmetz-mail-authenticator' ),
			),
			30
		);

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'             => 'steinmetz-mail-authenticator',
					'settings-updated' => 'true',
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * Render the settings page.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'steinmetz-mail-authenticator' ) );
		}

		$settings  = self::get_settings();
		$providers = SMA_Providers::get_all();
		$records   = SMA_Record_Generator::get_required_records( $settings );
		$domain    = SMA_Record_Generator::get_email_domain( $settings );

		$spf_lookups = 0;
		foreach ( $records as $record ) {
			if ( 'spf' === $record['key'] ) {
				$spf_lookups = SMA_Record_Generator::count_spf_lookups( $record['value'] );
				break;
			}
		}

		$notice_key = 'sma_admin_notice_' . get_current_user_id();
		$notice     = get_transient( $notice_key );
		if ( $notice ) {
			delete_transient( $notice_key );
		}

		include SMA_PLUGIN_DIR . 'includes/views/page-main.php';
	}
}
