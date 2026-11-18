/**
 * Dynamic views (loaded only on pages with filters or AJAX, "load more" and infinite pagination).
 * Progressive enhancement: filters are a GET form and pagination consists of links, both work without this script. The
 * module reloads the view through the public route (views/{id}/render), keeps the URL in sync, announces results to
 * assistive technology and falls back to normal navigation on errors.
 */
( function () {
	'use strict';

	var config = window.SWPostlist || {};
	var i18n = config.i18n || {};
	var states = [];

	function text( key, fallback, number ) {
		return ( i18n[ key ] || fallback ).replace( '%d', String( number ) );
	}

	function reducedMotion() {
		return typeof window.matchMedia === 'function' && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
	}

	function cleanQuery( query ) {
		var params = new URLSearchParams( query );
		var keep = new URLSearchParams();
		params.forEach( function ( value, name ) {
			if ( '' !== value ) {
				keep.append( name, value );
			}
		} );
		var out = keep.toString();
		return out ? '?' + out : '';
	}

	/**
	 * The page query with the state of one instance replaced by the one in `query`. Links and form fields were
	 * rendered earlier and may carry outdated state of the other instances; the address bar is the source of truth.
	 */
	function merge( state, query ) {
		var prefix = 'swp[' + state.key + ']';
		var current = new URLSearchParams( window.location.search );
		var merged = new URLSearchParams();
		current.forEach( function ( value, name ) {
			if ( 0 !== name.indexOf( prefix ) ) {
				merged.append( name, value );
			}
		} );
		new URLSearchParams( query ).forEach( function ( value, name ) {
			if ( 0 === name.indexOf( prefix ) ) {
				merged.append( name, value );
			}
		} );
		return cleanQuery( merged.toString() );
	}

	function container( view ) {
		var item = view.querySelector( '.swp-item' );
		return item ? item.parentElement : null;
	}

	function announce( state, message ) {
		state.status.textContent = '';
		// A changed text is what makes the live region speak.
		window.setTimeout( function () {
			state.status.textContent = message;
		}, 50 );
	}

	function setBusy( state, busy ) {
		state.busy = busy;
		state.view.classList.toggle( 'is-loading', busy );
		if ( busy ) {
			state.view.setAttribute( 'aria-busy', 'true' );
		} else {
			state.view.removeAttribute( 'aria-busy' );
		}
	}

	function clearNotice( view ) {
		var notice = view.querySelector( ':scope > .swp-notice' );
		if ( notice ) {
			notice.remove();
		}
	}

	function showError( state, retry ) {
		clearNotice( state.view );
		var notice = document.createElement( 'div' );
		notice.className = 'swp-notice';
		notice.setAttribute( 'role', 'alert' );
		var message = document.createElement( 'p' );
		message.textContent = text( 'error', 'The list could not be loaded.' );
		var button = document.createElement( 'button' );
		button.type = 'button';
		button.className = 'swp-retry';
		button.textContent = text( 'retry', 'Try again' );
		button.addEventListener( 'click', retry );
		notice.appendChild( message );
		notice.appendChild( button );
		state.view.insertBefore( notice, state.view.firstChild );
	}

	/** Keeps the focused filter form while its siblings are replaced; only the selection marks are refreshed. */
	function syncForm( oldForm, newForm ) {
		var oldButtons = oldForm.querySelectorAll( '.swp-filter__button' );
		var newButtons = newForm.querySelectorAll( '.swp-filter__button' );
		if ( oldButtons.length === newButtons.length ) {
			Array.prototype.forEach.call( oldButtons, function ( button, index ) {
				var active = newButtons[ index ].classList.contains( 'is-active' );
				button.classList.toggle( 'is-active', active );
				button.setAttribute( 'aria-pressed', active ? 'true' : 'false' );
			} );
		}
		var oldReset = oldForm.querySelector( '.swp-filters__reset' );
		var newReset = newForm.querySelector( '.swp-filters__reset' );
		if ( oldReset && ! newReset ) {
			oldReset.remove();
		} else if ( ! oldReset && newReset ) {
			oldForm.querySelector( '.swp-filters__actions' ).appendChild( newReset );
		} else if ( oldReset && newReset ) {
			oldReset.setAttribute( 'href', newReset.getAttribute( 'href' ) );
		}
	}

	function refreshModules( view ) {
		var modules = window.SWPostlist || {};
		[ 'masonry', 'slider' ].forEach( function ( name ) {
			if ( modules[ name ] ) {
				modules[ name ].init( view );
			}
		} );
	}

	function replaceContent( state, fresh ) {
		var view = state.view;
		var oldForm = view.querySelector( '.swp-filters' );
		var newForm = fresh.querySelector( '.swp-filters' );
		var active = null;
		var selection = null;
		if ( oldForm && newForm && oldForm.contains( document.activeElement ) ) {
			active = document.activeElement;
			selection = 'number' === typeof active.selectionStart ? [ active.selectionStart, active.selectionEnd ] : null;
			syncForm( oldForm, newForm );
			newForm.replaceWith( oldForm );
		}
		view.replaceChildren.apply( view, Array.prototype.slice.call( fresh.childNodes ) );
		if ( active ) {
			// Moving the form blurs the field; the visitor keeps typing where they were.
			active.focus( { preventScroll: true } );
			if ( selection ) {
				active.setSelectionRange( selection[ 0 ], selection[ 1 ] );
			}
		}
		refreshModules( view );
	}

	function appendContent( state, fresh ) {
		var view = state.view;
		var target = container( view );
		var items = fresh.querySelectorAll( '.swp-item' );
		if ( ! target ) {
			replaceContent( state, fresh );
			return;
		}
		Array.prototype.forEach.call( items, function ( item ) {
			target.appendChild( document.importNode( item, true ) );
		} );
		var oldNav = view.querySelector( '.swp-pagination' );
		var newNav = fresh.querySelector( '.swp-pagination' );
		if ( oldNav && newNav ) {
			oldNav.replaceWith( document.importNode( newNav, true ) );
		} else if ( oldNav ) {
			oldNav.remove();
		}
		if ( window.SWPostlist && window.SWPostlist.masonry && window.SWPostlist.masonry.refresh ) {
			window.SWPostlist.masonry.refresh( view );
		}
	}

	function nextQuery( state ) {
		var link = state.view.querySelector( '.swp-pagination a[rel="next"]' );
		return link ? merge( state, new URL( link.getAttribute( 'href' ), window.location.href ).search ) : null;
	}

	/** "Load more" and infinite scroll replace the page links by a button (the links stay for no-JS and as fallback). */
	function prepareLoadMore( state ) {
		var view = state.view;
		if ( 'load_more' !== state.mode && 'infinite' !== state.mode ) {
			return;
		}
		var nav = view.querySelector( '.swp-pagination' );
		var button = view.querySelector( '.swp-load-more' );
		var more = null !== nextQuery( state );
		if ( nav ) {
			nav.hidden = true;
		}
		if ( ! more ) {
			if ( button ) {
				button.remove();
			}
			return;
		}
		if ( ! button ) {
			button = document.createElement( 'button' );
			button.type = 'button';
			button.className = 'swp-load-more';
			button.textContent = text( 'more', 'Load more' );
			if ( nav ) {
				nav.insertAdjacentElement( 'afterend', button );
			} else {
				view.appendChild( button );
			}
		}
		if ( 'infinite' === state.mode && 'IntersectionObserver' in window ) {
			if ( ! state.observer ) {
				state.observer = new window.IntersectionObserver( function ( entries ) {
					if ( entries[ 0 ].isIntersecting && ! state.busy && ! state.failed ) {
						loadNext( state );
					}
				}, { rootMargin: '200px' } );
			}
			state.observer.disconnect();
			state.observer.observe( button );
		}
	}

	function prepareForms( state ) {
		Array.prototype.forEach.call( state.view.querySelectorAll( '.swp-filters' ), function ( form ) {
			form.classList.add( 'swp-filters--live' );
		} );
	}

	function updateUrl( query, push ) {
		var url = window.location.pathname + cleanQuery( query ) + window.location.hash;
		if ( url === window.location.pathname + window.location.search + window.location.hash ) {
			return;
		}
		window.history[ push ? 'pushState' : 'replaceState' ]( { swp: true }, '', url );
	}

	function request( state, query ) {
		var url = config.rest + 'views/' + state.id + '/render?key=' + encodeURIComponent( state.key ) +
			'&query=' + encodeURIComponent( query ) + ( state.token ? '&token=' + encodeURIComponent( state.token ) : '' );
		return window.fetch( url, { signal: state.controller.signal, headers: { Accept: 'application/json' }, credentials: 'same-origin' } )
			.then( function ( response ) {
				return response.json().then( function ( data ) {
					if ( ! response.ok || ! data || data.error || 'string' !== typeof data.html ) {
						throw new Error( 'swp-response' );
					}
					return data;
				} );
			} );
	}

	/**
	 * @param {Object} state Instance state.
	 * @param {string} query Query string of the page for the new state.
	 * @param {Object} options mode: replace|append, history: push|replace|none, focus: boolean.
	 */
	function load( state, query, options ) {
		if ( state.controller ) {
			state.controller.abort();
		}
		state.controller = new window.AbortController();
		var controller = state.controller;
		state.failed = false;
		clearNotice( state.view );
		setBusy( state, true );
		announce( state, text( 'loading', 'Loading…' ) );
		request( state, query ).then( function ( data ) {
			if ( controller !== state.controller ) {
				return;
			}
			var fresh = new window.DOMParser().parseFromString( data.html, 'text/html' ).querySelector( '.swp-view' );
			if ( ! fresh ) {
				throw new Error( 'swp-response' );
			}
			if ( 'append' === options.mode ) {
				appendContent( state, fresh );
			} else {
				replaceContent( state, fresh );
			}
			prepareForms( state );
			prepareLoadMore( state );
			if ( 'none' !== options.history ) {
				updateUrl( query, 'push' === options.history );
			}
			var shown = state.view.querySelectorAll( '.swp-item' ).length;
			announce( state, summary( shown, data.page, options.mode ) );
			if ( options.focus ) {
				state.view.setAttribute( 'tabindex', '-1' );
				state.view.focus( { preventScroll: true } );
				if ( state.view.getBoundingClientRect().top < 0 ) {
					state.view.scrollIntoView( { block: 'start', behavior: reducedMotion() ? 'auto' : 'smooth' } );
				}
			}
			setBusy( state, false );
		} ).catch( function ( error ) {
			if ( controller !== state.controller || ( error && 'AbortError' === error.name ) ) {
				return;
			}
			state.failed = true;
			setBusy( state, false );
			showError( state, function () {
				load( state, query, options );
			} );
			announce( state, text( 'error', 'The list could not be loaded.' ) );
		} );
	}

	function summary( shown, pageNumber, mode ) {
		if ( 0 === shown ) {
			return text( 'empty', 'No entries found.' );
		}
		if ( 'append' !== mode && pageNumber > 1 ) {
			return text( 'page', 'Page %d loaded.', pageNumber );
		}
		return 1 === shown ? text( 'shown_one', '1 entry shown.' ) : text( 'shown', '%d entries shown.', shown );
	}

	function loadNext( state ) {
		var query = nextQuery( state );
		if ( null !== query && ! state.busy ) {
			load( state, query, { mode: 'append', history: 'none', focus: false } );
		}
	}

	function formQuery( form, submitter ) {
		var data;
		try {
			data = new window.FormData( form, submitter || undefined );
		} catch ( error ) {
			data = new window.FormData( form );
			if ( submitter && submitter.name ) {
				data.append( submitter.name, submitter.value );
			}
		}
		var params = new URLSearchParams();
		data.forEach( function ( value, name ) {
			if ( 'string' === typeof value ) {
				params.append( name, value );
			}
		} );
		return cleanQuery( params.toString() );
	}

	function bind( state ) {
		var view = state.view;
		var timer = null;
		view.addEventListener( 'submit', function ( event ) {
			var form = event.target.closest ? event.target.closest( '.swp-filters' ) : null;
			if ( ! form ) {
				return;
			}
			event.preventDefault();
			window.clearTimeout( timer );
			load( state, merge( state, formQuery( form, event.submitter ) ), { mode: 'replace', history: 'push', focus: false } );
		} );
		view.addEventListener( 'change', function ( event ) {
			var form = event.target.closest ? event.target.closest( '.swp-filters' ) : null;
			if ( form && 'search' !== event.target.type ) {
				form.requestSubmit ? form.requestSubmit() : form.dispatchEvent( new window.Event( 'submit', { cancelable: true, bubbles: true } ) );
			}
		} );
		view.addEventListener( 'input', function ( event ) {
			var form = event.target.closest ? event.target.closest( '.swp-filters' ) : null;
			if ( form && 'search' === event.target.type ) {
				window.clearTimeout( timer );
				timer = window.setTimeout( function () {
					load( state, merge( state, formQuery( form ) ), { mode: 'replace', history: 'push', focus: false } );
				}, 400 );
			}
		} );
		view.addEventListener( 'click', function ( event ) {
			var link = event.target.closest ? event.target.closest( '.swp-pagination__link[href]' ) : null;
			if ( link && 'ajax' === state.mode && ! event.defaultPrevented && 0 === event.button && ! ( event.ctrlKey || event.metaKey || event.shiftKey || event.altKey ) ) {
				event.preventDefault();
				load( state, merge( state, new URL( link.getAttribute( 'href' ), window.location.href ).search ), { mode: 'replace', history: 'push', focus: true } );
				return;
			}
			if ( event.target.closest && event.target.closest( '.swp-load-more' ) ) {
				loadNext( state );
			}
		} );
	}

	function init( view ) {
		if ( view.getAttribute( 'data-swp-dynamic' ) || ! config.rest || ! window.fetch || ! window.AbortController ) {
			return;
		}
		view.setAttribute( 'data-swp-dynamic', '1' );
		var status = document.createElement( 'div' );
		status.className = 'swp-status';
		status.setAttribute( 'role', 'status' );
		status.setAttribute( 'aria-live', 'polite' );
		view.insertAdjacentElement( 'beforebegin', status );
		var state = {
			view: view,
			id: Number( view.getAttribute( 'data-swp-view' ) ),
			key: view.getAttribute( 'data-swp-instance' ) || '',
			token: view.getAttribute( 'data-swp-token' ) || '',
			mode: view.getAttribute( 'data-swp-mode' ) || 'classic',
			status: status,
			controller: null,
			busy: false,
			failed: false,
			observer: null
		};
		states.push( state );
		bind( state );
		prepareForms( state );
		prepareLoadMore( state );
	}

	function initAll( root ) {
		Array.prototype.forEach.call( ( root || document ).querySelectorAll( '.swp-view[data-swp-mode]' ), init );
	}

	// Browser back and forward restore the state from the URL for every dynamic view of the page.
	window.addEventListener( 'popstate', function () {
		states.forEach( function ( state ) {
			load( state, window.location.search, { mode: 'replace', history: 'none', focus: false } );
		} );
	} );

	window.SWPostlist = window.SWPostlist || {};
	window.SWPostlist.dynamic = { init: init, initAll: initAll };

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', function () {
			initAll( document );
		} );
	} else {
		initAll( document );
	}
}() );
