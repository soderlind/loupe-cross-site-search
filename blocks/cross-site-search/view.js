/**
 * Front-end view script for the Cross-Site Search block.
 *
 * Vanilla JS, no build step. Renders a complete search experience against the
 * hub REST endpoint: debounced query, site + post-type facets, sorting,
 * highlighting/snippets, result metadata, pagination, and loading/empty/error
 * states.
 */
( function () {
	'use strict';

	var HIGHLIGHT_TAGS = { MARK: 1, STRONG: 1, EM: 1, B: 1, I: 1 };

	function debounce( fn, wait ) {
		var timer;
		return function () {
			var args = arguments;
			clearTimeout( timer );
			timer = setTimeout( function () {
				fn.apply( null, args );
			}, wait );
		};
	}

	function h( tag, attrs, children ) {
		var node = document.createElement( tag );
		if ( attrs ) {
			Object.keys( attrs ).forEach( function ( key ) {
				if ( key === 'class' ) {
					node.className = attrs[ key ];
				} else if ( key === 'text' ) {
					node.textContent = attrs[ key ];
				} else if ( key === 'html' ) {
					node.innerHTML = attrs[ key ];
				} else if ( key === 'onclick' ) {
					node.addEventListener( 'click', attrs[ key ] );
				} else if ( attrs[ key ] !== null && attrs[ key ] !== undefined && attrs[ key ] !== false ) {
					node.setAttribute( key, attrs[ key ] );
				}
			} );
		}
		( children || [] ).forEach( function ( child ) {
			if ( child !== null && child !== undefined && child !== false ) {
				node.appendChild( typeof child === 'string' ? document.createTextNode( child ) : child );
			}
		} );
		return node;
	}

	// Keep only safe inline highlight tags; strip everything else. Indexed
	// content is plain text, so the server's _formatted values only ever contain
	// these wrap tags plus matched text — this is defense in depth.
	function sanitizeHighlight( html ) {
		var tpl = document.createElement( 'template' );
		tpl.innerHTML = String( html == null ? '' : html );
		( function walk( parent ) {
			Array.prototype.slice.call( parent.childNodes ).forEach( function ( child ) {
				if ( child.nodeType === 1 ) {
					if ( ! HIGHLIGHT_TAGS[ child.tagName ] ) {
						child.replaceWith( document.createTextNode( child.textContent ) );
					} else {
						Array.prototype.slice.call( child.attributes ).forEach( function ( a ) {
							child.removeAttribute( a.name );
						} );
						walk( child );
					}
				}
			} );
		} )( tpl.content );
		return tpl.innerHTML;
	}

	// Minimal printf for translated strings (%s, %d, %1$s, %2$d, …).
	function fmt( str, args ) {
		var i = 0;
		return String( str ).replace( /%(?:(\d+)\$)?([sd])/g, function ( _m, pos, type ) {
			var idx = pos ? parseInt( pos, 10 ) - 1 : i++;
			var v = args[ idx ];
			return type === 'd' ? String( parseInt( v, 10 ) || 0 ) : String( v == null ? '' : v );
		} );
	}

	function prettifyType( slug ) {
		return String( slug ).replace( /[_-]+/g, ' ' ).replace( /\b\w/g, function ( c ) {
			return c.toUpperCase();
		} );
	}

	function formatDate( raw ) {
		if ( ! raw ) {
			return '';
		}
		var d = new Date( String( raw ).replace( ' ', 'T' ) );
		return isNaN( d.getTime() ) ? '' : d.toLocaleDateString();
	}

	function parseConfig( root ) {
		var raw = root.getAttribute( 'data-config' );
		if ( raw ) {
			try {
				return JSON.parse( raw );
			} catch ( e ) {
				/* fall through to legacy attributes */
			}
		}
		// Legacy (0.1.x) attributes.
		return {
			endpoint: root.getAttribute( 'data-endpoint' ),
			perPage: parseInt( root.getAttribute( 'data-per-page' ), 10 ) || 10,
			placeholder: root.getAttribute( 'data-placeholder' ) || 'Search…',
			i18n: {},
		};
	}

	function sortToParam( sort ) {
		switch ( sort ) {
			case 'newest':
				return [ { by: 'post_date', order: 'desc' } ];
			case 'oldest':
				return [ { by: 'post_date', order: 'asc' } ];
			case 'title':
				return [ { by: 'post_title', order: 'asc' } ];
			default:
				return [ { by: '_score', order: 'desc' } ];
		}
	}

	function initBlock( root ) {
		var cfg = parseConfig( root );
		if ( ! cfg || ! cfg.endpoint ) {
			return;
		}
		var t = cfg.i18n || {};
		var perPage = cfg.perPage || 10;

		var state = {
			query: '',
			page: 1,
			sort: cfg.defaultSort || 'relevance',
			sites: {},
			types: {},
			typeLabels: {},
			controller: null,
		};

		root.textContent = '';
		root.setAttribute( 'role', 'search' );

		if ( cfg.heading ) {
			root.appendChild( h( 'h2', { class: 'lcss-search__heading', text: cfg.heading } ) );
		}

		var input = h( 'input', {
			type: 'search',
			class: 'lcss-search__input',
			placeholder: cfg.placeholder || 'Search…',
			'aria-label': cfg.placeholder || 'Search…',
		} );
		var clearBtn = h( 'button', {
			type: 'button',
			class: 'lcss-search__clear',
			'aria-label': t.clear || 'Clear search',
			text: '×',
		} );
		clearBtn.hidden = true;
		var inputRow = h( 'div', { class: 'lcss-search__inputrow' }, [ input, clearBtn ] );

		var sortSelect = null;
		if ( cfg.showSort !== false ) {
			sortSelect = h( 'select', { class: 'lcss-search__sort', 'aria-label': t.sortBy || 'Sort by' }, [
				h( 'option', { value: 'relevance', text: t.relevance || 'Relevance' } ),
				h( 'option', { value: 'newest', text: t.newest || 'Newest' } ),
				h( 'option', { value: 'oldest', text: t.oldest || 'Oldest' } ),
				h( 'option', { value: 'title', text: t.title || 'Title' } ),
			] );
			sortSelect.value = state.sort;
		}

		var status = h( 'p', { class: 'lcss-search__status', 'aria-live': 'polite', role: 'status' } );
		var toolbar = h( 'div', { class: 'lcss-search__toolbar' }, [ status, sortSelect ] );

		var facets = h( 'aside', { class: 'lcss-search__facets' } );
		var results = h( 'ul', { class: 'lcss-search__results' } );
		var pager = h( 'nav', { class: 'lcss-search__pager', 'aria-label': 'Pagination' } );
		var showFacets = cfg.showSiteFilter !== false || cfg.showTypeFilter !== false;
		var main = h( 'div', { class: 'lcss-search__main' }, [ results, pager ] );
		var layout = h( 'div', { class: 'lcss-search__layout' + ( showFacets ? '' : ' is-nofacets' ) }, [ showFacets ? facets : null, main ] );

		root.appendChild( inputRow );
		root.appendChild( toolbar );
		root.appendChild( layout );

		function buildBody() {
			var body = {
				q: state.query,
				page: { number: state.page, size: perPage },
				sort: sortToParam( state.sort ),
			};
			var facetReq = [];
			if ( cfg.showSiteFilter !== false ) {
				facetReq.push( { type: 'terms', field: 'blog_name' } );
			}
			if ( cfg.showTypeFilter !== false ) {
				facetReq.push( { type: 'terms', field: 'post_type' } );
			}
			if ( facetReq.length ) {
				body.facets = facetReq;
			}
			var preds = [];
			var sites = Object.keys( state.sites );
			var types = Object.keys( state.types );
			if ( sites.length ) {
				preds.push( { type: 'pred', field: 'blog_name', op: 'in', value: sites } );
			}
			if ( types.length ) {
				preds.push( { type: 'pred', field: 'post_type', op: 'in', value: types } );
			}
			if ( preds.length === 1 ) {
				body.filter = preds[ 0 ];
			} else if ( preds.length > 1 ) {
				body.filter = { type: 'and', items: preds };
			}
			if ( cfg.highlight !== false ) {
				body.attributesToHighlight = [ 'post_title', 'post_content' ];
				body.highlightStartTag = '<mark>';
				body.highlightEndTag = '</mark>';
				body.attributesToCrop = [ 'post_content' ];
				body.cropLength = 30;
			}
			return body;
		}

		function run() {
			if ( state.controller ) {
				state.controller.abort();
			}
			state.controller = new AbortController();
			root.classList.add( 'is-loading' );
			status.textContent = t.searching || 'Searching…';

			fetch( cfg.endpoint, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				signal: state.controller.signal,
				body: JSON.stringify( buildBody() ),
			} )
				.then( function ( res ) {
					return res.json();
				} )
				.then( function ( data ) {
					render( data );
				} )
				.catch( function ( error ) {
					if ( error.name !== 'AbortError' ) {
						status.textContent = t.failed || 'Search failed.';
						results.textContent = '';
						pager.textContent = '';
						facets.textContent = '';
					}
				} )
				.finally( function () {
					root.classList.remove( 'is-loading' );
				} );
		}

		function render( data ) {
			data = data || {};
			var hits = data.hits || [];
			var pagination = data.pagination || { total: 0, current_page: 1, total_pages: 0 };

			// Cache type labels from hits for nicer facet labels.
			hits.forEach( function ( hit ) {
				if ( hit.post_type && hit.post_type_label ) {
					state.typeLabels[ hit.post_type ] = hit.post_type_label;
				}
			} );

			renderStatus( pagination.total, data.tookMs || 0 );
			renderResults( hits );
			renderFacets( data.facets || {} );
			renderPager( pagination );
		}

		function renderStatus( total, took ) {
			if ( ! total ) {
				status.textContent = fmt( t.noResults || 'No results for “%s”.', [ state.query ] );
				return;
			}
			status.textContent = total === 1
				? fmt( t.oneResult || '1 result for “%1$s” in %2$d ms', [ state.query, took ] )
				: fmt( t.results || '%1$d results for “%2$s” in %3$d ms', [ total, state.query, took ] );
		}

		function renderResults( hits ) {
			results.textContent = '';
			hits.forEach( function ( hit ) {
				var titleHtml = cfg.highlight !== false && hit._formatted && hit._formatted.post_title
					? sanitizeHighlight( hit._formatted.post_title )
					: null;
				var link = h( 'a', titleHtml
					? { href: hit.url, class: 'lcss-search__title', html: titleHtml }
					: { href: hit.url, class: 'lcss-search__title', text: hit.title || '(untitled)' } );

				var meta = [ h( 'span', { class: 'lcss-search__site', text: hit.blog_name || '' } ) ];
				if ( hit.post_type_label ) {
					meta.push( h( 'span', { class: 'lcss-search__type', text: hit.post_type_label } ) );
				}
				if ( cfg.showDate !== false && hit.date ) {
					var d = formatDate( hit.date );
					if ( d ) {
						meta.push( h( 'time', { class: 'lcss-search__date', datetime: hit.date, text: d } ) );
					}
				}

				var kids = [ link, h( 'div', { class: 'lcss-search__meta' }, meta ) ];

				if ( cfg.showExcerpt !== false ) {
					var snipHtml = cfg.highlight !== false && hit._formatted && hit._formatted.post_content
						? sanitizeHighlight( hit._formatted.post_content )
						: null;
					if ( snipHtml ) {
						kids.push( h( 'p', { class: 'lcss-search__excerpt', html: snipHtml } ) );
					} else if ( hit.excerpt ) {
						kids.push( h( 'p', { class: 'lcss-search__excerpt', text: hit.excerpt } ) );
					}
				}

				results.appendChild( h( 'li', { class: 'lcss-search__result' }, kids ) );
			} );
		}

		function renderFacets( respFacets ) {
			if ( ! showFacets ) {
				return;
			}
			facets.textContent = '';

			var hasSelection = Object.keys( state.sites ).length || Object.keys( state.types ).length;
			if ( hasSelection ) {
				facets.appendChild( h( 'button', {
					type: 'button',
					class: 'lcss-search__clearfilters',
					text: t.clearAll || 'Clear filters',
					onclick: function () {
						state.sites = {};
						state.types = {};
						state.page = 1;
						run();
					},
				} ) );
			}

			if ( cfg.showSiteFilter !== false ) {
				facets.appendChild( facetGroup(
					t.sites || 'Sites',
					respFacets.blog_name,
					state.sites,
					function ( v ) { return v; }
				) );
			}
			if ( cfg.showTypeFilter !== false ) {
				facets.appendChild( facetGroup(
					t.types || 'Types',
					respFacets.post_type,
					state.types,
					function ( v ) { return state.typeLabels[ v ] || prettifyType( v ); }
				) );
			}
		}

		function facetGroup( label, facet, selected, labeler ) {
			var buckets = ( facet && facet.buckets ) ? facet.buckets.slice() : [];
			// Ensure selected-but-absent values remain visible.
			Object.keys( selected ).forEach( function ( val ) {
				if ( ! buckets.some( function ( b ) { return String( b.value ) === val; } ) ) {
					buckets.push( { value: val, count: 0 } );
				}
			} );

			var list = h( 'ul', { class: 'lcss-search__facetlist' } );
			buckets.forEach( function ( bucket ) {
				var val = String( bucket.value );
				var cb = h( 'input', { type: 'checkbox', class: 'lcss-search__facetcb' } );
				cb.checked = !! selected[ val ];
				cb.addEventListener( 'change', function () {
					if ( cb.checked ) {
						selected[ val ] = true;
					} else {
						delete selected[ val ];
					}
					state.page = 1;
					run();
				} );
				var lbl = h( 'label', { class: 'lcss-search__facet' }, [
					cb,
					h( 'span', { class: 'lcss-search__facetlabel', text: labeler( val ) } ),
					h( 'span', { class: 'lcss-search__facetcount', text: String( bucket.count ) } ),
				] );
				list.appendChild( h( 'li', {}, [ lbl ] ) );
			} );

			return h( 'div', { class: 'lcss-search__facetgroup' }, [
				h( 'h3', { class: 'lcss-search__facettitle', text: label } ),
				list,
			] );
		}

		function renderPager( pagination ) {
			pager.textContent = '';
			var total = pagination.total_pages || 0;
			if ( total <= 1 ) {
				return;
			}
			var current = pagination.current_page || 1;

			function button( label, page, disabled, active, aria ) {
				var btn = h( 'button', {
					type: 'button',
					class: 'lcss-search__page' + ( active ? ' is-active' : '' ),
					'aria-label': aria || null,
					'aria-current': active ? 'page' : null,
				}, [ label ] );
				if ( disabled ) {
					btn.disabled = true;
				} else {
					btn.addEventListener( 'click', function () {
						state.page = page;
						run();
						root.scrollIntoView( { behavior: 'smooth', block: 'start' } );
					} );
				}
				return btn;
			}

			pager.appendChild( button( '‹', current - 1, current <= 1, false, t.prev || 'Previous' ) );
			var start = Math.max( 1, current - 2 );
			var end = Math.min( total, start + 4 );
			for ( var p = start; p <= end; p++ ) {
				pager.appendChild( button( String( p ), p, false, p === current ) );
			}
			pager.appendChild( button( '›', current + 1, current >= total, false, t.next || 'Next' ) );
		}

		function reset() {
			results.textContent = '';
			pager.textContent = '';
			facets.textContent = '';
			status.textContent = '';
		}

		var onInput = debounce( function () {
			var q = input.value.trim();
			clearBtn.hidden = ! input.value;
			state.query = q;
			state.page = 1;
			if ( ! q ) {
				reset();
				return;
			}
			run();
		}, 300 );

		input.addEventListener( 'input', onInput );
		clearBtn.addEventListener( 'click', function () {
			input.value = '';
			clearBtn.hidden = true;
			state.query = '';
			state.sites = {};
			state.types = {};
			state.page = 1;
			reset();
			input.focus();
		} );
		if ( sortSelect ) {
			sortSelect.addEventListener( 'change', function () {
				state.sort = sortSelect.value;
				state.page = 1;
				if ( state.query ) {
					run();
				}
			} );
		}
	}

	if ( typeof document !== 'undefined' && document.addEventListener ) {
		document.addEventListener( 'DOMContentLoaded', function () {
			document.querySelectorAll( '.lcss-search' ).forEach( initBlock );
		} );
	}

	// Expose internals for unit tests; ignored in the browser.
	if ( typeof module !== 'undefined' && module.exports ) {
		module.exports = { debounce: debounce, h: h, initBlock: initBlock, sanitizeHighlight: sanitizeHighlight, fmt: fmt };
	}
} )();
