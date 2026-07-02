/**
 * Full-book scrolling reader (core engine).
 *
 * Enhances a single chapter view into a continuous scroll through the whole
 * book. Chapters load on demand as the reader approaches them (a fragment fetch
 * of the chapter's real canonical URL — so server logs still count each view),
 * and unload again once far from the viewport, leaving a height-preserving
 * spacer so the scrollbar never lurches. The location bar tracks the chapter at
 * the top of the viewport.
 *
 * Data comes from window.SheafScroll (see Frontend::build_spine). No build step,
 * no framework — vanilla ES5-ish so it runs unminified anywhere.
 */
( function () {
	'use strict';

	var data = window.SheafScroll;
	if ( ! data || ! data.chapters || data.chapters.length < 2 ) {
		return; // Nothing to stitch.
	}
	if ( ! ( 'IntersectionObserver' in window ) ) {
		return; // Degrade to the normal single-chapter page.
	}

	var spine = data.chapters;
	var settings = data.settings || {};

	// The server-rendered chapter this page loaded as.
	var currentEl = document.querySelector( '.sheaf-chapter[data-chapter-id="' + data.currentId + '"]' )
		|| document.querySelector( '.sheaf-chapter' );
	if ( ! currentEl ) {
		return;
	}

	var currentIndex = indexOfId( data.currentId );
	if ( currentIndex < 0 ) {
		return;
	}

	var rail = currentEl.parentNode;
	var slots = new Array( spine.length );
	var activeIndex = currentIndex;
	var pxPerWord = estimatePxPerWord();

	// How far outside the viewport (top and bottom) a chapter stays loaded.
	var BAND = '1200px 0px';

	document.body.classList.add( 'sheaf-scroll-active' );
	retitleForBook();
	buildSlots();
	observeSlots();
	trackPosition();

	// In full-book view the page represents the whole book, so its heading
	// should name the book — the entry chapter's own title now renders in-flow
	// (see buildSlots/titleFor). Done in JS to stay theme-agnostic: the heading
	// is the theme's post-title element, not markup Sheaf controls.
	function retitleForBook() {
		if ( ! data.bookTitle ) {
			return;
		}
		var heading = document.querySelector(
			'h1.wp-block-post-title, h1.entry-title, .wp-block-post-title, .entry-title'
		);
		if ( heading ) {
			heading.textContent = data.bookTitle;
		}
	}

	/* --------------------------------------------------------- spine utils -- */

	function indexOfId( id ) {
		for ( var i = 0; i < spine.length; i++ ) {
			if ( spine[ i ].id === id ) {
				return i;
			}
		}
		return -1;
	}

	// Pixels of rendered height per word, learned from the loaded chapter, used
	// to estimate the height of chapters that haven't loaded yet.
	function estimatePxPerWord() {
		var words = spine[ currentIndex ] ? spine[ currentIndex ].words : 0;
		var height = currentEl.offsetHeight;
		if ( words > 0 && height > 0 ) {
			return height / words;
		}
		return 0.9; // Reasonable fallback for prose at a typical measure.
	}

	function estimatedHeight( i ) {
		return Math.max( 200, Math.round( ( spine[ i ].words || 0 ) * pxPerWord ) );
	}

	/* ------------------------------------------------------- scroll anchor -- */

	// Run a DOM mutation without letting the anchor element move on screen, so
	// inserting/removing content above the viewport never jolts the reader.
	function preserveScroll( anchor, mutate ) {
		if ( ! anchor ) {
			mutate();
			return;
		}
		var before = anchor.getBoundingClientRect().top;
		mutate();
		var delta = anchor.getBoundingClientRect().top - before;
		if ( delta ) {
			window.scrollBy( 0, delta );
		}
	}

	// A loaded chapter currently crossing the viewport, to anchor mutations to.
	function viewportAnchor() {
		for ( var i = 0; i < slots.length; i++ ) {
			var s = slots[ i ];
			if ( s && s.loaded ) {
				var r = s.el.getBoundingClientRect();
				if ( r.bottom > 0 && r.top < window.innerHeight ) {
					return s.el;
				}
			}
		}
		return currentEl;
	}

	/* ------------------------------------------------------- slot building -- */

	function makeSlot( i ) {
		var slot = document.createElement( 'div' );
		slot.className = 'sheaf-slot';
		slot.setAttribute( 'data-index', i );
		slot.setAttribute( 'data-chapter-id', spine[ i ].id );
		slot.setAttribute( 'data-loaded', '0' );
		return slot;
	}

	// The break that precedes chapter i (none before the first). A section's
	// special break is chosen by the *previous* chapter being a section.
	function breakBefore( i ) {
		if ( i <= 0 ) {
			return null;
		}
		var prev = spine[ i - 1 ];
		var useSection = settings.specialSectionBreaks && prev.isSection;
		var type = ( useSection ? settings.sectionBreak : settings.chapterBreak ) || 'none';
		var html = useSection ? settings.sectionBreakHtml : settings.chapterBreakHtml;

		var el = document.createElement( 'div' );
		el.className = 'sheaf-break sheaf-break--' + String( type ).replace( /_/g, '-' );
		if ( 'hr' === type || 'hr_page_break' === type ) {
			el.innerHTML = html || '<hr>';
		}
		return el;
	}

	// A rendered title for a spliced chapter (the landing chapter keeps the
	// theme's own title). Sections always show theirs; chapters obey the setting.
	function titleFor( i ) {
		if ( ! settings.chapterTitles && ! spine[ i ].isSection ) {
			return null;
		}
		var h = document.createElement( 'h2' );
		h.className = 'sheaf-chapter-title' + ( spine[ i ].isSection ? ' sheaf-chapter-title--section' : '' );
		h.textContent = spine[ i ].title;
		return h;
	}

	function buildSlots() {
		preserveScroll( currentEl, function () {
			// Wrap the existing current chapter in its slot, giving it the same
			// in-flow break + title a spliced chapter gets so its title sits at
			// its start rather than being left as the (now book-titled) page <h1>.
			var cur = makeSlot( currentIndex );
			cur.setAttribute( 'data-loaded', '1' );
			rail.insertBefore( cur, currentEl );
			var landingBreak = breakBefore( currentIndex );
			if ( landingBreak ) {
				cur.appendChild( landingBreak );
			}
			var landingTitle = titleFor( currentIndex );
			if ( landingTitle ) {
				cur.appendChild( landingTitle );
			}
			cur.appendChild( currentEl );
			slots[ currentIndex ] = { el: cur, loaded: true };

			// Later chapters, appended in order after the current slot.
			for ( var i = currentIndex + 1; i < spine.length; i++ ) {
				var after = makeSlot( i );
				after.style.minHeight = estimatedHeight( i ) + 'px';
				rail.appendChild( after );
				slots[ i ] = { el: after, loaded: false };
			}

			// Earlier chapters, inserted in order directly before the current slot.
			for ( var j = 0; j < currentIndex; j++ ) {
				var before = makeSlot( j );
				before.style.minHeight = estimatedHeight( j ) + 'px';
				rail.insertBefore( before, cur );
				slots[ j ] = { el: before, loaded: false };
			}
		} );
	}

	/* ------------------------------------------------------- load / unload -- */

	function observeSlots() {
		var io = new IntersectionObserver( onIntersect, { rootMargin: BAND } );
		for ( var i = 0; i < slots.length; i++ ) {
			io.observe( slots[ i ].el );
		}
	}

	function onIntersect( entries ) {
		for ( var k = 0; k < entries.length; k++ ) {
			var idx = parseInt( entries[ k ].target.getAttribute( 'data-index' ), 10 );
			var s = slots[ idx ];
			if ( ! s ) {
				continue;
			}
			if ( entries[ k ].isIntersecting ) {
				if ( ! s.loaded && ! s.loading ) {
					loadSlot( idx );
				}
			} else if ( s.loaded && idx !== activeIndex ) {
				unloadSlot( idx );
			}
		}
	}

	function loadSlot( idx ) {
		var s = slots[ idx ];
		if ( ! s || s.loaded || s.loading ) {
			return;
		}
		s.loading = true;
		fetch( spine[ idx ].url, {
			headers: { 'X-Sheaf-Fragment': '1' },
			credentials: 'same-origin'
		} )
			.then( function ( r ) {
				return 200 === r.status ? r.text() : Promise.reject( r.status );
			} )
			.then( function ( html ) {
				fillSlot( idx, html );
			} )
			.catch( function () {
				s.loading = false;
				s.error = true; // Leave the spacer; normal links still work.
			} );
	}

	function fillSlot( idx, html ) {
		var s = slots[ idx ];
		var tmp = document.createElement( 'div' );
		tmp.innerHTML = html;
		var chapter = tmp.querySelector( '.sheaf-chapter' );
		if ( ! chapter ) {
			s.loading = false;
			s.error = true;
			return;
		}

		var frag = document.createDocumentFragment();
		var br = breakBefore( idx );
		if ( br ) {
			frag.appendChild( br );
		}
		var title = titleFor( idx );
		if ( title ) {
			frag.appendChild( title );
		}
		frag.appendChild( chapter );

		preserveScroll( viewportAnchor(), function () {
			s.el.style.minHeight = '';
			s.el.innerHTML = '';
			s.el.appendChild( frag );
			s.el.setAttribute( 'data-loaded', '1' );
		} );

		s.loaded = true;
		s.loading = false;

		// A large jump (Home/End/PageUp-Down, scrollbar click) can land on an
		// unloaded spacer, so the scroll handler finds no loaded chapter to make
		// current. Once the slot it landed on loads, catch the URL up — on the
		// next frame, after this insertion's layout (and any sibling loads in the
		// same jump) have settled.
		window.requestAnimationFrame( updateActive );
	}

	function unloadSlot( idx ) {
		var s = slots[ idx ];
		if ( ! s || ! s.loaded ) {
			return;
		}
		var height = s.el.offsetHeight; // Real height, so the spacer matches exactly.
		preserveScroll( viewportAnchor(), function () {
			s.el.innerHTML = '';
			s.el.style.minHeight = height + 'px';
			s.el.setAttribute( 'data-loaded', '0' );
		} );
		s.loaded = false;
	}

	/* -------------------------------------------------------- URL tracking -- */

	function trackPosition() {
		var ticking = false;
		window.addEventListener( 'scroll', function () {
			if ( ! ticking ) {
				ticking = true;
				window.requestAnimationFrame( function () {
					ticking = false;
					updateActive();
				} );
			}
		}, { passive: true } );
	}

	// The chapter crossing a line a third of the way down the viewport is the
	// one we consider "current"; reflect it in the URL and title without a reload.
	function updateActive() {
		var line = window.innerHeight * 0.33;
		var best = -1;
		var bestDist = Infinity;
		for ( var i = 0; i < slots.length; i++ ) {
			var s = slots[ i ];
			if ( ! s || ! s.loaded ) {
				continue;
			}
			var r = s.el.getBoundingClientRect();
			if ( r.top <= line && r.bottom > line ) {
				best = i;
				break;
			}
			// No chapter straddles the line (e.g. at the very top or bottom of
			// the book, or mid-load): fall back to the loaded chapter nearest it,
			// so the URL always resolves to something visible.
			var dist = r.top > line ? r.top - line : line - r.bottom;
			if ( dist < bestDist ) {
				bestDist = dist;
				best = i;
			}
		}
		if ( best < 0 || best === activeIndex ) {
			return;
		}
		activeIndex = best;
		try {
			history.replaceState( history.state, '', spine[ best ].url );
		} catch ( e ) {}
		if ( spine[ best ].title ) {
			document.title = spine[ best ].title;
		}
	}
}() );
