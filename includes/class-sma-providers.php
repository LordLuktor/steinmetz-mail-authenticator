<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Known email-sending services: their SPF "include" mechanism (where one
 * exists) and plain-language guidance on where to find their DKIM record.
 *
 * SPF include values are well-known constants published by each provider,
 * but providers do occasionally change them - worth a periodic spot check
 * against current provider documentation.
 */
class SMA_Providers {

	/**
	 * @return array<string, array{label:string, spf_include:string, dkim_help:string}>
	 */
	public static function get_all() {
		return array(
			'google_workspace' => array(
				'label'       => __( 'Google Workspace', 'steinmetz-mail-authenticator' ),
				'spf_include' => '_spf.google.com',
				'dkim_help'   => __( 'In the Google Admin console, go to Apps > Google Workspace > Gmail > Authenticate email and generate a DKIM key. It gives you a host name (ending in "._domainkey") and a long TXT value starting with "v=DKIM1" - add both below as a TXT record.', 'steinmetz-mail-authenticator' ),
			),
			'microsoft365'     => array(
				'label'       => __( 'Microsoft 365 / Outlook', 'steinmetz-mail-authenticator' ),
				'spf_include' => 'spf.protection.outlook.com',
				'dkim_help'   => __( 'In the Microsoft 365 admin center, go to Settings > Domains > your domain > DNS records, or Exchange admin center > Mail flow > DKIM. Microsoft gives you two CNAME records (selector1 and selector2) - add both below as CNAME records.', 'steinmetz-mail-authenticator' ),
			),
			'amazon_ses'       => array(
				'label'       => __( 'Amazon SES', 'steinmetz-mail-authenticator' ),
				'spf_include' => 'amazonses.com',
				'dkim_help'   => __( 'In the SES console, go to Configuration > Verified identities > your domain > DKIM and enable Easy DKIM. SES gives you three CNAME records - add all three below.', 'steinmetz-mail-authenticator' ),
			),
			'sendgrid'         => array(
				'label'       => __( 'SendGrid', 'steinmetz-mail-authenticator' ),
				'spf_include' => 'sendgrid.net',
				'dkim_help'   => __( 'In SendGrid, go to Settings > Sender Authentication > Authenticate Your Domain. SendGrid gives you a set of CNAME records - add each one below exactly as shown.', 'steinmetz-mail-authenticator' ),
			),
			'mailgun'          => array(
				'label'       => __( 'Mailgun', 'steinmetz-mail-authenticator' ),
				'spf_include' => 'mailgun.org',
				'dkim_help'   => __( 'In Mailgun, go to Sending > Domains > your domain > DNS records. Mailgun shows a TXT record for DKIM (host ending in "._domainkey") - add it below.', 'steinmetz-mail-authenticator' ),
			),
			'brevo'            => array(
				'label'       => __( 'Brevo (Sendinblue)', 'steinmetz-mail-authenticator' ),
				'spf_include' => 'spf.brevo.com',
				'dkim_help'   => __( 'In Brevo, go to Senders, Domains & Dedicated IPs > Domains > Authenticate this domain. Brevo gives you a DKIM TXT record and a verification TXT record - add both below.', 'steinmetz-mail-authenticator' ),
			),
			'postmark'         => array(
				'label'       => __( 'Postmark', 'steinmetz-mail-authenticator' ),
				'spf_include' => 'spf.mtasv.net',
				'dkim_help'   => __( 'In Postmark, go to your server > Domains > your domain > DKIM. Postmark gives you a TXT record - add it below exactly as shown.', 'steinmetz-mail-authenticator' ),
			),
			'other'            => array(
				'label'       => __( 'Other / my host\'s mail server', 'steinmetz-mail-authenticator' ),
				'spf_include' => '',
				'dkim_help'   => __( 'Check your hosting control panel (often under "Email" or "Email Deliverability") for DKIM setup - it may already be enabled there. If your host gave you a TXT or CNAME record for DKIM, add it below. If your host also told you an SPF "include" value to add, enter it as an extra TXT record below as well.', 'steinmetz-mail-authenticator' ),
			),
		);
	}

	/**
	 * @param string $key
	 * @return array{label:string, spf_include:string, dkim_help:string}|null
	 */
	public static function get( $key ) {
		$all = self::get_all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * @return array<string, string> Map of provider key => label, for <select> options.
	 */
	public static function get_choices() {
		$choices = array();
		foreach ( self::get_all() as $key => $provider ) {
			$choices[ $key ] = $provider['label'];
		}
		return $choices;
	}
}
