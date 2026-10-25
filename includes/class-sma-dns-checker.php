<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads live DNS to find the current SPF record and to check whether
 * required records have been published correctly. All lookups are
 * read-only standard DNS queries - no provider credentials involved.
 */
class SMA_DNS_Checker {

	/**
	 * Get all TXT record values published at a host.
	 *
	 * @param string $host Fully-qualified hostname.
	 * @return string[] One entry per TXT record found at that host.
	 */
	public static function get_txt_records( $host ) {
		$host = self::clean_host( $host );
		if ( '' === $host ) {
			return array();
		}

		// dns_get_record() emits a warning (not an exception) on lookup
		// failure (e.g. NXDOMAIN); suppressed deliberately, callers treat
		// an empty result as "not found".
		$records = @dns_get_record( $host, DNS_TXT );
		if ( ! is_array( $records ) ) {
			return array();
		}

		$values = array();
		foreach ( $records as $record ) {
			if ( isset( $record['txt'] ) ) {
				$values[] = $record['txt'];
			} elseif ( isset( $record['entries'] ) ) {
				$values[] = implode( '', (array) $record['entries'] );
			}
		}
		return $values;
	}

	/**
	 * Get the CNAME target for a host, if any.
	 *
	 * @param string $host Fully-qualified hostname.
	 * @return string Target hostname (no trailing dot), or '' if none.
	 */
	public static function get_cname_record( $host ) {
		$host = self::clean_host( $host );
		if ( '' === $host ) {
			return '';
		}

		$records = @dns_get_record( $host, DNS_CNAME );
		if ( is_array( $records ) && ! empty( $records[0]['target'] ) ) {
			return rtrim( $records[0]['target'], '.' );
		}
		return '';
	}

	/**
	 * Find the current SPF record (the TXT record starting with "v=spf1") for a domain.
	 *
	 * @param string $domain
	 * @return string The raw SPF record, or '' if none is published.
	 */
	public static function get_spf_record( $domain ) {
		foreach ( self::get_txt_records( $domain ) as $txt ) {
			if ( 0 === stripos( trim( $txt ), 'v=spf1' ) ) {
				return trim( $txt );
			}
		}
		return '';
	}

	/**
	 * Check one generated record against live DNS.
	 *
	 * @param array $record Record as produced by SMA_Record_Generator::get_required_records().
	 * @return array{status:string, actual:string} status is one of 'match', 'mismatch', 'missing'.
	 */
	public static function check_record( array $record ) {
		$type     = strtoupper( $record['type'] );
		$host     = $record['full_host'];
		$expected = trim( $record['value'] );

		if ( 'CNAME' === $type ) {
			$actual = self::get_cname_record( $host );
			if ( '' === $actual ) {
				return array(
					'status' => 'missing',
					'actual' => '',
				);
			}

			$expected_clean = rtrim( $expected, '.' );
			if ( 0 === strcasecmp( $actual, $expected_clean ) ) {
				return array(
					'status' => 'match',
					'actual' => $actual,
				);
			}

			return array(
				'status' => 'mismatch',
				'actual' => $actual,
			);
		}

		// TXT-based records (SPF, DMARC, DKIM TXT).
		$values = self::get_txt_records( $host );
		if ( empty( $values ) ) {
			return array(
				'status' => 'missing',
				'actual' => '',
			);
		}

		// SPF and DMARC are compared by meaning, not exact text: a record that
		// has the required parts in a different order (or extra tags) is fine.
		if ( 'spf' === $record['key'] ) {
			return self::check_spf( $values, $record['expect'] ?? array() );
		}
		if ( 'dmarc' === $record['key'] ) {
			return self::check_dmarc( $values, $record['expect'] ?? array() );
		}

		foreach ( $values as $value ) {
			if ( trim( $value ) === $expected ) {
				return array(
					'status' => 'match',
					'actual' => $value,
				);
			}
		}

		// For SPF specifically, surface the existing record even if it
		// does not match exactly - the admin likely needs to merge by hand.
		if ( 'spf' === $record['key'] ) {
			foreach ( $values as $value ) {
				if ( 0 === stripos( trim( $value ), 'v=spf1' ) ) {
					return array(
						'status' => 'mismatch',
						'actual' => $value,
					);
				}
			}
		}

		return array(
			'status' => 'mismatch',
			'actual' => implode( ' | ', $values ),
		);
	}

