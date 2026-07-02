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

	var spine = data.chapters;
	var settings = data.settings || {};

	// The reader can drop to a plain one-chapter view; that choice is remembered
	// per book across visits. When opted out, leave the normal single-chapter
	// page untouched and just offer a way back into full-book view.
	if ( isOptedOut() ) {
		offerFullBook();
		return;
	}
	if ( ! ( 'IntersectionObserver' in window ) ) {
		return; // Degrade to the normal single-chapter page.
	}

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
	rebreadcrumb();
	buildSlots();
	observeSlots();
	buildSidebar();
	trackPosition();
	onFrame();

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

	// The server renders the entry chapter's own breadcrumb trail; in full-book
	// view swap it for the book's trail (ending at the book). Client-side so the
	// plain single-chapter fallback keeps the chapter trail.
	function rebreadcrumb() {
		if ( ! data.bookCrumbs ) {
			return;
		}
		var nav = document.querySelector( '.sheaf-breadcrumbs' );
		if ( nav ) {
			nav.outerHTML = data.bookCrumbs;
		}
	}

	/* ----------------------------------------------------------- view opt-out -- */

	function storageKey() {
		return 'sheaf-scroll-optout-' + data.bookId;
	}

	function isOptedOut() {
		try {
			return '1' === window.localStorage.getItem( storageKey() );
		} catch ( e ) {
			return false;
		}
	}

	function setOptedOut( on ) {
		try {
			if ( on ) {
				window.localStorage.setItem( storageKey(), '1' );
			} else {
				window.localStorage.removeItem( storageKey() );
			}
		} catch ( e ) {}
	}

	// Leave full-book view for a plain single chapter: remember the choice and
	// reload the chapter the reader is currently on.
	function enterSingleChapter() {
		setOptedOut( true );
		window.location.href = spine[ activeIndex ].url;
	}

	// Return to full-book view and reload so the reader stitches the page.
	function enterFullBook() {
		setOptedOut( false );
		window.location.reload();
	}

	// On an opted-out (plain) chapter, drop in a small control to re-enter
	// full-book view. The rest of the page is the theme's normal render.
	function offerFullBook() {
		var bar = document.createElement( 'div' );
		bar.className = 'sheaf-view-toggle';
		var btn = document.createElement( 'button' );
		btn.type = 'button';
		btn.className = 'sheaf-view-toggle__btn';
		btn.textContent = 'Read the whole book';
		btn.addEventListener( 'click', enterFullBook );
		bar.appendChild( btn );
		document.body.appendChild( bar );
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
		// current. Once the slot it landed on loads, catch up — on the next
		// frame, after this insertion's layout (and any sibling loads in the
		// same jump) have settled.
		window.requestAnimationFrame( onFrame );
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

	/* ------------------------------------------------ position + tracking -- */

	function trackPosition() {
		var ticking = false;
		window.addEventListener( 'scroll', function () {
			if ( ! ticking ) {
				ticking = true;
				window.requestAnimationFrame( function () {
					ticking = false;
					onFrame();
				} );
			}
		}, { passive: true } );
	}

	// Where the reader is: the loaded chapter crossing a line a third of the way
	// down the viewport (or, near the ends or mid-load, the nearest loaded one),
	// plus how far through that chapter we are, 0..1.
	function currentPosition() {
		var line = window.innerHeight * 0.33;
		var best = -1;
		var bestDist = Infinity;
		var bestRect = null;
		for ( var i = 0; i < slots.length; i++ ) {
			var s = slots[ i ];
			if ( ! s || ! s.loaded ) {
				continue;
			}
			var r = s.el.getBoundingClientRect();
			if ( r.top <= line && r.bottom > line ) {
				best = i;
				bestRect = r;
				break;
			}
			var dist = r.top > line ? r.top - line : line - r.bottom;
			if ( dist < bestDist ) {
				bestDist = dist;
				best = i;
				bestRect = r;
			}
		}
		if ( best < 0 ) {
			return null;
		}
		var span = bestRect.bottom - bestRect.top;
		var frac = span > 0 ? ( line - bestRect.top ) / span : 0;
		frac = frac < 0 ? 0 : ( frac > 1 ? 1 : frac );
		return { index: best, fraction: frac };
	}

	// Run once per animation frame while scrolling (and after a load settles):
	// move the URL to the current chapter and refresh the position sidebar.
	function onFrame() {
		var pos = currentPosition();
		if ( ! pos ) {
			return;
		}
		applyActive( pos.index );
		updateSidebar( pos );
	}

	// Reflect the current chapter in the URL and document title without a reload.
	function applyActive( index ) {
		if ( index === activeIndex ) {
			return;
		}
		activeIndex = index;
		try {
			history.replaceState( history.state, '', spine[ index ].url );
		} catch ( e ) {}
		if ( spine[ index ].title ) {
			document.title = spine[ index ].title;
		}
		highlightToc( index );
	}

	/* --------------------------------------------------------- sidebar -- */

	var sidebar, pageEl, chapterEl, timeEl, tocNav, tocLinks;

	// A fixed column in the left margin showing where the reader is: the pseudo
	// page (if enabled) and either the current chapter + time to the next, or a
	// full table of contents that tracks the current chapter. It sits beside the
	// content and hides itself when the margin is too narrow (mobile is a later
	// phase). Built here, not server-side, so it stays theme-agnostic.
	function buildSidebar() {
		sidebar = document.createElement( 'aside' );
		sidebar.className = 'sheaf-rail';
		sidebar.setAttribute( 'aria-label', 'Reading position' );

		if ( settings.showPageNumbers ) {
			pageEl = document.createElement( 'div' );
			pageEl.className = 'sheaf-rail__page';
			sidebar.appendChild( pageEl );
		}

		if ( settings.showFullToc ) {
			sidebar.appendChild( buildToc() );
		} else {
			var here = document.createElement( 'div' );
			here.className = 'sheaf-rail__here';
			chapterEl = document.createElement( 'div' );
			chapterEl.className = 'sheaf-rail__chapter';
			timeEl = document.createElement( 'div' );
			timeEl.className = 'sheaf-rail__time';
			here.appendChild( chapterEl );
			here.appendChild( timeEl );
			sidebar.appendChild( here );
		}

		var toggle = document.createElement( 'button' );
		toggle.type = 'button';
		toggle.className = 'sheaf-rail__toggle';
		toggle.textContent = 'Read one chapter at a time';
		toggle.addEventListener( 'click', enterSingleChapter );
		sidebar.appendChild( toggle );

		document.body.appendChild( sidebar );
		positionSidebar();
		highlightToc( activeIndex ); // Mark the landing chapter before any scroll.
		window.addEventListener( 'resize', positionSidebar, { passive: true } );
	}

	function buildToc() {
		tocNav = document.createElement( 'nav' );
		tocNav.className = 'sheaf-rail__toc';
		tocNav.setAttribute( 'aria-label', 'Table of contents' );
		tocLinks = [];
		for ( var i = 0; i < spine.length; i++ ) {
			var a = document.createElement( 'a' );
			a.className = 'sheaf-rail__toc-item' + ( spine[ i ].isSection ? ' sheaf-rail__toc-item--section' : '' );
			a.href = spine[ i ].url;
			a.textContent = spine[ i ].title;
			a.setAttribute( 'data-index', i );
			a.addEventListener( 'click', onTocClick );
			tocNav.appendChild( a );
			tocLinks.push( a );
		}
		return tocNav;
	}

	// A TOC click scrolls to the chapter in place (its slot always has a
	// position, loaded or not) rather than reloading the whole page.
	function onTocClick( e ) {
		var idx = parseInt( this.getAttribute( 'data-index' ), 10 );
		if ( isNaN( idx ) || ! slots[ idx ] ) {
			return; // Let the browser follow the href.
		}
		e.preventDefault();
		var top = slots[ idx ].el.getBoundingClientRect().top + window.scrollY;
		window.scrollTo( 0, top );
	}

	function highlightToc( index ) {
		if ( ! tocLinks ) {
			return;
		}
		for ( var i = 0; i < tocLinks.length; i++ ) {
			var on = i === index;
			tocLinks[ i ].classList.toggle( 'is-current', on );
			if ( on ) {
				tocLinks[ i ].setAttribute( 'aria-current', 'true' );
			} else {
				tocLinks[ i ].removeAttribute( 'aria-current' );
			}
		}
		// Slide a taller-than-viewport TOC so the current chapter stays in view.
		if ( tocNav && tocNav.scrollHeight > tocNav.clientHeight ) {
			var a = tocLinks[ index ];
			if ( a ) {
				tocNav.scrollTop = a.offsetTop - ( tocNav.clientHeight - a.offsetHeight ) / 2;
			}
		}
	}

	function updateSidebar( pos ) {
		if ( ! sidebar ) {
			return;
		}
		var ch = spine[ pos.index ];
		if ( pageEl ) {
			pageEl.textContent = 'Page ' + currentPage( pos ) + ' of ' + data.totalPages;
		}
		if ( chapterEl ) {
			chapterEl.textContent = ch.title;
		}
		if ( timeEl ) {
			timeEl.textContent = timeToNext( pos );
		}
	}

	// The pseudo page the reader is on: the chapter's start page plus how far
	// through its own pages they have scrolled.
	function currentPage( pos ) {
		var ch = spine[ pos.index ];
		var page = ch.startPage || 1;
		if ( ch.pages > 0 ) {
			page += Math.min( ch.pages - 1, Math.floor( pos.fraction * ch.pages ) );
		}
		return page;
	}

	// Reading time remaining before the next chapter (i.e. left in this one).
	function timeToNext( pos ) {
		var ch = spine[ pos.index ];
		var remaining = Math.round( ( 1 - pos.fraction ) * ( ch.minutes || 0 ) );
		if ( remaining < 1 && ch.minutes > 0 ) {
			remaining = 1;
		}
		if ( remaining <= 0 ) {
			return '';
		}
		var last = pos.index >= spine.length - 1;
		return remaining + ( last ? ' min left' : ' min to next chapter' );
	}

	// Park the sidebar in the left margin, just left of the content column;
	// hide it when that margin is too narrow to hold it.
	function positionSidebar() {
		if ( ! sidebar ) {
			return;
		}
		// Measure the constrained text column (the chapter element), not its
		// full-width container: block themes centre children to contentSize, so
		// the container's left edge is ~0 while the readable column is inset.
		var contentLeft = currentEl.getBoundingClientRect().left;
		var gap = 24;
		var left = contentLeft - gap - sidebar.offsetWidth;
		if ( left < 8 ) {
			sidebar.classList.add( 'sheaf-rail--hidden' );
		} else {
			sidebar.classList.remove( 'sheaf-rail--hidden' );
			sidebar.style.left = Math.round( left ) + 'px';
		}
	}
}() );
