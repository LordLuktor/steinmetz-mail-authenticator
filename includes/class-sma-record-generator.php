<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Works out which DNS records a domain needs for SPF, DKIM, and DMARC,
 * based on the saved settings and (for SPF) the record currently published.
 */
class SMA_Record_Generator {

	/**
	 * The site's own domain, derived from home_url(), with a leading
	 * "www." stripped. Used as the default email domain.
	 *
	 * @return string
	 */
	public static function get_site_domain() {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$host = $host ? strtolower( $host ) : '';

		if ( 0 === strpos( $host, 'www.' ) ) {
			$host = substr( $host, 4 );
		}

		return $host;
	}

	/**
	 * The domain to authenticate - the saved override if set, otherwise
	 * the site's own domain. Lets sites whose mail domain differs from
	 * their web domain (e.g. mail comes from "shop.com" but the site is
	 * "www.shop-store.com") be configured correctly.
	 *
	 * @param array $settings
	 * @return string
	 */
	public static function get_email_domain( array $settings ) {
		$domain = ! empty( $settings['domain'] ) ? strtolower( trim( $settings['domain'] ) ) : '';
		return '' !== $domain ? $domain : self::get_site_domain();
	}

	/**
	 * Merge a required SPF "include:" mechanism into an existing SPF
	 * record, preserving any other mechanisms already present.
	 *
	 * @param string $current   The currently-published SPF record (may be '').
	 * @param string $include   The host to add as "include:<host>" (may be '').
	 * @param string $qualifier The "all" qualifier to use, e.g. '~all' or '-all'.
	 * @return string The SPF record that should be published.
	 */
	public static function merge_spf( $current, $include, $qualifier = '~all' ) {
		$current   = trim( (string) $current );
		$include   = trim( (string) $include );
		$qualifier = in_array( $qualifier, array( '~all', '-all', '?all', '+all' ), true ) ? $qualifier : '~all';

		if ( '' === $current ) {
			return '' === $include
				? 'v=spf1 ' . $qualifier
				: 'v=spf1 include:' . $include . ' ' . $qualifier;
		}

		if ( '' === $include ) {
			return $current;
		}

		if ( false !== stripos( $current, 'include:' . $include ) ) {
			return $current; // Already covered.
		}

		// Insert the new include just before the "all" mechanism so the
		// rest of the record (other includes, ip4/ip6 ranges, etc.) is
		// preserved untouched.
		if ( preg_match( '/^(v=spf1\b)(.*?)(\s+[\-~?+]?all)\s*$/i', $current, $m ) ) {
			$prefix  = $m[1];
			$middle  = trim( $m[2] );
			$all_mech = trim( $m[3] );

			$new = $prefix;
			if ( '' !== $middle ) {
				$new .= ' ' . $middle;
			}
			$new .= ' include:' . $include . ' ' . $all_mech;

			return $new;
		}

		// No "all" mechanism found - append the include and a default qualifier.
		return rtrim( $current ) . ' include:' . $include . ' ' . $qualifier;
	}

	/**
	 * Roughly count the DNS-lookup-consuming mechanisms in an SPF record.
	 * SPF allows a maximum of 10 (RFC 7208) - used to show a warning, not
	 * as an authoritative count.
	 *
	 * @param string $spf
	 * @return int
	 */
	public static function count_spf_lookups( $spf ) {
		$count = 0;
		foreach ( array( 'include:', 'redirect=', 'a:', 'mx:', 'ptr:', 'exists:' ) as $mechanism ) {
			$count += substr_count( strtolower( $spf ), $mechanism );
		}
		// Bare "a" / "mx" mechanisms (without a colon) also cost a lookup.
		$count += preg_match_all( '/(?:^|\s)[+\-~?]?(a|mx)(?=\s|$)/i', $spf );

		return $count;
	}

	/**
	 * Build a DMARC TXT record value.
	 *
	 * @param string $report_email Address for aggregate (rua) reports. May be empty.
	 * @param string $policy       'none' | 'quarantine' | 'reject'.
	 * @return string
	 */
	public static function generate_dmarc( $report_email, $policy = 'none' ) {
		$policy = in_array( $policy, array( 'none', 'quarantine', 'reject' ), true ) ? $policy : 'none';

		$value = 'v=DMARC1; p=' . $policy;

		if ( ! empty( $report_email ) && is_email( $report_email ) ) {
			$value .= '; rua=mailto:' . $report_email;
		}

		$value .= '; fo=1';

		return $value;
	}

