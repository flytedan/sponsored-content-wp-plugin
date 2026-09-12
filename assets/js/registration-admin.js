/**
 * Flytedesk Hosted Content — Registration page.
 *
 * Renders the timeline / status panel / technical details from a single
 * `state` object (the same shape PHP embeds for the first paint and the
 * AJAX endpoints return afterwards), polls for updates every 3 seconds
 * while the registration is in a non-terminal state, and drives the
 * "Register Now" / "Re-register" button.
 */
( function () {
	'use strict';

	var config = window.flytedeskRegistration;
	if ( ! config ) {
		return;
	}

	var POLL_INTERVAL_MS = 3000;
	var pollTimer = null;

	var els = {
		timeline: document.getElementById( 'flytedesk-timeline' ),
		statusPanel: document.getElementById( 'flytedesk-status-panel' ),
		technicalPanel: document.getElementById( 'flytedesk-technical-panel' ),
		registerButton: document.getElementById( 'flytedesk-register-button' ),
		pollIndicator: document.getElementById( 'flytedesk-poll-indicator' ),
		verificationBreakdown: document.getElementById( 'flytedesk-verification-breakdown' ),
		consentButton: document.getElementById( 'flytedesk-consent-button' ),
	};

	document.addEventListener( 'DOMContentLoaded', function () {
		initTabs();

		if ( els.consentButton ) {
			els.consentButton.addEventListener( 'click', onGrantConsentClick );
		}

		// The consent gate has none of the dashboard elements below (no
		// point polling a page where nothing can change until the user
		// clicks "Connect to flytedesk").
		if ( els.timeline ) {
			renderState( config.initialState );
			maybeStartPolling( config.initialState );
		}

		if ( els.registerButton ) {
			els.registerButton.addEventListener( 'click', onRegisterClick );
		}
	} );

	/* ---------------------------------------------------------------- */
	/* Tabs                                                               */
	/* ---------------------------------------------------------------- */

	function initTabs() {
		var buttons = document.querySelectorAll( '.flytedesk-tab-button' );
		buttons.forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				var target = button.getAttribute( 'data-tab' );

				document.querySelectorAll( '.flytedesk-tab-button' ).forEach( function ( b ) {
					b.classList.toggle( 'is-active', b === button );
				} );
				document.querySelectorAll( '.flytedesk-tab-panel' ).forEach( function ( panel ) {
					panel.hidden = panel.getAttribute( 'data-tab-panel' ) !== target;
				} );
			} );
		} );
	}

	/* ---------------------------------------------------------------- */
	/* AJAX                                                               */
	/* ---------------------------------------------------------------- */

	function fetchState( action ) {
		var body = new URLSearchParams();
		body.set( 'action', action );
		body.set( '_ajax_nonce', config.nonce );

		return fetch( config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString(),
		} )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( json ) {
				if ( ! json || ! json.success ) {
					throw new Error( ( json && json.data && json.data.message ) || 'Request failed.' );
				}

				return json.data;
			} );
	}

	/**
	 * Rejected is truly terminal - nothing flytedesk's platform does
	 * afterward can change it, so polling can stop for good. Accepted is
	 * NOT terminal on its own: flytedesk can still go on to actually create/
	 * update/delete content against this site, and the Verified step only
	 * lights up once that happens - so polling must keep running past
	 * Accepted until verification is complete too, or the page would never
	 * pick up that later activity without a manual reload.
	 */
	function isFullyResolved( state ) {
		if ( 'rejected' === state.status ) {
			return true;
		}

		return 'accepted' === state.status && !! ( state.verification && state.verification.all_verified );
	}

	function maybeStartPolling( state ) {
		stopPolling();

		if ( isFullyResolved( state ) ) {
			return;
		}

		if ( els.pollIndicator ) {
			els.pollIndicator.hidden = false;
		}

		pollTimer = window.setInterval( function () {
			fetchState( 'flytedesk_registration_status' )
				.then( function ( state ) {
					renderState( state );
					if ( isFullyResolved( state ) ) {
						stopPolling();
					}
				} )
				.catch( function () {
					// Transient poll failures are silently skipped - the next
					// tick tries again. Nothing actionable for the user here.
				} );
		}, POLL_INTERVAL_MS );
	}

	function stopPolling() {
		if ( pollTimer ) {
			window.clearInterval( pollTimer );
			pollTimer = null;
		}
		if ( els.pollIndicator ) {
			els.pollIndicator.hidden = true;
		}
	}

	/**
	 * The consent gate's only action: records consent and sends the first
	 * registration in one click. On success, reloads the page - the gate
	 * and the full dashboard are different server-rendered shells, so a
	 * reload is simpler and more robust than duplicating the dashboard's
	 * markup here in JS just to swap it in without one.
	 */
	function onGrantConsentClick() {
		var button = els.consentButton;
		button.disabled = true;
		button.classList.add( 'is-loading' );
		setConsentButtonLabel( config.strings.connecting );

		fetchState( 'flytedesk_grant_consent' )
			.then( function () {
				window.location.reload();
			} )
			.catch( function ( error ) {
				renderConsentError( error.message || config.strings.genericError );
				button.disabled = false;
				button.classList.remove( 'is-loading' );
				setConsentButtonLabel( config.strings.connectToFlytedesk );
			} );
	}

	function renderConsentError( message ) {
		var errorBox = document.querySelector( '[data-role="consent-error"]' );
		if ( errorBox ) {
			errorBox.hidden = false;
			errorBox.querySelector( '.flytedesk-notice-text' ).textContent = message;
		}
	}

	function setConsentButtonLabel( text ) {
		if ( ! els.consentButton ) {
			return;
		}
		var label = els.consentButton.querySelector( '.flytedesk-button-label' );
		if ( label ) {
			label.textContent = text;
		}
	}

	function onRegisterClick() {
		var button = els.registerButton;
		button.disabled = true;
		button.classList.add( 'is-loading' );
		setButtonLabel( config.strings.sending );

		fetchState( 'flytedesk_register' )
			.then( function ( state ) {
				renderState( state );
				maybeStartPolling( state );
			} )
			.catch( function ( error ) {
				renderNotice( els.statusPanel, 'error', error.message || config.strings.genericError );
			} )
			.then( function () {
				button.disabled = false;
				button.classList.remove( 'is-loading' );
			} );
	}

	/* ---------------------------------------------------------------- */
	/* Rendering                                                          */
	/* ---------------------------------------------------------------- */

	function renderState( state ) {
		renderTimeline( state );
		renderStatusPanel( state );
		renderTechnicalPanel( state );
		renderVerificationBreakdown( state );
	}

	// Step indices: 0 Pending, 1 Connected, 2 Awaiting Response, 3 Accepted/Rejected, 4 Verified.
	var RESOLVED_STEP_INDEX = 3;
	var VERIFIED_STEP_INDEX = 4;

	function renderTimeline( state ) {
		if ( ! els.timeline ) {
			return;
		}

		var currentIndex = currentTimelineIndex( state );

		var steps = els.timeline.querySelectorAll( '.flytedesk-timeline-step' );
		var connectors = els.timeline.querySelectorAll( '.flytedesk-timeline-connector' );

		steps.forEach( function ( step, index ) {
			step.classList.remove( 'is-complete', 'is-active', 'is-upcoming', 'is-rejected' );

			if ( index < currentIndex ) {
				step.classList.add( 'is-complete' );
			} else if ( index === currentIndex ) {
				if ( 'rejected' === state.status && RESOLVED_STEP_INDEX === index ) {
					step.classList.add( 'is-rejected' );
				} else if ( RESOLVED_STEP_INDEX === index || VERIFIED_STEP_INDEX === index ) {
					// Accepted and Verified are completed EVENTS, not
					// something still being waited on - reaching either one
					// means it already happened, so both read as done
					// (green) immediately. Only Pending/Connected/Awaiting
					// Response are genuinely still waiting on an external
					// actor to do something next, which is what the blue
					// "active" pulse is for.
					step.classList.add( 'is-complete' );
				} else {
					step.classList.add( 'is-active' );
				}
			} else {
				step.classList.add( 'is-upcoming' );
			}

			var icon = step.querySelector( '.flytedesk-timeline-icon' );
			if ( icon ) {
				icon.textContent = iconFor( index, state.status, index < currentIndex || ( index === currentIndex && ( 'accepted' === state.status || 'rejected' === state.status ) ) );
			}

			var label = step.querySelector( '.flytedesk-timeline-label' );
			if ( label && RESOLVED_STEP_INDEX === index ) {
				label.textContent = resolvedStepLabel( state.status );
			}

			var timestamp = step.querySelector( '.flytedesk-timeline-timestamp' );
			if ( timestamp ) {
				timestamp.textContent = timelineTimestampFor( index, state );
			}
		} );

		connectors.forEach( function ( connector, index ) {
			connector.classList.toggle( 'is-complete', index < currentIndex );
		} );
	}

	/**
	 * Pending shows when the most recent registration attempt was actually
	 * sent (`technical.last_attempt_at`), not `timeline.created_at` - the
	 * one-time date this site was first ever added to the workflow. Using
	 * created_at made every re-registration look stuck on a stale date from
	 * however long ago the site was first set up, instead of reading as
	 * "this just happened."
	 */
	function timelineTimestampFor( index, state ) {
		if ( 0 === index ) {
			return state.technical.last_attempt_at || '';
		}
		if ( VERIFIED_STEP_INDEX === index ) {
			return ( state.verification && state.verification.verified_at ) || '';
		}
		// "Awaiting Response" (index 2) has no event of its own - it's just
		// the span of time between connected and resolved.
		var field = [ '', 'ping_received_at', '', 'resolved_at' ][ index ];
		return ( field && state.timeline[ field ] ) || '';
	}

	function currentTimelineIndex( state ) {
		if ( 'accepted' === state.status && state.verification && state.verification.all_verified ) {
			return VERIFIED_STEP_INDEX;
		}
		if ( 'accepted' === state.status || 'rejected' === state.status ) {
			return RESOLVED_STEP_INDEX;
		}
		if ( 'sent' === state.status ) {
			return state.timeline.ping_received_at ? 2 : 1;
		}
		return 0;
	}

	function resolvedStepLabel( status ) {
		if ( 'accepted' === status ) {
			return config.strings.accepted;
		}
		if ( 'rejected' === status ) {
			return config.strings.rejected;
		}
		return config.strings.acceptedRejected;
	}

	function iconFor( stepIndex, status, isDone ) {
		if ( RESOLVED_STEP_INDEX === stepIndex && 'rejected' === status ) {
			return '✕'; // ×
		}
		if ( isDone ) {
			return '✓'; // checkmark
		}
		return String( stepIndex + 1 );
	}

	function renderStatusPanel( state ) {
		if ( ! els.statusPanel ) {
			return;
		}

		var badge = els.statusPanel.querySelector( '.flytedesk-status-badge' );
		if ( badge ) {
			badge.className = 'flytedesk-status-badge is-' + state.status;
			var label = badge.querySelector( '.flytedesk-status-badge-label' );
			if ( label ) {
				label.textContent = config.strings.statusLabels[ state.status ] || state.status;
			}
		}

		setField( els.statusPanel, 'site_domain', state.site_domain );
		setField( els.statusPanel, 'verification_token', state.verification_token );
		setField( els.statusPanel, 'api_username', state.api_username );

		var errorBox = els.statusPanel.querySelector( '[data-role="last-error"]' );
		if ( errorBox ) {
			if ( state.last_error ) {
				errorBox.hidden = false;
				errorBox.querySelector( '.flytedesk-notice-text' ).textContent = state.last_error;
			} else {
				errorBox.hidden = true;
			}
		}

		if ( els.registerButton ) {
			setButtonLabel( 'pending' === state.status ? config.strings.registerNow : config.strings.reregister );
		}
	}

	function renderTechnicalPanel( state ) {
		if ( ! els.technicalPanel ) {
			return;
		}

		var tech = state.technical;

		var consentField = els.technicalPanel.querySelector( '[data-field="consent_summary"]' );
		if ( consentField ) {
			consentField.textContent = ( state.consent && state.consent.granted && state.consent.granted_by )
				? state.consent.granted_by + ' on ' + state.consent.granted_at
				: '';
		}

		var attemptField = els.technicalPanel.querySelector( '[data-field="last_attempt_at"]' );
		if ( attemptField ) {
			attemptField.textContent = tech.last_attempt_at || config.strings.noAttemptsYet;
		}

		var statusField = els.technicalPanel.querySelector( '[data-field="last_http_status"]' );
		if ( statusField ) {
			if ( tech.last_attempt_at ) {
				var statusClass = 'is-0';
				if ( tech.last_http_status >= 200 && tech.last_http_status < 300 ) {
					statusClass = 'is-2xx';
				} else if ( tech.last_http_status >= 400 && tech.last_http_status < 500 ) {
					statusClass = 'is-4xx';
				} else if ( tech.last_http_status >= 500 ) {
					statusClass = 'is-5xx';
				}
				statusField.hidden = false;
				statusField.className = 'flytedesk-tech-http-status ' + statusClass;
				statusField.textContent = tech.last_http_status > 0 ? 'HTTP ' + tech.last_http_status : config.strings.networkError;
			} else {
				statusField.hidden = true;
			}
		}

		var requestBlock = els.technicalPanel.querySelector( '[data-field="last_request"]' );
		if ( requestBlock ) {
			requestBlock.textContent = tech.last_attempt_at
				? JSON.stringify( tech.last_request, null, 2 )
				: config.strings.noAttemptsYet;
			requestBlock.classList.toggle( 'is-empty', ! tech.last_attempt_at );
		}

		var responseBlock = els.technicalPanel.querySelector( '[data-field="last_response_body"]' );
		if ( responseBlock ) {
			responseBlock.textContent = tech.last_response_body || config.strings.emptyResponseBody;
			responseBlock.classList.toggle( 'is-empty', ! tech.last_response_body );
		}
	}

	function renderVerificationBreakdown( state ) {
		if ( ! els.verificationBreakdown || ! state.verification ) {
			return;
		}

		[ 'create', 'update', 'delete' ].forEach( function ( key ) {
			var item = els.verificationBreakdown.querySelector( '[data-verification="' + key + '"]' );
			if ( ! item ) {
				return;
			}

			var verifiedAt = state.verification[ key + '_at' ];

			item.classList.toggle( 'is-verified', !! verifiedAt );

			var icon = item.querySelector( '.flytedesk-verification-icon' );
			if ( icon ) {
				icon.textContent = verifiedAt ? '✓' : '○';
			}

			var timestamp = item.querySelector( '.flytedesk-verification-timestamp' );
			if ( timestamp ) {
				timestamp.textContent = verifiedAt || config.strings.notYetVerified;
			}
		} );
	}

	function renderNotice( container, type, message ) {
		if ( ! container ) {
			return;
		}
		var errorBox = container.querySelector( '[data-role="last-error"]' );
		if ( errorBox ) {
			errorBox.hidden = false;
			errorBox.className = 'flytedesk-notice is-' + type;
			errorBox.querySelector( '.flytedesk-notice-text' ).textContent = message;
		}
	}

	function setField( container, field, value ) {
		var el = container.querySelector( '[data-field="' + field + '"]' );
		if ( el ) {
			el.textContent = value;
		}
	}

	function setButtonLabel( text ) {
		if ( ! els.registerButton ) {
			return;
		}
		var label = els.registerButton.querySelector( '.flytedesk-button-label' );
		if ( label ) {
			label.textContent = text;
		}
	}
} )();
