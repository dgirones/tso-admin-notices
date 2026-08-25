/**
 * TSO Admin Notices Manager - Admin JavaScript
 *
 * Layer 1: PHP wraps notice callbacks in .tsoan-hidden-group (CSS-hidden).
 * Layer 2: JS finds leftover notices (SDK / late inject) and hides them.
 *
 * Count = tracked groups on this screen (PHP wrappers + JS-hidden notices).
 *
 * @package TSO_Admin_Notices
 * @since   1.0.2
 */

/* global tsoanAdminData */
( function () {
	'use strict';

	if ( 'undefined' === typeof tsoanAdminData || '1' !== tsoanAdminData.enabled ) {
		return;
	}

	var NOTICE_SELECTOR = [
		'.notice',
		'.updated',
		'.update-nag',
		'.error',
		'.fs-notice',
		'.rank-math-notice',
		'.wp-helpers-notice',
		'.backuply-backup-nag',
		'.woocommerce-message',
		'.woocommerce-error',
		'.woocommerce-info',
	].join( ',' );

	var SAFE_ANCESTOR = [
		'#dashboard-widgets',
		'.plugins',
		'.plugin-card',
		'.plugin-check',
		'.tsoan-wrap',
		'.tsoan-safe-group',
		'#screen-meta',
	].join( ',' );

	/** @type {HTMLElement[]} */
	var groups = [];

	/** @type {boolean} */
	var isVisible = false;

	/** @type {boolean} */
	var scanPending = false;

	function getSource( el ) {
		return (
			el.getAttribute( 'data-tsoan-source' ) ||
			el.getAttribute( 'data-tso-source' ) ||
			el.getAttribute( 'data-slug' ) ||
			el.getAttribute( 'data-manager-id' ) ||
			'untagged'
		);
	}

	function isWhitelisted( source ) {
		var wl = ( tsoanAdminData.whitelist && tsoanAdminData.whitelist.length )
			? tsoanAdminData.whitelist : [];
		return wl.indexOf( source ) !== -1;
	}

	function collectPhpGroups() {
		document.querySelectorAll( '.tsoan-hidden-group' ).forEach( function ( el ) {
			if ( groups.indexOf( el ) !== -1 ) {
				return;
			}
			if ( el.textContent.trim() === '' ) {
				return;
			}
			el.setAttribute( 'data-tsoan-type', 'php' );
			groups.push( el );
		} );
	}

	function shouldSkipCommon( el ) {
		if ( el.closest( '.tsoan-hidden-group' ) ) {
			return true;
		}
		// First-party / whitelisted notices carry this marker on the element
		// itself, so they stay visible even after WordPress core relocates them
		// out of the .tsoan-safe-group wrapper.
		if ( el.hasAttribute( 'data-tsoan-keep' ) || el.closest( '[data-tsoan-keep]' ) ) {
			return true;
		}
		if ( el.hasAttribute( 'data-tsoan-type' ) || el.hasAttribute( 'data-tso-type' ) ) {
			return true;
		}
		if ( ! el.closest( '#wpbody-content' ) ) {
			return true;
		}
		if ( el.closest( SAFE_ANCESTOR ) ) {
			return true;
		}
		return false;
	}

	function isActionResultNotice( el ) {
		if ( el.id && 0 === el.id.indexOf( 'setting-error-' ) ) {
			return true;
		}
		if ( el.classList.contains( 'inline' ) ) {
			return true;
		}
		return false;
	}

	function isVendorNotice( el ) {
		return (
			el.classList.contains( 'fs-notice' ) ||
			el.classList.contains( 'rank-math-notice' ) ||
			el.classList.contains( 'wp-helpers-notice' ) ||
			el.classList.contains( 'backuply-backup-nag' ) ||
			el.classList.contains( 'woocommerce-message' ) ||
			el.classList.contains( 'woocommerce-error' ) ||
			el.classList.contains( 'woocommerce-info' )
		);
	}

	/**
	 * Promo-position: direct child of #wpbody-content or .wrap (admin_notices area).
	 *
	 * @param {HTMLElement} el
	 * @return {boolean}
	 */
	function isPromoNoticePosition( el ) {
		if ( isActionResultNotice( el ) ) {
			return false;
		}
		var wpbody = document.getElementById( 'wpbody-content' );
		if ( wpbody && el.parentElement === wpbody ) {
			return true;
		}
		var wrap = el.closest( '.wrap' );
		if ( wrap && el.parentElement === wrap ) {
			return true;
		}
		return false;
	}

	/**
	 * @param {HTMLElement} el
	 * @return {boolean} true = skip (leave visible)
	 */
	function shouldSkipNotice( el ) {
		if ( shouldSkipCommon( el ) ) {
			return true;
		}
		if ( isActionResultNotice( el ) ) {
			return true;
		}
		// Vendor / SDK: hide anywhere under #wpbody-content (outside safe zones).
		if ( isVendorNotice( el ) ) {
			return false;
		}
		// Generic .notice: only hide in the classic admin_notices / .wrap promo slot.
		// Nested UI notices stay visible; core notices that PHP did not wrap stay
		// visible unless they sit in that same promo slot (rare for core).
		return ! isPromoNoticePosition( el );
	}

	function isNoticeElement( el ) {
		return el.nodeType === 1 && el.matches( NOTICE_SELECTOR );
	}

	function hideNotice( notice ) {
		if ( notice.textContent.trim() === '' ) {
			return;
		}

		var source = getSource( notice );

		if ( isWhitelisted( source ) ) {
			notice.classList.add( 'tsoan-ok' );
			return;
		}

		notice.setAttribute( 'data-tsoan-type', 'js' );
		notice.setAttribute( 'data-tsoan-source', source );
		notice.setAttribute( 'aria-hidden', 'true' );
		notice.style.setProperty( 'display', 'none', 'important' );

		if ( groups.indexOf( notice ) === -1 ) {
			groups.push( notice );
		}
	}

	function scan() {
		var container = document.getElementById( 'wpbody-content' ) || document.body;

		container.querySelectorAll( NOTICE_SELECTOR ).forEach( function ( el ) {
			if ( ! shouldSkipNotice( el ) ) {
				hideNotice( el );
			}
		} );
	}

	function scheduleScan() {
		if ( scanPending ) {
			return;
		}
		scanPending = true;
		setTimeout( function () {
			scanPending = false;
			scan();
			updateAdminBarLabel();
		}, 50 );
	}

	function startDomObserver() {
		if ( ! window.MutationObserver ) {
			return;
		}

		var container = document.getElementById( 'wpbody-content' ) || document.body;

		var observer = new MutationObserver( function ( mutations ) {
			var needScan = false;
			var needRecount = false;
			var i, j;

			for ( i = 0; i < mutations.length; i++ ) {
				var added = mutations[ i ].addedNodes;
				var removed = mutations[ i ].removedNodes;

				for ( j = 0; j < added.length; j++ ) {
					var anode = added[ j ];
					if ( anode.nodeType !== 1 ) {
						continue;
					}
					if ( anode.hasAttribute && ( anode.hasAttribute( 'data-tsoan-type' ) || anode.hasAttribute( 'data-tso-type' ) ) ) {
						continue;
					}
					if (
						isNoticeElement( anode ) ||
						( anode.querySelector && anode.querySelector( NOTICE_SELECTOR ) )
					) {
						needScan = true;
					}
				}

				for ( j = 0; j < removed.length; j++ ) {
					var rnode = removed[ j ];
					if ( rnode.nodeType !== 1 ) {
						continue;
					}
					var idx = groups.indexOf( rnode );
					if ( idx > -1 ) {
						groups.splice( idx, 1 );
						needRecount = true;
					} else if ( mutations[ i ].target.closest ) {
						var pg = mutations[ i ].target.closest( '.tsoan-hidden-group, .tsoan-revealed' );
						if ( pg ) {
							checkGroupEmpty( pg );
							needRecount = true;
						}
					}
				}
			}

			if ( needScan ) {
				scheduleScan();
			} else if ( needRecount ) {
				updateAdminBarLabel();
			}
		} );

		observer.observe( container, { childList: true, subtree: true } );
	}

	function untrackGroup( group ) {
		var idx = groups.indexOf( group );
		if ( idx > -1 ) {
			groups.splice( idx, 1 );
		}
	}

	function checkGroupEmpty( group ) {
		if ( 'php' === group.getAttribute( 'data-tsoan-type' ) ) {
			if ( 0 === group.querySelectorAll( NOTICE_SELECTOR ).length ) {
				untrackGroup( group );
			}
		} else if ( ! document.body.contains( group ) ) {
			untrackGroup( group );
		}
	}

	function bindDismissListener() {
		document.addEventListener( 'click', function ( e ) {
			var btn = e.target;
			var d = 0;
			while ( btn && d < 4 ) {
				if ( btn.classList && (
					btn.classList.contains( 'notice-dismiss' ) ||
					btn.classList.contains( 'dismiss-notice' ) ||
					btn.classList.contains( 'fs-close' )
				) ) {
					break;
				}
				btn = btn.parentElement;
				d++;
			}
			if ( ! btn || ! btn.classList ) {
				return;
			}
			if ( ! (
				btn.classList.contains( 'notice-dismiss' ) ||
				btn.classList.contains( 'dismiss-notice' ) ||
				btn.classList.contains( 'fs-close' )
			) ) {
				return;
			}

			var notice = btn.closest( NOTICE_SELECTOR );
			if ( ! notice ) {
				return;
			}

			var group = ( 'js' === notice.getAttribute( 'data-tsoan-type' ) )
				? notice
				: notice.closest( '.tsoan-hidden-group, .tsoan-revealed' );
			if ( ! group ) {
				return;
			}

			setTimeout( function () {
				checkGroupEmpty( group );
				updateAdminBarLabel();
			}, 0 );
		}, true );
	}

	function hideGroup( group ) {
		if ( 'js' === group.getAttribute( 'data-tsoan-type' ) ) {
			group.style.setProperty( 'display', 'none', 'important' );
			group.classList.remove( 'tsoan-revealed' );
		} else {
			group.classList.add( 'tsoan-hidden-group' );
			group.classList.remove( 'tsoan-revealed' );
		}
		group.setAttribute( 'aria-hidden', 'true' );
	}

	function showGroup( group ) {
		if ( 'js' === group.getAttribute( 'data-tsoan-type' ) ) {
			group.style.removeProperty( 'display' );
			group.classList.add( 'tsoan-revealed' );
		} else {
			group.classList.remove( 'tsoan-hidden-group' );
			group.classList.add( 'tsoan-revealed' );
		}
		group.removeAttribute( 'aria-hidden' );
	}

	function pruneEmptyGroups() {
		groups = groups.filter( function ( group ) {
			if ( 'php' === group.getAttribute( 'data-tsoan-type' ) ) {
				return group.querySelectorAll( NOTICE_SELECTOR ).length > 0
					|| group.textContent.trim() !== '';
			}
			return document.body.contains( group ) && group.textContent.trim() !== '';
		} );
	}

	function countHiddenNotices() {
		var total = 0;

		groups.forEach( function ( group ) {
			if ( 'php' === group.getAttribute( 'data-tsoan-type' ) ) {
				var nested = group.querySelectorAll( NOTICE_SELECTOR );
				total += nested.length > 0 ? nested.length : 1;
			} else {
				total += 1;
			}
		} );

		return total;
	}

	function updateAdminBarLabel() {
		if ( '1' !== tsoanAdminData.showAdminBar ) {
			return;
		}
		var label = document.getElementById( 'tsoan-ab-label' );
		if ( ! label ) {
			return;
		}

		pruneEmptyGroups();
		var abItem = label.parentElement;
		var count = countHiddenNotices();

		if ( 0 === count ) {
			label.textContent = '\uD83D\uDD14 ' + tsoanAdminData.i18n.noNotices;
			if ( abItem ) {
				abItem.style.opacity = '0.55';
				abItem.style.cursor = 'default';
			}
		} else if ( isVisible ) {
			label.textContent = '\uD83D\uDD14 ' + tsoanAdminData.i18n.hideNotices + ' (' + count + ')';
			if ( abItem ) {
				abItem.style.opacity = '';
				abItem.style.cursor = '';
			}
		} else {
			label.textContent = '\uD83D\uDD15 ' + tsoanAdminData.i18n.hiddenNotices + ': ' + count;
			if ( abItem ) {
				abItem.style.opacity = '';
				abItem.style.cursor = '';
			}
		}
	}

	function toggleGroups() {
		if ( 0 === groups.length ) {
			return;
		}
		isVisible = ! isVisible;
		groups.forEach( function ( g ) {
			if ( isVisible ) {
				showGroup( g );
			} else {
				hideGroup( g );
			}
		} );
		updateAdminBarLabel();
	}

	function bindAdminBarToggle() {
		if ( '1' !== tsoanAdminData.showAdminBar ) {
			return;
		}
		var abNode = document.getElementById( 'wp-admin-bar-tsoan-toggle' );
		if ( ! abNode ) {
			return;
		}
		var anchor = abNode.querySelector( 'a.ab-item' );
		if ( anchor ) {
			anchor.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				toggleGroups();
			} );
		}
	}

	function bindWhitelistSearch() {
		var inp = document.getElementById( 'tsoan-search' );
		var list = document.getElementById( 'tsoan-whitelist-list' );
		if ( ! inp || ! list ) {
			return;
		}
		inp.addEventListener( 'input', function () {
			var q = this.value.toLowerCase().trim();
			list.querySelectorAll( '.tsoan-whitelist-item' ).forEach( function ( item ) {
				var name = item.getAttribute( 'data-name' ) || '';
				item.style.display = ( ! q || name.indexOf( q ) >= 0 ) ? '' : 'none';
			} );
		} );
	}

	function runScanCycle() {
		collectPhpGroups();
		scan();
		updateAdminBarLabel();
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		runScanCycle();
		setTimeout( runScanCycle, 100 );
		setTimeout( runScanCycle, 500 );
		bindAdminBarToggle();
		bindDismissListener();
		startDomObserver();
		bindWhitelistSearch();
	} );

	window.addEventListener( 'load', runScanCycle );
} )();