	/**
	 * Turn a host/name as entered by the user (which may be a bare label
	 * like "_dmarc", "@", or a full hostname already including the domain)
	 * into a fully-qualified hostname suitable for a DNS lookup.
	 *
	 * @param string $host
	 * @param string $domain
	 * @return string
	 */
	public static function qualify_host( $host, $domain ) {
		$host = trim( (string) $host );

		if ( '' === $host || '@' === $host ) {
			return $domain;
		}

		$host  = rtrim( $host, '.' );
		$dlen  = strlen( $domain );
		$hlen  = strlen( $host );

		if ( $hlen >= $dlen && 0 === substr_compare( $host, $domain, $hlen - $dlen, $dlen ) ) {
			return $host; // Already fully-qualified for this domain.
		}

		return $host . '.' . $domain;
	}

	/**
	 * Build the full list of DNS records the configured setup needs.
	 *
	 * @param array $settings Saved plugin settings (see SMA_Admin::get_settings()).
	 * @return array[] List of records, each with:
	 *                 key, type, host, full_host, value, label, description.
	 */
	public static function get_required_records( array $settings ) {
		$domain   = self::get_email_domain( $settings );
		$provider = SMA_Providers::get( $settings['provider'] ?? '' );

		$records = array();

		// --- SPF -------------------------------------------------------
		$qualifier   = ! empty( $settings['spf_qualifier'] ) ? $settings['spf_qualifier'] : '~all';
		$includes    = array_filter( array( $provider['spf_include'] ?? '', $settings['spf_include_custom'] ?? '' ) );
		$extra       = ! empty( $settings['extra_records'] ) ? $settings['extra_records'] : array();

		// A pasted "v=spf1 ..." record must never become a second SPF record
		// (two SPF records break SPF entirely) - fold its includes into the one record.
		foreach ( $extra as $i => $rec ) {
			$value = isset( $rec['value'] ) ? trim( $rec['value'] ) : '';
			if ( 0 === stripos( $value, 'v=spf1' ) ) {
				preg_match_all( '/include:([^\s]+)/i', $value, $m );
				$includes = array_merge( $includes, $m[1] );
				unset( $extra[ $i ] );
			}
		}
		$includes = array_values( array_unique( array_map( 'strtolower', $includes ) ) );

		$spf_value = '' !== $domain ? SMA_DNS_Checker::get_spf_record( $domain ) : '';
		foreach ( $includes ?: array( '' ) as $include ) {
			$spf_value = self::merge_spf( $spf_value, $include, $qualifier );
		}

		$records[] = array(
			'key'         => 'spf',
			'type'        => 'TXT',
			'host'        => '@',
			'full_host'   => $domain,
			'value'       => $spf_value,
			'expect'      => array( 'includes' => $includes, 'qualifier' => $qualifier ),
			'label'       => __( 'SPF record', 'steinmetz-mail-authenticator' ),
			'description' => __( 'Authorizes which servers are allowed to send email for your domain.', 'steinmetz-mail-authenticator' ),
		);

		// --- DMARC -------------------------------------------------------
		$report_email = ! empty( $settings['report_email'] ) ? $settings['report_email'] : get_option( 'admin_email' );
		$dmarc_value  = self::generate_dmarc( $report_email, $settings['dmarc_policy'] ?? 'none' );

		$records[] = array(
			'key'         => 'dmarc',
			'type'        => 'TXT',
			'host'        => '_dmarc',
			'full_host'   => '_dmarc.' . $domain,
			'value'       => $dmarc_value,
			'expect'      => array(
				'policy' => in_array( $settings['dmarc_policy'] ?? 'none', array( 'none', 'quarantine', 'reject' ), true ) ? $settings['dmarc_policy'] : 'none',
				'rua'    => is_email( $report_email ) ? $report_email : '',
			),
			'label'       => __( 'DMARC record', 'steinmetz-mail-authenticator' ),
			'description' => __( 'Tells receiving mail servers what to do with messages that fail SPF/DKIM, and where to send reports about them.', 'steinmetz-mail-authenticator' ),
		);

		// --- DKIM / other provider-supplied records ---------------------
		foreach ( $extra as $i => $rec ) {
			$host  = isset( $rec['host'] ) ? trim( $rec['host'] ) : '';
			$value = isset( $rec['value'] ) ? trim( $rec['value'] ) : '';

			if ( '' === $host && '' === $value ) {
				continue;
			}

			$type = isset( $rec['type'] ) ? strtoupper( $rec['type'] ) : 'TXT';
			$type = in_array( $type, array( 'TXT', 'CNAME' ), true ) ? $type : 'TXT';

			$records[] = array(
				'key'         => 'extra_' . $i,
				'type'        => $type,
				'host'        => $host,
				'full_host'   => self::qualify_host( $host, $domain ),
				'value'       => $value,
				'label'       => __( 'DKIM / provider record', 'steinmetz-mail-authenticator' ),
				'description' => __( 'Provided by your email sending service - lets receivers verify messages were signed by them on your behalf.', 'steinmetz-mail-authenticator' ),
			);
		}

		return $records;
	}
}
