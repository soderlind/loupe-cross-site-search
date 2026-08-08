import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import view from '../../src/cross-site-search/view.js';

const { h, debounce, initBlock, sanitizeHighlight, fmt } = view;

describe( 'h()', () => {
	it( 'creates an element with class, text, attributes and children', () => {
		const child = h( 'li', { text: 'item' } );
		const el = h( 'ul', { class: 'list', 'data-role': 'results' }, [ child ] );

		expect( el.tagName ).toBe( 'UL' );
		expect( el.className ).toBe( 'list' );
		expect( el.getAttribute( 'data-role' ) ).toBe( 'results' );
		expect( el.children.length ).toBe( 1 );
		expect( el.firstChild.textContent ).toBe( 'item' );
	} );

	it( 'sets innerHTML via the html key', () => {
		const el = h( 'span', { html: '<mark>x</mark>' } );
		expect( el.innerHTML ).toBe( '<mark>x</mark>' );
	} );
} );

describe( 'debounce()', () => {
	beforeEach( () => vi.useFakeTimers() );
	afterEach( () => vi.useRealTimers() );

	it( 'invokes the callback once after the delay', () => {
		const fn = vi.fn();
		const debounced = debounce( fn, 100 );
		debounced();
		debounced();
		debounced();
		expect( fn ).not.toHaveBeenCalled();
		vi.advanceTimersByTime( 100 );
		expect( fn ).toHaveBeenCalledTimes( 1 );
	} );
} );

describe( 'sanitizeHighlight()', () => {
	it( 'keeps allowed inline tags and strips everything else', () => {
		const out = sanitizeHighlight( '<mark>hi</mark><script>alert(1)</script><b onclick="x()">z</b>' );
		expect( out ).toContain( '<mark>hi</mark>' );
		expect( out ).toContain( '<b>z</b>' );
		expect( out ).not.toContain( '<script' );
		expect( out ).not.toContain( 'onclick' );
		expect( out ).toContain( 'alert(1)' ); // unwrapped to text
	} );
} );

describe( 'fmt()', () => {
	it( 'substitutes positional and sequential placeholders', () => {
		expect( fmt( '%1$d results for “%2$s”', [ 3, 'x' ] ) ).toBe( '3 results for “x”' );
		expect( fmt( 'No results for “%s”.', [ 'y' ] ) ).toBe( 'No results for “y”.' );
	} );
} );

describe( 'initBlock()', () => {
	afterEach( () => {
		document.body.innerHTML = '';
		vi.restoreAllMocks();
	} );

	const CONFIG = {
		endpoint: 'https://hub.test/wp-json/loupe-cross-site/v1/search',
		perPage: 2,
		placeholder: 'Search the network…',
		showSiteFilter: true,
		showTypeFilter: true,
		showSort: true,
		defaultSort: 'relevance',
		showExcerpt: true,
		showDate: true,
		highlight: true,
		i18n: {},
	};

	function mountBlock( overrides ) {
		const root = document.createElement( 'div' );
		root.className = 'lcss-search';
		root.setAttribute( 'data-config', JSON.stringify( Object.assign( {}, CONFIG, overrides ) ) );
		document.body.appendChild( root );
		return root;
	}

	function responseData() {
		return {
			hits: [
				{ title: 'Alpha', url: 'https://a.test/alpha', blog_name: 'Site A', post_type: 'post', post_type_label: 'Post', date: '2025-01-02 10:00:00', excerpt: 'first', _formatted: { post_title: '<mark>Al</mark>pha', post_content: 'a <mark>match</mark> here' } },
				{ title: 'Beta', url: 'https://b.test/beta', blog_name: 'Site B', post_type: 'page', post_type_label: 'Page', date: '2025-01-01 10:00:00', excerpt: 'second' },
			],
			facets: {
				blog_name: { type: 'terms', buckets: [ { value: 'Site A', count: 2 }, { value: 'Site B', count: 1 } ] },
				post_type: { type: 'terms', buckets: [ { value: 'post', count: 2 }, { value: 'page', count: 1 } ] },
			},
			pagination: { total: 3, per_page: 2, current_page: 1, total_pages: 2 },
			tookMs: 5,
		};
	}

	it( 'queries the endpoint and renders results, facets, highlighting and pager', async () => {
		global.fetch = vi.fn( () => Promise.resolve( { json: () => Promise.resolve( responseData() ) } ) );

		const root = mountBlock();
		initBlock( root );

		const input = root.querySelector( '.lcss-search__input' );
		expect( input.getAttribute( 'placeholder' ) ).toBe( 'Search the network…' );

		input.value = 'match';
		input.dispatchEvent( new Event( 'input' ) );
		await new Promise( ( r ) => setTimeout( r, 400 ) );

		expect( global.fetch ).toHaveBeenCalledTimes( 1 );
		const body = JSON.parse( global.fetch.mock.calls[ 0 ][ 1 ].body );
		expect( body.q ).toBe( 'match' );
		expect( body.page.size ).toBe( 2 );
		expect( body.facets.map( ( f ) => f.field ) ).toEqual( [ 'blog_name', 'post_type' ] );
		expect( body.attributesToHighlight ).toContain( 'post_title' );

		// Results + highlighting.
		expect( root.querySelectorAll( '.lcss-search__result' ).length ).toBe( 2 );
		expect( root.querySelector( '.lcss-search__title' ).innerHTML ).toContain( '<mark>Al</mark>' );
		expect( root.querySelector( '.lcss-search__site' ).textContent ).toBe( 'Site A' );

		// Facets rendered (2 sites + 2 types).
		expect( root.querySelectorAll( '.lcss-search__facetcb' ).length ).toBe( 4 );

		// Pager.
		expect( root.querySelectorAll( '.lcss-search__page' ).length ).toBeGreaterThan( 0 );
	} );

	it( 'sends a filter when a facet is toggled', async () => {
		global.fetch = vi.fn( () => Promise.resolve( { json: () => Promise.resolve( responseData() ) } ) );
		const root = mountBlock();
		initBlock( root );

		const input = root.querySelector( '.lcss-search__input' );
		input.value = 'match';
		input.dispatchEvent( new Event( 'input' ) );
		await new Promise( ( r ) => setTimeout( r, 400 ) );

		// Toggle the "Site A" facet checkbox.
		const siteLabel = [ ...root.querySelectorAll( '.lcss-search__facet' ) ].find( ( l ) => /Site A/.test( l.textContent ) );
		const cb = siteLabel.querySelector( 'input' );
		cb.checked = true;
		cb.dispatchEvent( new Event( 'change' ) );
		await new Promise( ( r ) => setTimeout( r, 50 ) );

		expect( global.fetch ).toHaveBeenCalledTimes( 2 );
		const body = JSON.parse( global.fetch.mock.calls[ 1 ][ 1 ].body );
		expect( body.filter ).toEqual( { type: 'pred', field: 'blog_name', op: 'in', value: [ 'Site A' ] } );
	} );

	it( 'clears results when the query is emptied', async () => {
		global.fetch = vi.fn();
		const root = mountBlock();
		initBlock( root );

		const input = root.querySelector( '.lcss-search__input' );
		input.value = '';
		input.dispatchEvent( new Event( 'input' ) );
		await new Promise( ( r ) => setTimeout( r, 400 ) );

		expect( global.fetch ).not.toHaveBeenCalled();
		expect( root.querySelectorAll( '.lcss-search__result' ).length ).toBe( 0 );
	} );
} );
