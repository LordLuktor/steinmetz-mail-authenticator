<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings page template.
 *
 * Variables provided by SMA_Admin::render_page():
 * @var array       $settings    Saved plugin settings.
 * @var array       $providers   All known providers, keyed by provider key.
 * @var array[]     $records     Required DNS records.
 * @var string      $domain      The domain being authenticated.
 * @var int         $spf_lookups Approximate SPF DNS-lookup count.
 * @var array|false $notice      One-time admin notice, or false.
 */
?>
<div class="wrap sma-wrap">
	<h1><?php esc_html_e( 'Steinmetz Mail Authenticator', 'steinmetz-mail-authenticator' ); ?></h1>
	<p class="sma-intro">
		<?php esc_html_e( 'Set up SPF, DKIM, and DMARC for your domain so the receipts and notifications this site sends land in the inbox instead of the spam folder.', 'steinmetz-mail-authenticator' ); ?>
	</p>

	<?php if ( $notice ) : ?>
		<div class="notice notice-<?php echo esc_attr( 'success' === $notice['type'] ? 'success' : 'error' ); ?> is-dismissible">
			<p><?php echo esc_html( $notice['message'] ); ?></p>
		</div>
	<?php endif; ?>

	<div class="sma-card">
		<h2><?php esc_html_e( '1. Email setup', 'steinmetz-mail-authenticator' ); ?></h2>
		<form method="post">
			<?php wp_nonce_field( SMA_Admin::NONCE_ACTION, 'sma_nonce' ); ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="sma_domain"><?php esc_html_e( 'Email domain', 'steinmetz-mail-authenticator' ); ?></label>
					</th>
					<td>
						<input
							type="text"
							id="sma_domain"
							name="sma_domain"
							class="regular-text"
							value="<?php echo esc_attr( $settings['domain'] ); ?>"
							placeholder="<?php echo esc_attr( SMA_Record_Generator::get_site_domain() ); ?>"
						/>
						<p class="description">
							<?php esc_html_e( 'Only fill this in if mail is sent from a different domain than your site address. Leave blank to use your site domain.', 'steinmetz-mail-authenticator' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="sma_provider"><?php esc_html_e( 'Email sending service', 'steinmetz-mail-authenticator' ); ?></label>
					</th>
					<td>
						<select id="sma_provider" name="sma_provider">
							<option value=""><?php esc_html_e( '— Choose the service that sends your email —', 'steinmetz-mail-authenticator' ); ?></option>
							<?php foreach ( $providers as $key => $provider ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $settings['provider'], $key ); ?>>
									<?php echo esc_html( $provider['label'] ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="description">
							<?php esc_html_e( 'This is whatever sends mail on behalf of this site - your hosting server, Google Workspace, Microsoft 365, or an email-sending service like SendGrid or Mailgun.', 'steinmetz-mail-authenticator' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="sma_spf_include_custom"><?php esc_html_e( 'SPF "include" value (optional)', 'steinmetz-mail-authenticator' ); ?></label>
					</th>
					<td>
						<input
							type="text"
							id="sma_spf_include_custom"
							name="sma_spf_include_custom"
							class="regular-text"
							value="<?php echo esc_attr( $settings['spf_include_custom'] ); ?>"
							placeholder="_spf.example.com"
						/>
						<p class="description">
							<?php esc_html_e( 'Only needed if your service is not in the list above. Your email service\'s setup page will show something like "include:_spf.example.com" - paste the part after "include:". It gets merged into your single SPF record.', 'steinmetz-mail-authenticator' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="sma_report_email"><?php esc_html_e( 'DMARC report address', 'steinmetz-mail-authenticator' ); ?></label>
					</th>
					<td>
						<input
							type="email"
							id="sma_report_email"
							name="sma_report_email"
							class="regular-text"
							value="<?php echo esc_attr( $settings['report_email'] ); ?>"
						/>
						<p class="description">
							<?php esc_html_e( 'Aggregate authentication reports from mailbox providers (such as Gmail) will be sent to this address. Your site admin email by default.', 'steinmetz-mail-authenticator' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="sma_dmarc_policy"><?php esc_html_e( 'DMARC policy', 'steinmetz-mail-authenticator' ); ?></label>
					</th>
					<td>
						<select id="sma_dmarc_policy" name="sma_dmarc_policy">
							<option value="none" <?php selected( $settings['dmarc_policy'], 'none' ); ?>>
								<?php esc_html_e( 'Monitor only (recommended to start)', 'steinmetz-mail-authenticator' ); ?>
							</option>
							<option value="quarantine" <?php selected( $settings['dmarc_policy'], 'quarantine' ); ?>>
								<?php esc_html_e( 'Quarantine - send unauthenticated mail to spam', 'steinmetz-mail-authenticator' ); ?>
							</option>
							<option value="reject" <?php selected( $settings['dmarc_policy'], 'reject' ); ?>>
								<?php esc_html_e( 'Reject - block unauthenticated mail outright', 'steinmetz-mail-authenticator' ); ?>
							</option>
						</select>
						<p class="description">
							<?php esc_html_e( 'Start with "Monitor only" while you confirm everything passes, then tighten this once you have verified the test results below.', 'steinmetz-mail-authenticator' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="sma_spf_qualifier"><?php esc_html_e( 'SPF strictness', 'steinmetz-mail-authenticator' ); ?></label>
					</th>
					<td>
						<select id="sma_spf_qualifier" name="sma_spf_qualifier">
							<option value="~all" <?php selected( $settings['spf_qualifier'], '~all' ); ?>>
								<?php esc_html_e( 'Soft fail (~all) - recommended while testing', 'steinmetz-mail-authenticator' ); ?>
							</option>
							<option value="-all" <?php selected( $settings['spf_qualifier'], '-all' ); ?>>
								<?php esc_html_e( 'Hard fail (-all) - recommended once verified', 'steinmetz-mail-authenticator' ); ?>
							</option>
						</select>
					</td>
				</tr>
			</table>

			<h3><?php esc_html_e( 'DKIM and other provider records', 'steinmetz-mail-authenticator' ); ?></h3>

			<div id="sma-dkim-help-blocks">
				<?php foreach ( $providers as $key => $provider ) : ?>
					<p
						class="sma-help-text sma-dkim-help"
						data-provider="<?php echo esc_attr( $key ); ?>"
						<?php echo $key !== $settings['provider'] ? 'style="display:none;"' : ''; ?>
					>
						<?php echo esc_html( $provider['dkim_help'] ); ?>
					</p>
				<?php endforeach; ?>
			</div>

			<p class="description">
				<?php esc_html_e( 'Paste any DKIM (or other) records your provider gave you below. Each record needs its host/name and its value - copy them exactly as shown in your provider dashboard.', 'steinmetz-mail-authenticator' ); ?>
			</p>

			<table class="widefat sma-extra-records-table">
				<thead>
					<tr>
						<th class="sma-col-type"><?php esc_html_e( 'Type', 'steinmetz-mail-authenticator' ); ?></th>
						<th><?php esc_html_e( 'Host / Name', 'steinmetz-mail-authenticator' ); ?></th>
						<th><?php esc_html_e( 'Value / Points to', 'steinmetz-mail-authenticator' ); ?></th>
						<th class="sma-col-remove"></th>
					</tr>
				</thead>
				<tbody id="sma-extra-records-body">
					<?php
					$extra_rows = $settings['extra_records'];
					$blank_rows = max( 0, 3 - count( $extra_rows ) );
					for ( $i = 0; $i < $blank_rows; $i++ ) {
						$extra_rows[] = array(
							'type'  => 'TXT',
							'host'  => '',
							'value' => '',
						);
					}
					foreach ( $extra_rows as $row ) :
						?>
						<tr class="sma-extra-record-row">
							<td>
								<select name="sma_extra_type[]">
									<option value="TXT" <?php selected( $row['type'], 'TXT' ); ?>>TXT</option>
									<option value="CNAME" <?php selected( $row['type'], 'CNAME' ); ?>>CNAME</option>
								</select>
							</td>
							<td>
								<input
									type="text"
									name="sma_extra_host[]"
									class="regular-text"
									value="<?php echo esc_attr( $row['host'] ); ?>"
									placeholder="selector1._domainkey"
								/>
							</td>
							<td>
								<input
									type="text"
									name="sma_extra_value[]"
									class="regular-text"
									value="<?php echo esc_attr( $row['value'] ); ?>"
									placeholder="v=DKIM1; k=rsa; p=..."
								/>
							</td>
							<td>
								<button
									type="button"
									class="button-link sma-remove-row"
									aria-label="<?php esc_attr_e( 'Remove this record', 'steinmetz-mail-authenticator' ); ?>"
								>&times;</button>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p>
				<button type="button" class="button" id="sma-add-row">
					<?php esc_html_e( 'Add another record', 'steinmetz-mail-authenticator' ); ?>
				</button>
			</p>

			<p class="submit">
				<button type="submit" name="sma_save" value="1" class="button button-primary">
					<?php esc_html_e( 'Save settings', 'steinmetz-mail-authenticator' ); ?>
				</button>
			</p>
		</form>
	</div>

	<div class="sma-card">
		<h2><?php esc_html_e( '2. DNS records to add', 'steinmetz-mail-authenticator' ); ?></h2>
		<p>
			<?php esc_html_e( 'Log in to your domain registrar or DNS settings panel for the domain below and add each of these records. If a record type already exists at that host (especially the SPF TXT record), edit the existing one to match the value shown rather than adding a second one.', 'steinmetz-mail-authenticator' ); ?>
		</p>
		<p>
			<strong><?php esc_html_e( 'Domain:', 'steinmetz-mail-authenticator' ); ?></strong>
			<code><?php echo esc_html( $domain ); ?></code>
		</p>

		<?php if ( $spf_lookups > 10 ) : ?>
			<div class="notice notice-warning inline">
				<p>
					<?php esc_html_e( 'Your SPF record now requires more than 10 DNS lookups, which exceeds the SPF specification limit and can cause SPF to fail entirely. Consider removing unused includes.', 'steinmetz-mail-authenticator' ); ?>
				</p>
			</div>
		<?php endif; ?>

		<table class="widefat sma-records-table" id="sma-records-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Type', 'steinmetz-mail-authenticator' ); ?></th>
					<th><?php esc_html_e( 'Host / Name', 'steinmetz-mail-authenticator' ); ?></th>
					<th><?php esc_html_e( 'Value / Points to', 'steinmetz-mail-authenticator' ); ?></th>
					<th><?php esc_html_e( 'Status', 'steinmetz-mail-authenticator' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $records as $record ) : ?>
					<tr data-record-key="<?php echo esc_attr( $record['key'] ); ?>">
						<td><?php echo esc_html( $record['type'] ); ?></td>
						<td>
							<code><?php echo esc_html( $record['host'] ); ?></code>
							<p class="description"><?php echo esc_html( $record['full_host'] ); ?></p>
						</td>
						<td>
							<code class="sma-record-value"><?php echo esc_html( $record['value'] ); ?></code>
							<p class="description"><?php echo esc_html( $record['description'] ); ?></p>
						</td>
						<td class="sma-status-cell">
							<span class="sma-status sma-status-unknown">
								<?php esc_html_e( 'Not checked yet', 'steinmetz-mail-authenticator' ); ?>
							</span>
						</td>
						<td>
							<button
								type="button"
								class="button sma-copy-btn"
								data-copy-text="<?php echo esc_attr( $record['value'] ); ?>"
							>
								<?php esc_html_e( 'Copy', 'steinmetz-mail-authenticator' ); ?>
							</button>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<p>
			<button type="button" class="button button-secondary" id="sma-recheck-dns">
				<?php esc_html_e( 'Recheck DNS status', 'steinmetz-mail-authenticator' ); ?>
			</button>
			<span id="sma-recheck-result" class="sma-inline-result"></span>
		</p>
		<p class="description">
			<?php esc_html_e( 'DNS changes can take anywhere from a few minutes up to 48 hours to take effect everywhere, depending on your provider.', 'steinmetz-mail-authenticator' ); ?>
		</p>
	</div>

	<div class="sma-card">
		<h2><?php esc_html_e( '3. Test your setup', 'steinmetz-mail-authenticator' ); ?></h2>
		<p>
			<?php esc_html_e( 'Once the DNS records above show as found, send a test email to a Gmail (or other) address you control, then check the authentication results.', 'steinmetz-mail-authenticator' ); ?>
		</p>

		<p>
			<label for="sma-test-email"><?php esc_html_e( 'Send a test email to:', 'steinmetz-mail-authenticator' ); ?></label>
			<br />
			<input
				type="email"
				id="sma-test-email"
				class="regular-text"
				value="<?php echo esc_attr( wp_get_current_user()->user_email ); ?>"
			/>
			<button type="button" class="button button-secondary" id="sma-send-test">
				<?php esc_html_e( 'Send test email', 'steinmetz-mail-authenticator' ); ?>
			</button>
		</p>
		<div id="sma-send-test-result" class="sma-inline-result"></div>

		<h3><?php esc_html_e( 'Then check the results', 'steinmetz-mail-authenticator' ); ?></h3>
		<p>
			<?php esc_html_e( 'Open the test email and view its full headers:', 'steinmetz-mail-authenticator' ); ?>
		</p>
		<ul class="sma-help-list">
			<li><?php esc_html_e( 'Gmail: open the message, click the three-dot menu, then choose "Show original".', 'steinmetz-mail-authenticator' ); ?></li>
			<li><?php esc_html_e( 'Outlook: open the message, then File > Properties (or the "..." menu > View > View message details), and look at "Internet headers".', 'steinmetz-mail-authenticator' ); ?></li>
		</ul>
		<p>
			<?php esc_html_e( 'Copy the entire header block (or the whole "original message" text) and paste it below.', 'steinmetz-mail-authenticator' ); ?>
		</p>
		<p>
			<label for="sma-headers"><?php esc_html_e( 'Paste headers here:', 'steinmetz-mail-authenticator' ); ?></label>
			<br />
			<textarea id="sma-headers" rows="8" class="large-text code"></textarea>
		</p>
		<p>
			<button type="button" class="button button-primary" id="sma-analyze-headers">
				<?php esc_html_e( 'Analyze headers', 'steinmetz-mail-authenticator' ); ?>
			</button>
		</p>
		<div id="sma-analysis-result"></div>
	</div>
</div>