	/**
	 * SPF passes when exactly one SPF record exists, it contains every
	 * required include, and it ends with the chosen "all" qualifier.
	 *
	 * @param string[] $values TXT values at the domain.
	 * @param array    $expect includes (string[]), qualifier ('~all' | '-all').
	 * @return array{status:string, actual:string}
	 */
	private static function check_spf( array $values, array $expect ) {
		$spf = array_values( array_filter( $values, function ( $v ) {
			return 0 === stripos( trim( $v ), 'v=spf1' );
		} ) );

		if ( empty( $spf ) ) {
			return array( 'status' => 'missing', 'actual' => '' );
		}
		if ( count( $spf ) > 1 ) {
			// Two SPF records make SPF fail outright for every message.
			return array(
				'status' => 'mismatch',
				'actual' => sprintf( __( '%d separate SPF records found - there must be exactly one: ', 'steinmetz-mail-authenticator' ), count( $spf ) ) . implode( ' | ', $spf ),
			);
		}

		$live = strtolower( trim( $spf[0] ) );
		foreach ( (array) ( $expect['includes'] ?? array() ) as $include ) {
			if ( ! preg_match( '/(^|\s)\+?include:' . preg_quote( strtolower( $include ), '/' ) . '(\s|$)/', $live ) ) {
				return array( 'status' => 'mismatch', 'actual' => $spf[0] );
			}
		}
		if ( ! empty( $expect['qualifier'] ) && ! preg_match( '/(^|\s)' . preg_quote( $expect['qualifier'], '/' ) . '$/', $live ) ) {
			return array( 'status' => 'mismatch', 'actual' => $spf[0] );
		}

		return array( 'status' => 'match', 'actual' => $spf[0] );
	}

	/**
	 * DMARC passes when the record's policy matches and, if a report address
	 * was chosen, that address is among its rua= destinations.
	 *
	 * @param string[] $values TXT values at _dmarc.<domain>.
	 * @param array    $expect policy (string), rua (string, may be '').
	 * @return array{status:string, actual:string}
	 */
	private static function check_dmarc( array $values, array $expect ) {
		foreach ( $values as $value ) {
			if ( 0 !== stripos( trim( $value ), 'v=DMARC1' ) ) {
				continue;
			}
			$tags = array();
			foreach ( explode( ';', $value ) as $pair ) {
				$parts = explode( '=', $pair, 2 );
				if ( 2 === count( $parts ) ) {
					$tags[ strtolower( trim( $parts[0] ) ) ] = strtolower( trim( $parts[1] ) );
				}
			}
			$policy_ok = ( $tags['p'] ?? '' ) === ( $expect['policy'] ?? '' );
			$rua_ok    = empty( $expect['rua'] ) || in_array( 'mailto:' . strtolower( $expect['rua'] ), array_map( 'trim', explode( ',', $tags['rua'] ?? '' ) ), true );

			return array( 'status' => ( $policy_ok && $rua_ok ) ? 'match' : 'mismatch', 'actual' => $value );
		}

		return array( 'status' => 'missing', 'actual' => '' );
	}

	/**
	 * Check a full set of required records.
	 *
	 * @param array $records
	 * @return array<string, array> Keyed by record 'key', merged with status/actual.
	 */
	public static function check_all( array $records ) {
		$results = array();
		foreach ( $records as $record ) {
			$results[ $record['key'] ] = array_merge( $record, self::check_record( $record ) );
		}
		return $results;
	}

	/**
	 * Normalize and validate a hostname before passing it to dns_get_record().
	 *
	 * @param string $host
	 * @return string Lowercased hostname, or '' if it does not look like a hostname.
	 */
	private static function clean_host( $host ) {
		$host = strtolower( trim( (string) $host ) );
		$host = rtrim( $host, '.' );

		// Labels may start with an underscore (_dmarc, _domainkey, _spf).
		if ( '' === $host || ! preg_match( '/^[a-z0-9_]([a-z0-9.\-_]*[a-z0-9])?$/', $host ) ) {
			return '';
		}

		return $host;
	}
}
