/**
 * Masonry layout module (loaded only on pages that use the masonry layout, see docs/decisions.md ADR-10).
 * Progressive enhancement: without this script the layout is a normal responsive grid. With it, items are placed in
 * a grid with 1px rows and span as many rows as their content is high plus the column gap (used as row gap).
 */
( function () {
	'use strict';

	function layout( grid ) {
		var gap = parseFloat( window.getComputedStyle( grid ).columnGap ) || 0;
		var items = grid.children;
		for ( var index = 0; index < items.length; index++ ) {
			var item = items[ index ];
			var content = item.firstElementChild || item;
			var height = content.getBoundingClientRect().height;
			item.style.gridRowEnd = 'span ' + Math.max( 1, Math.ceil( height + gap ) );
		}
	}

	function init( view ) {
		var grid = view.querySelector( '.swp-masonry' );
		if ( ! grid || grid.getAttribute( 'data-swp-ready' ) ) {
			return;
		}
		grid.setAttribute( 'data-swp-ready', '1' );
		var scheduled = false;
		var schedule = function () {
			if ( scheduled ) {
				return;
			}
			scheduled = true;
			window.requestAnimationFrame( function () {
				scheduled = false;
				layout( grid );
			} );
		};
		grid.classList.add( 'is-ready' );
		grid.swpRefresh = function () {
			Array.prototype.forEach.call( grid.children, function ( item ) {
				if ( window.ResizeObserver && grid.swpObserver ) {
					grid.swpObserver.observe( item.firstElementChild || item );
				}
			} );
			schedule();
		};
		// Images change the height of an item when they load.
		grid.addEventListener( 'load', schedule, true );
		if ( 'ResizeObserver' in window ) {
			var observer = new window.ResizeObserver( schedule );
			grid.swpObserver = observer;
			observer.observe( grid );
			Array.prototype.forEach.call( grid.children, function ( item ) {
				observer.observe( item.firstElementChild || item );
			} );
		} else {
			window.addEventListener( 'resize', schedule );
		}
		schedule();
	}

	function initAll( root ) {
		Array.prototype.forEach.call( ( root || document ).querySelectorAll( '.swp-view[data-swp-module="masonry"]' ), init );
	}

	window.SWPostlist = window.SWPostlist || {};
	/** Called after entries were appended (load more): observe and place the new items. */
	function refresh( view ) {
		var grid = view.querySelector( '.swp-masonry' );
		if ( grid && grid.swpRefresh ) {
			grid.swpRefresh();
		}
	}

	window.SWPostlist.masonry = { init: init, initAll: initAll, refresh: refresh };

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', function () {
			initAll( document );
		} );
	} else {
		initAll( document );
	}
}() );
