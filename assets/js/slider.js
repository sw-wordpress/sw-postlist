/**
 * Slider/carousel module (loaded only on pages that use the slider layout, see docs/decisions.md ADR-10).
 * Progressive enhancement of a native scroll-snap track: swipe and scroll work without this script. The module adds
 * previous/next buttons, dots, keyboard control, optional autoplay with a pause button and ARIA roles.
 * Reduced motion: no autoplay and no smooth scrolling.
 */
( function () {
	'use strict';

	var DEFAULTS = { autoplay: false, interval: 6000, loop: false, arrows: true, dots: true };
	var i18n = ( window.SWPostlist && window.SWPostlist.i18n ) || {};
	var text = function ( key, fallback ) {
		return i18n[ key ] || fallback;
	};

	function readOptions( view ) {
		var options = {};
		var parsed = {};
		try {
			parsed = JSON.parse( view.getAttribute( 'data-swp-options' ) || '{}' ) || {};
		} catch ( error ) {
			parsed = {};
		}
		Object.keys( DEFAULTS ).forEach( function ( key ) {
			options[ key ] = typeof parsed[ key ] === typeof DEFAULTS[ key ] ? parsed[ key ] : DEFAULTS[ key ];
		} );
		options.interval = Math.min( 20000, Math.max( 2000, options.interval ) );
		return options;
	}

	function button( label, className, onClick ) {
		var element = document.createElement( 'button' );
		element.type = 'button';
		element.className = 'swp-slider__button ' + className;
		element.setAttribute( 'aria-label', label );
		element.addEventListener( 'click', onClick );
		return element;
	}

	function init( view ) {
		var track = view.querySelector( '.swp-slider__track' );
		if ( ! track || track.getAttribute( 'data-swp-ready' ) ) {
			return;
		}
		track.setAttribute( 'data-swp-ready', '1' );
		var options = readOptions( view );
		var reduced = typeof window.matchMedia === 'function' && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
		var autoplay = options.autoplay && ! reduced;
		var slides = Array.prototype.slice.call( track.children );
		var current = 0;
		var timer = null;
		var userPaused = false;
		var hovering = false;

		slides.forEach( function ( slide, index ) {
			slide.setAttribute( 'role', 'group' );
			slide.setAttribute( 'aria-roledescription', 'slide' );
			slide.setAttribute( 'aria-label', ( index + 1 ) + ' / ' + slides.length );
		} );

		var controls = document.createElement( 'div' );
		controls.className = 'swp-slider__controls';
		var dots = [];
		var previous = null;
		var next = null;
		var pause = null;

		function show( index, behavior ) {
			if ( slides.length === 0 ) {
				return;
			}
			var target = index;
			if ( target < 0 ) {
				target = options.loop ? slides.length - 1 : 0;
			} else if ( target >= slides.length ) {
				target = options.loop ? 0 : slides.length - 1;
			}
			current = target;
			track.scrollTo( { left: slides[ target ].offsetLeft - track.offsetLeft, behavior: reduced ? 'auto' : behavior || 'smooth' } );
			update();
		}

		function update() {
			dots.forEach( function ( dot, index ) {
				if ( index === current ) {
					dot.setAttribute( 'aria-current', 'true' );
				} else {
					dot.removeAttribute( 'aria-current' );
				}
			} );
			if ( previous ) {
				previous.disabled = ! options.loop && current <= 0;
			}
			if ( next ) {
				next.disabled = ! options.loop && current >= slides.length - 1;
			}
		}

		function stop() {
			if ( timer ) {
				window.clearInterval( timer );
				timer = null;
			}
		}

		function start() {
			stop();
			if ( ! autoplay || userPaused || hovering || document.hidden ) {
				track.setAttribute( 'aria-live', 'polite' );
				return;
			}
			track.setAttribute( 'aria-live', 'off' );
			timer = window.setInterval( function () {
				show( current + 1 >= slides.length ? 0 : current + 1 );
			}, options.interval );
		}

		if ( options.arrows ) {
			previous = button( text( 'previous', 'Previous slide' ), 'swp-slider__prev', function () {
				show( current - 1 );
			} );
			next = button( text( 'next', 'Next slide' ), 'swp-slider__next', function () {
				show( current + 1 );
			} );
			controls.appendChild( previous );
			controls.appendChild( next );
		}
		if ( autoplay ) {
			pause = button( text( 'pause', 'Pause autoplay' ), 'swp-slider__pause', function () {
				userPaused = ! userPaused;
				pause.setAttribute( 'aria-label', userPaused ? text( 'play', 'Start autoplay' ) : text( 'pause', 'Pause autoplay' ) );
				pause.setAttribute( 'aria-pressed', userPaused ? 'true' : 'false' );
				start();
			} );
			pause.setAttribute( 'aria-pressed', 'false' );
			controls.appendChild( pause );
		}
		if ( options.dots && slides.length > 1 ) {
			var list = document.createElement( 'div' );
			list.className = 'swp-slider__dots';
			slides.forEach( function ( slide, index ) {
				var dot = button( ( index + 1 ) + ' / ' + slides.length, 'swp-slider__dot', function () {
					show( index );
				} );
				dots.push( dot );
				list.appendChild( dot );
			} );
			controls.appendChild( list );
		}
		if ( controls.children.length > 0 ) {
			track.parentNode.appendChild( controls );
		}

		track.addEventListener( 'keydown', function ( event ) {
			var handled = true;
			if ( 'ArrowRight' === event.key ) {
				show( current + 1 );
			} else if ( 'ArrowLeft' === event.key ) {
				show( current - 1 );
			} else if ( 'Home' === event.key ) {
				show( 0 );
			} else if ( 'End' === event.key ) {
				show( slides.length - 1 );
			} else {
				handled = false;
			}
			if ( handled ) {
				event.preventDefault();
			}
		} );

		// Keep the position in sync when the visitor swipes or scrolls instead of using the controls.
		var settle = null;
		track.addEventListener( 'scroll', function () {
			window.clearTimeout( settle );
			settle = window.setTimeout( function () {
				var best = 0;
				var distance = Infinity;
				slides.forEach( function ( slide, index ) {
					var delta = Math.abs( slide.offsetLeft - track.offsetLeft - track.scrollLeft );
					if ( delta < distance ) {
						distance = delta;
						best = index;
					}
				} );
				current = best;
				update();
			}, 100 );
		} );

		view.addEventListener( 'mouseenter', function () {
			hovering = true;
			start();
		} );
		view.addEventListener( 'mouseleave', function () {
			hovering = false;
			start();
		} );
		view.addEventListener( 'focusin', function () {
			hovering = true;
			start();
		} );
		view.addEventListener( 'focusout', function () {
			hovering = false;
			start();
		} );
		document.addEventListener( 'visibilitychange', start );

		update();
		start();
	}

	function initAll( root ) {
		Array.prototype.forEach.call( ( root || document ).querySelectorAll( '.swp-view[data-swp-module="slider"]' ), init );
	}

	window.SWPostlist = window.SWPostlist || {};
	window.SWPostlist.slider = { init: init, initAll: initAll };

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', function () {
			initAll( document );
		} );
	} else {
		initAll( document );
	}
}() );
