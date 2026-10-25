<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AJAX endpoints used by the settings page: re-check DNS status, send a
 * test email, and analyze pasted Authentication-Results headers.
 */
class SMA_Ajax {

	const NONCE_ACTION = 'sma_ajax';

	/**
	 * Register AJAX handlers (admin-only - this plugin has no front-end).
	 */
	public static function init() {
		add_action( 'wp_ajax_sma_check_dns', array( __CLASS__, 'check_dns' ) );
		add_action( 'wp_ajax_sma_send_test_email', array( __CLASS__, 'send_test_email' ) );
		add_action( 'wp_ajax_sma_analyze_headers', array( __CLASS__, 'analyze_headers' ) );
	}

	/**
	 * Capability + nonce check shared by all handlers. Halts the request
	 * with a JSON error if either check fails.
	 */
	private static function verify_request() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'You do not have permission to do this.', 'steinmetz-mail-authenticator' ) ),
				403
			);
		}

		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
	}

	/**
	 * Re-check the live DNS status of every required record.
	 */
	public static function check_dns() {
		self::verify_request();

		$settings = SMA_Admin::get_settings();
		$records  = SMA_Record_Generator::get_required_records( $settings );
		$checked  = SMA_DNS_Checker::check_all( $records );

		$output = array();
		foreach ( $checked as $key => $record ) {
			$output[] = array(
				'key'    => $key,
				'status' => $record['status'],
				'actual' => $record['actual'],
			);
		}

		wp_send_json_success( array( 'records' => $output ) );
	}

	/**
	 * Send a test email the admin can run through Gmail's "Show original"
	 * (or Outlook's "Internet headers") to get a real Authentication-Results
	 * header to paste back in.
	 */
	public static function send_test_email() {
		self::verify_request();

		$to = isset( $_POST['to'] ) ? sanitize_email( wp_unslash( $_POST['to'] ) ) : '';

		if ( ! is_email( $to ) ) {
			wp_send_json_error( array( 'message' => __( 'Please enter a valid email address.', 'steinmetz-mail-authenticator' ) ) );
		}

		$rate_key = 'sma_test_email_' . get_current_user_id();
		if ( get_transient( $rate_key ) ) {
			wp_send_json_error( array( 'message' => __( 'Please wait a minute before sending another test email.', 'steinmetz-mail-authenticator' ) ) );
		}

		set_transient( $rate_key, time(), 60 );

		$subject = __( 'Steinmetz Mail Authenticator test email', 'steinmetz-mail-authenticator' );

		$body  = __( 'This is a test email from Steinmetz Mail Authenticator.', 'steinmetz-mail-authenticator' ) . "\n\n";
		$body .= __( 'To check your authentication results:', 'steinmetz-mail-authenticator' ) . "\n";
		$body .= __( '- In Gmail: open this message, click the three-dot menu, then choose "Show original".', 'steinmetz-mail-authenticator' ) . "\n";
		$body .= __( '- In Outlook: open this message, then view its Internet headers.', 'steinmetz-mail-authenticator' ) . "\n\n";
		$body .= __( 'Copy the header block (or the whole original message) and paste it into the "Test your setup" section of Steinmetz Mail Authenticator.', 'steinmetz-mail-authenticator' );

		$sent = wp_mail( $to, $subject, $body );

		if ( $sent ) {
			wp_send_json_success( array( 'message' => __( 'Test email sent. Check the inbox (and spam folder) for it.', 'steinmetz-mail-authenticator' ) ) );
		}

		wp_send_json_error( array( 'message' => __( 'The test email could not be sent - check your site\'s outgoing mail configuration.', 'steinmetz-mail-authenticator' ) ) );
	}

	/**
	 * Parse pasted email headers and report on the SPF/DKIM/DMARC results.
	 */
	public static function analyze_headers() {
		self::verify_request();

		$raw = isset( $_POST['raw'] ) ? (string) wp_unslash( $_POST['raw'] ) : '';
		$raw = substr( $raw, 0, 200000 );

		if ( '' === trim( $raw ) ) {
			wp_send_json_error( array( 'message' => __( 'Please paste the email headers first.', 'steinmetz-mail-authenticator' ) ) );
		}

		$parsed = SMA_Auth_Results::parse( $raw );

		if ( empty( $parsed ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'No "Authentication-Results" header was found in the pasted text. Make sure you copied the full headers (or the whole original message).', 'steinmetz-mail-authenticator' ),
				)
			);
		}

		$results = array();
		foreach ( $parsed as $entry ) {
			$mechanisms = array();

			foreach ( array( 'spf', 'dkim', 'dmarc' ) as $mechanism ) {
				$status                    = $entry[ $mechanism ];
				$mechanisms[ $mechanism ] = array(
					'status'      => $status,
					'class'       => SMA_Auth_Results::status_class( $status ),
					'description' => SMA_Auth_Results::describe( $mechanism, $status ),
				);
			}

			$results[] = array(
				'authserv_id' => $entry['authserv_id'],
				'mechanisms'  => $mechanisms,
			);
		}

		wp_send_json_success( array( 'results' => $results ) );
	}
}
