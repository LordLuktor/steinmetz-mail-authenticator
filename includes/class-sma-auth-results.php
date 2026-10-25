<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Parses the "Authentication-Results" header(s) from a raw email source
 * (as copied from "Show original" in Gmail, or "Internet headers" in
 * Outlook) and turns the spf=/dkim=/dmarc= results into something a
 * non-technical site owner can read.
 */
class SMA_Auth_Results {

	/**
	 * @param string $raw Raw email source or just the headers.
	 * @return array[] One entry per Authentication-Results header found,
	 *                  each: ['authserv_id' => string, 'spf' => string,
	 *                  'dkim' => string, 'dmarc' => string, 'raw' => string].
	 */
	public static function parse( $raw ) {
		$raw = (string) $raw;

		// Unfold header continuation lines (RFC 5322: lines starting with
		// whitespace are a continuation of the previous header).
		$unfolded = preg_replace( '/\r\n[ \t]+/', ' ', $raw );
		$unfolded = preg_replace( '/\n[ \t]+/', ' ', $unfolded );

		$results = array();

		if ( ! preg_match_all( '/^Authentication-Results:\s*(.+)$/im', $unfolded, $matches ) ) {
			return $results;
		}

		foreach ( $matches[1] as $header_value ) {
			$entry = array(
				'authserv_id' => '',
				'spf'         => '',
				'dkim'        => '',
				'dmarc'       => '',
				'raw'         => trim( $header_value ),
			);

			// The authserv-id is everything up to the first ";".
			if ( preg_match( '/^([^;]+);/', $header_value, $m ) ) {
				$entry['authserv_id'] = trim( $m[1] );
			}

			foreach ( array( 'spf', 'dkim', 'dmarc' ) as $mechanism ) {
				if ( preg_match( '/\b' . $mechanism . '\s*=\s*([a-zA-Z]+)/i', $header_value, $m ) ) {
					$entry[ $mechanism ] = strtolower( $m[1] );
				}
			}

			$results[] = $entry;
		}

		return $results;
	}

	/**
	 * Plain-language explanation of a result value for a given mechanism.
	 *
	 * @param string $mechanism 'spf' | 'dkim' | 'dmarc'.
	 * @param string $status    The raw result value, e.g. 'pass', 'fail', 'none'.
	 * @return string Empty string if there is no canned explanation.
	 */
	public static function describe( $mechanism, $status ) {
		$descriptions = array(
			'spf'   => array(
				'pass'      => __( 'The sending server is authorized by your SPF record.', 'steinmetz-mail-authenticator' ),
				'fail'      => __( 'The sending server is NOT authorized by your SPF record.', 'steinmetz-mail-authenticator' ),
				'softfail'  => __( 'Your SPF record marks this sender as questionable ("~all") rather than rejecting it outright.', 'steinmetz-mail-authenticator' ),
				'neutral'   => __( 'Your SPF record neither allows nor denies this sender.', 'steinmetz-mail-authenticator' ),
				'none'      => __( 'No SPF record was found for this domain.', 'steinmetz-mail-authenticator' ),
				'permerror' => __( 'Your SPF record has a syntax error and could not be evaluated.', 'steinmetz-mail-authenticator' ),
				'temperror' => __( 'A temporary error occurred while checking SPF - try sending another test email.', 'steinmetz-mail-authenticator' ),
			),
			'dkim'  => array(
				'pass'      => __( 'The message had a valid DKIM signature matching your published key.', 'steinmetz-mail-authenticator' ),
				'fail'      => __( 'The message had a DKIM signature, but it did not validate against your published key.', 'steinmetz-mail-authenticator' ),
				'none'      => __( 'The message was not signed with DKIM.', 'steinmetz-mail-authenticator' ),
				'permerror' => __( 'Your DKIM record has a syntax error and could not be evaluated.', 'steinmetz-mail-authenticator' ),
				'temperror' => __( 'A temporary error occurred while checking DKIM - try sending another test email.', 'steinmetz-mail-authenticator' ),
			),
			'dmarc' => array(
				'pass' => __( 'SPF or DKIM aligned with your "From:" domain - DMARC passes.', 'steinmetz-mail-authenticator' ),
				'fail' => __( 'Neither SPF nor DKIM aligned with your "From:" domain - DMARC fails.', 'steinmetz-mail-authenticator' ),
				'none' => __( 'No DMARC record was found for this domain.', 'steinmetz-mail-authenticator' ),
			),
		);

		$status = strtolower( (string) $status );

		return $descriptions[ $mechanism ][ $status ] ?? '';
	}

	/**
	 * Map a result value to a CSS status class for display.
	 *
	 * @param string $status
	 * @return string 'good' | 'bad' | 'warn' | 'unknown'.
	 */
	public static function status_class( $status ) {
		$status = strtolower( (string) $status );

		if ( 'pass' === $status ) {
			return 'good';
		}

		if ( in_array( $status, array( 'fail', 'permerror' ), true ) ) {
			return 'bad';
		}

		if ( '' === $status ) {
			return 'unknown';
		}

		return 'warn';
	}
}
