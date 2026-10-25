/* global smaAdmin */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		initAddRecordRow();
		initCopyButtons();
		initRecheckDns();
		initSendTestEmail();
		initAnalyzeHeaders();
	} );

	/**
	 * POST an admin-ajax request and resolve with the decoded JSON response.
	 *
	 * @param {string} action AJAX action name (without the "wp_ajax_" prefix).
	 * @param {Object} data   Extra fields to send.
	 * @return {Promise<Object>}
	 */
	function postAjax( action, data ) {
		var params = new URLSearchParams();
		params.set( 'action', action );
		params.set( 'nonce', smaAdmin.nonce );

		Object.keys( data || {} ).forEach( function ( key ) {
			params.set( key, data[ key ] );
		} );

		return fetch( smaAdmin.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: params.toString(),
		} ).then( function ( response ) {
			return response.json();
		} );
	}

	/**
	 * Copy text to the clipboard, falling back to execCommand for browsers
	 * (or contexts) without the async Clipboard API.
	 *
	 * @param {string} text
	 * @return {Promise<void>}
	 */
	function copyText( text ) {
		if ( navigator.clipboard && window.isSecureContext ) {
			return navigator.clipboard.writeText( text );
		}

		var textarea = document.createElement( 'textarea' );
		textarea.value = text;
		textarea.setAttribute( 'readonly', '' );
		textarea.style.position = 'absolute';
		textarea.style.left = '-9999px';
		document.body.appendChild( textarea );
		textarea.select();

		try {
			document.execCommand( 'copy' );
		} finally {
			document.body.removeChild( textarea );
		}

		return Promise.resolve();
	}

	/**
	 * Disable a button and swap in busy text, remembering the original.
	 *
	 * @param {HTMLButtonElement} button
	 * @param {string}            busyText
	 */
	function setBusy( button, busyText ) {
		if ( ! button.dataset.smaOriginalText ) {
			button.dataset.smaOriginalText = button.textContent;
		}
		button.textContent = busyText;
		button.disabled = true;
	}

	/**
	 * Restore a button's original text and re-enable it.
	 *
	 * @param {HTMLButtonElement} button
	 */
	function clearBusy( button ) {
		if ( button.dataset.smaOriginalText ) {
			button.textContent = button.dataset.smaOriginalText;
			delete button.dataset.smaOriginalText;
		}
		button.disabled = false;
	}

	/**
	 * Map a DNS record check status to its display label.
	 *
	 * @param {string} status 'match' | 'mismatch' | 'missing' | 'unknown'.
	 * @return {string}
	 */
	function statusLabel( status ) {
		switch ( status ) {
			case 'match':
				return smaAdmin.i18n.statusMatch;
			case 'mismatch':
				return smaAdmin.i18n.statusMismatch;
			case 'missing':
				return smaAdmin.i18n.statusMissing;
			default:
				return smaAdmin.i18n.statusUnknown;
		}
	}

	/**
	 * Allow adding extra DKIM / provider record rows.
	 */
	function initAddRecordRow() {
		var addButton = document.getElementById( 'sma-add-row' );
		var body = document.getElementById( 'sma-extra-records-body' );

		if ( ! addButton || ! body ) {
			return;
		}

		addButton.addEventListener( 'click', function () {
			var lastRow = body.querySelector( 'tr.sma-extra-record-row:last-child' );
			var newRow = lastRow ? lastRow.cloneNode( true ) : null;

			if ( ! newRow ) {
				return;
			}

			newRow.querySelectorAll( 'input' ).forEach( function ( input ) {
				input.value = '';
			} );
			newRow.querySelectorAll( 'select' ).forEach( function ( select ) {
				select.selectedIndex = 0;
			} );

			body.appendChild( newRow );
		} );

		// Event delegation: remove-row buttons exist on initial rows and any
		// rows added later by the button above.
		body.addEventListener( 'click', function ( event ) {
			var removeButton = event.target.closest( '.sma-remove-row' );
			if ( ! removeButton ) {
				return;
			}

			var row = removeButton.closest( 'tr.sma-extra-record-row' );
			if ( row && body.querySelectorAll( 'tr.sma-extra-record-row' ).length > 1 ) {
				row.remove();
			} else if ( row ) {
				row.querySelectorAll( 'input' ).forEach( function ( input ) {
					input.value = '';
				} );
			}
		} );
	}

	/**
	 * "Copy" buttons next to each generated DNS record value.
	 */
	function initCopyButtons() {
		document.querySelectorAll( '.sma-copy-btn' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				var text = button.dataset.copyText || '';

				copyText( text ).then( function () {
					var original = button.textContent;
					button.textContent = smaAdmin.i18n.copied;
					setTimeout( function () {
						button.textContent = original;
					}, 1500 );
				} );
			} );
		} );
	}

	/**
	 * "Recheck DNS status" button: re-queries DNS for every required record
	 * and updates the status badge (and "currently set to" note) per row.
	 */
	function initRecheckDns() {
		var button = document.getElementById( 'sma-recheck-dns' );
		var resultEl = document.getElementById( 'sma-recheck-result' );

		if ( ! button ) {
			return;
		}

		button.addEventListener( 'click', function () {
			setBusy( button, smaAdmin.i18n.checking );
			resultEl.textContent = '';

			postAjax( 'sma_check_dns', {} )
				.then( function ( response ) {
					if ( ! response || ! response.success ) {
						resultEl.textContent = ( response && response.data && response.data.message ) || smaAdmin.i18n.errorGeneric;
						resultEl.className = 'sma-inline-result sma-message-error';
						return;
					}

					response.data.records.forEach( function ( record ) {
						var row = document.querySelector( '#sma-records-table tr[data-record-key="' + record.key + '"]' );
						if ( ! row ) {
							return;
						}

						var cell = row.querySelector( '.sma-status-cell' );
						if ( ! cell ) {
							return;
						}

						cell.innerHTML = '';

						var badge = document.createElement( 'span' );
						badge.className = 'sma-status sma-status-' + record.status;
						badge.textContent = statusLabel( record.status );
						cell.appendChild( badge );

						if ( 'match' !== record.status && record.actual ) {
							var note = document.createElement( 'p' );
							note.className = 'description';
							note.textContent = smaAdmin.i18n.currentValue + ' ' + record.actual;
							cell.appendChild( note );
						}
					} );

					resultEl.textContent = '';
					resultEl.className = 'sma-inline-result';
				} )
				.catch( function () {
					resultEl.textContent = smaAdmin.i18n.errorGeneric;
					resultEl.className = 'sma-inline-result sma-message-error';
				} )
				.finally( function () {
					clearBusy( button );
				} );
		} );
	}

	/**
	 * "Send test email" button.
	 */
	function initSendTestEmail() {
		var button = document.getElementById( 'sma-send-test' );
		var input = document.getElementById( 'sma-test-email' );
		var resultEl = document.getElementById( 'sma-send-test-result' );

		if ( ! button || ! input ) {
			return;
		}

		button.addEventListener( 'click', function () {
			var to = input.value.trim();

			if ( '' === to ) {
				resultEl.textContent = smaAdmin.i18n.enterEmail;
				resultEl.className = 'sma-inline-result sma-message-error';
				return;
			}

			setBusy( button, smaAdmin.i18n.sending );
			resultEl.textContent = '';

			postAjax( 'sma_send_test_email', { to: to } )
				.then( function ( response ) {
					var message = response && response.data && response.data.message;

					if ( response && response.success ) {
						resultEl.textContent = message || '';
						resultEl.className = 'sma-inline-result sma-message-success';
					} else {
						resultEl.textContent = message || smaAdmin.i18n.errorGeneric;
						resultEl.className = 'sma-inline-result sma-message-error';
					}
				} )
				.catch( function () {
					resultEl.textContent = smaAdmin.i18n.errorGeneric;
					resultEl.className = 'sma-inline-result sma-message-error';
				} )
				.finally( function () {
					clearBusy( button );
				} );
		} );
	}

	/**
	 * "Analyze headers" button.
	 */
	function initAnalyzeHeaders() {
		var button = document.getElementById( 'sma-analyze-headers' );
		var textarea = document.getElementById( 'sma-headers' );
		var resultEl = document.getElementById( 'sma-analysis-result' );

		if ( ! button || ! textarea ) {
			return;
		}

		button.addEventListener( 'click', function () {
			var raw = textarea.value.trim();

			if ( '' === raw ) {
				resultEl.innerHTML = '';
				var notice = document.createElement( 'p' );
				notice.className = 'sma-message-error';
				notice.textContent = smaAdmin.i18n.pasteHeaders;
				resultEl.appendChild( notice );
				return;
			}

			setBusy( button, smaAdmin.i18n.analyzing );
			resultEl.innerHTML = '';

			postAjax( 'sma_analyze_headers', { raw: raw } )
				.then( function ( response ) {
					if ( ! response || ! response.success ) {
						var message = ( response && response.data && response.data.message ) || smaAdmin.i18n.errorGeneric;
						var notice = document.createElement( 'p' );
						notice.className = 'sma-message-error';
						notice.textContent = message;
						resultEl.appendChild( notice );
						return;
					}

					renderAnalysis( resultEl, response.data.results );
				} )
				.catch( function () {
					var notice = document.createElement( 'p' );
					notice.className = 'sma-message-error';
					notice.textContent = smaAdmin.i18n.errorGeneric;
					resultEl.appendChild( notice );
				} )
				.finally( function () {
					clearBusy( button );
				} );
		} );
	}

	/**
	 * Render one ".sma-analysis-block" per Authentication-Results header found.
	 *
	 * @param {HTMLElement} container
	 * @param {Array}       results
	 */
	function renderAnalysis( container, results ) {
		results.forEach( function ( entry ) {
			var block = document.createElement( 'div' );
			block.className = 'sma-analysis-block';

			var heading = document.createElement( 'h4' );
			heading.textContent = smaAdmin.i18n.checkedBy + ' ' + ( entry.authserv_id || smaAdmin.i18n.noResult );
			block.appendChild( heading );

			[ 'spf', 'dkim', 'dmarc' ].forEach( function ( mechanism ) {
				var data = entry.mechanisms[ mechanism ] || { status: '', class: 'unknown', description: '' };
				var label = smaAdmin.i18n[ mechanism + 'Label' ];

				var row = document.createElement( 'div' );
				row.className = 'sma-analysis-row';

				var badge = document.createElement( 'span' );
				badge.className = 'sma-status sma-status-' + ( data.class || 'unknown' );
				badge.textContent = label + ': ' + ( data.status || smaAdmin.i18n.noResult );
				row.appendChild( badge );

				if ( data.description ) {
					var desc = document.createElement( 'span' );
					desc.className = 'sma-analysis-desc';
					desc.textContent = data.description;
					row.appendChild( desc );
				}

				block.appendChild( row );
			} );

			container.appendChild( block );
		} );
	}
} )();
