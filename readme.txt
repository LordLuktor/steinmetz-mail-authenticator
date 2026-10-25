=== Steinmetz Mail Authenticator ===
Contributors: scottsteinmetz
Tags: email, spf, dkim, dmarc, deliverability
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Walks a site owner through SPF, DKIM and DMARC so receipts and notifications land in the inbox, then checks the live DNS and a real test email.

== Description ==

Order receipts and password resets that land in spam usually come down to three DNS records nobody set up. This plugin:

* Asks which service sends your mail (Google Workspace, Microsoft 365, Amazon SES, SendGrid, Mailgun, Brevo, Postmark, or any other) and builds the exact SPF, DMARC and DKIM records you need.
* Merges into your existing SPF record instead of replacing it, and never produces a second SPF record (which would break SPF entirely).
* Checks your live DNS and shows a plain pass / needs-attention status for each record. SPF and DMARC are checked by meaning, so a correct record written in a different order still passes.
* Sends a test email, then reads the "Authentication-Results" header you paste back from Gmail or Outlook and tells you, in plain English, whether SPF, DKIM and DMARC passed.

It never asks for your DNS provider login or stores any credentials. You copy the records into your DNS panel yourself, with step-by-step instructions and copy buttons.

== Installation ==

1. Upload the `steinmetz-mail-authenticator` folder to `/wp-content/plugins/` and activate it.
2. Go to Settings > Mail Authenticator.
3. Pick the service that sends your email. If it isn't listed, choose "Other" and paste its SPF include value.
4. Paste any DKIM records your email service gave you, then save.
5. Add the records shown to your DNS, click "Check DNS", then send a test email and paste its headers back in.

== Frequently Asked Questions ==

= Does it change my DNS? =
No. It only reads public DNS. You stay in control of every change.

= Which DMARC policy should I start with? =
"Monitor only" while you confirm everything passes, then "Quarantine" once your test email and a week or two of reports look clean.

== Changelog ==

= 0.1.1 =
* Fix: DMARC records were always reported missing (hostnames starting with an underscore were rejected).
* Fix: SPF and DMARC are now compared by meaning, not exact text.
* New: SPF include field for providers that aren't listed; a pasted SPF record is merged instead of becoming a second SPF record.
* New: warns when a domain publishes more than one SPF record.

= 0.1.0 =
* First version.
