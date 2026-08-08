/**
 * Network settings screen for Loupe Cross-Site Search.
 * WordPress React (@wordpress/element) + @wordpress/components. No build step.
 */
( function ( wp ) {
	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var useRef = wp.element.useRef;
	var apiFetch = wp.apiFetch;
	var __ = wp.i18n.__;
	var sprintf = wp.i18n.sprintf;
	var C = wp.components;

	if ( window.lcssSettings ) {
		apiFetch.use( apiFetch.createRootURLMiddleware( window.lcssSettings.root ) );
		apiFetch.use( apiFetch.createNonceMiddleware( window.lcssSettings.nonce ) );
	}

	function card( title, description, children ) {
		return el(
			C.Card,
			{ className: 'lcss-card' },
			el(
				C.CardHeader,
				null,
				el(
					'div',
					null,
					el( 'h2', { className: 'lcss-card__title' }, title ),
					description ? el( 'p', { className: 'lcss-card__desc' }, description ) : null
				)
			),
			el( C.CardBody, null, children )
		);
	}

	function App() {
		var d = useState( null ); var data = d[ 0 ], setData = d[ 1 ];
		var l = useState( true ); var loading = l[ 0 ], setLoading = l[ 1 ];
		var s = useState( false ); var saving = s[ 0 ], setSaving = s[ 1 ];
		var n = useState( null ); var notice = n[ 0 ], setNotice = n[ 1 ];
		var f = useState( '' ); var filter = f[ 0 ], setFilter = f[ 1 ];
		var rx = useState( { available: true, pending: 0, queued: 0, started_at: 0, finished_at: 0 } );
		var reindex = rx[ 0 ], setReindex = rx[ 1 ];
		var pollRef = useRef( null );

		useEffect( function () {
			apiFetch( { path: '/loupe-cross-site/v1/settings' } )
				.then( function ( res ) { setData( res ); } )
				.catch( function () { setNotice( { status: 'error', msg: __( 'Failed to load settings.', 'loupe-cross-site-search' ) } ); } )
				.finally( function () { setLoading( false ); } );
		}, [] );

		useEffect( function () {
			loadReindex();
			return function () { if ( pollRef.current ) { clearTimeout( pollRef.current ); } };
		}, [] );

		function loadReindex() {
			apiFetch( { path: '/loupe-cross-site/v1/reindex' } )
				.then( function ( r ) {
					setReindex( function ( prev ) { return Object.assign( {}, prev, r ); } );
					scheduleNextPoll( r );
				} )
				.catch( function () {} );
		}

		function scheduleNextPoll( r ) {
			if ( pollRef.current ) { clearTimeout( pollRef.current ); }
			if ( r && r.pending > 0 ) {
				pollRef.current = setTimeout( loadReindex, 3000 );
			}
		}

		function startReindex() {
			setNotice( null );
			apiFetch( { path: '/loupe-cross-site/v1/reindex', method: 'POST' } )
				.then( function ( r ) {
					setReindex( function ( prev ) { return Object.assign( {}, prev, r ); } );
					setNotice( { status: 'success', msg: sprintf( __( 'Reindex queued for %d sites. It runs in the background.', 'loupe-cross-site-search' ), r.queued || 0 ) } );
					scheduleNextPoll( r );
				} )
				.catch( function ( err ) {
					setNotice( { status: 'error', msg: ( err && err.message ) || __( 'Could not start the reindex.', 'loupe-cross-site-search' ) } );
				} );
		}

		function update( patch ) {
			setData( function ( prev ) {
				return Object.assign( {}, prev, { settings: Object.assign( {}, prev.settings, patch ) } );
			} );
		}

		function toggleIn( list, value, on ) {
			var next = list.slice();
			var i = next.indexOf( value );
			if ( on && i === -1 ) { next.push( value ); }
			if ( ! on && i !== -1 ) { next.splice( i, 1 ); }
			return next;
		}

		function save() {
			setSaving( true );
			setNotice( null );
			apiFetch( { path: '/loupe-cross-site/v1/settings', method: 'POST', data: data.settings } )
				.then( function ( res ) {
					setData( res );
					setNotice( { status: 'success', msg: __( 'Settings saved. Run “wp loupe-cross-site reindex” to apply changes to the index.', 'loupe-cross-site-search' ) } );
				} )
				.catch( function ( err ) {
					setNotice( { status: 'error', msg: ( err && err.message ) || __( 'Save failed.', 'loupe-cross-site-search' ) } );
				} )
				.finally( function () { setSaving( false ); } );
		}

		if ( loading ) {
			return el( 'div', { className: 'lcss-settings__loading' }, el( C.Spinner, null ), ' ', __( 'Loading settings…', 'loupe-cross-site-search' ) );
		}
		if ( ! data ) {
			return el( C.Notice, { status: 'error', isDismissible: false }, notice ? notice.msg : __( 'No data.', 'loupe-cross-site-search' ) );
		}

		var settings = data.settings;
		var siteOptions = data.sites.map( function ( site ) {
			return {
				value: String( site.id ),
				label: ( site.name || ( '#' + site.id ) ) + ' — ' + site.url,
			};
		} );
		var participating = data.sites.filter( function ( x ) { return x.participating; } ).length;

		var q = filter.toLowerCase();
		var visibleSites = data.sites.filter( function ( site ) {
			if ( ! q ) { return true; }
			return ( site.name || '' ).toLowerCase().indexOf( q ) !== -1 || site.url.toLowerCase().indexOf( q ) !== -1;
		} );

		return el(
			Fragment,
			null,
			el( 'h1', { className: 'lcss-settings__title' }, __( 'Loupe Cross-Site Search', 'loupe-cross-site-search' ) ),
			el( 'p', { className: 'lcss-settings__intro' }, sprintf(
				/* translators: %d: number of participating sites */
				__( '%d sites currently participate in cross-site search.', 'loupe-cross-site-search' ),
				participating
			) ),

			notice ? el(
				'div',
				{ className: 'lcss-settings__notice' },
				el( C.Notice, { status: notice.status, onRemove: function () { setNotice( null ); } }, notice.msg )
			) : null,

			card(
				__( 'Hub site', 'loupe-cross-site-search' ),
				__( 'The search endpoint POST /wp-json/loupe-cross-site/v1/search is registered only on this site.', 'loupe-cross-site-search' ),
				el( C.SelectControl, {
					label: __( 'Site that exposes the search endpoint', 'loupe-cross-site-search' ),
					hideLabelFromVision: true,
					value: String( settings.hub_blog_id ),
					options: siteOptions,
					onChange: function ( v ) { update( { hub_blog_id: parseInt( v, 10 ) } ); },
					__nextHasNoMarginBottom: true,
				} )
			),

			card(
				__( 'Participation', 'loupe-cross-site-search' ),
				__( 'Choose which sites are searchable.', 'loupe-cross-site-search' ),
				el(
					Fragment,
					null,
					el( C.RadioControl, {
						selected: settings.mode,
						options: [
							{ label: __( 'All public sites', 'loupe-cross-site-search' ), value: 'all' },
							{ label: __( 'Only selected sites (allowlist)', 'loupe-cross-site-search' ), value: 'allowlist' },
							{ label: __( 'All public sites except selected (blocklist)', 'loupe-cross-site-search' ), value: 'blocklist' },
						],
						onChange: function ( v ) { update( { mode: v } ); },
					} ),
					settings.mode !== 'all' ? el(
						'div',
						{ className: 'lcss-siteselect' },
						el( C.SearchControl, {
							value: filter,
							onChange: setFilter,
							placeholder: __( 'Filter sites…', 'loupe-cross-site-search' ),
							__nextHasNoMarginBottom: true,
						} ),
						el(
							'div',
							{ className: 'lcss-sitelist' },
							visibleSites.map( function ( site ) {
								return el( C.CheckboxControl, {
									key: site.id,
									label: site.name || ( '#' + site.id ),
									help: site.url + ( site.public ? '' : ' · ' + __( 'not public', 'loupe-cross-site-search' ) ),
									checked: settings.sites.indexOf( site.id ) !== -1,
									onChange: function ( on ) { update( { sites: toggleIn( settings.sites, site.id, on ) } ); },
									__nextHasNoMarginBottom: true,
								} );
							} )
						)
					) : null
				)
			),

			card(
				__( 'Index language', 'loupe-cross-site-search' ),
				__( 'One language is used to tokenize the whole combined index.', 'loupe-cross-site-search' ),
				el( C.TextControl, {
					label: __( 'Two-letter language code', 'loupe-cross-site-search' ),
					value: settings.language,
					onChange: function ( v ) { update( { language: v.toLowerCase().slice( 0, 2 ) } ); },
					help: __( 'For example: en, nb, de, fr.', 'loupe-cross-site-search' ),
					className: 'lcss-lang',
					__nextHasNoMarginBottom: true,
				} )
			),

			card(
				__( 'Post types', 'loupe-cross-site-search' ),
				__( 'Only these post types are mirrored into the combined index.', 'loupe-cross-site-search' ),
				el(
					'div',
					{ className: 'lcss-typelist' },
					data.postTypes.map( function ( pt ) {
						return el( C.CheckboxControl, {
							key: pt.slug,
							label: pt.label + ' (' + pt.slug + ')',
							checked: settings.post_types.indexOf( pt.slug ) !== -1,
							onChange: function ( on ) { update( { post_types: toggleIn( settings.post_types, pt.slug, on ) } ); },
							__nextHasNoMarginBottom: true,
						} );
					} )
				)
			),

			el(
				'div',
				{ className: 'lcss-settings__actions' },
				el( C.Button, {
					variant: 'primary',
					isBusy: saving,
					disabled: saving,
					onClick: save,
				}, saving ? __( 'Saving…', 'loupe-cross-site-search' ) : __( 'Save settings', 'loupe-cross-site-search' ) ),
				reindex.available ? el( C.Button, {
					variant: 'secondary',
					isBusy: reindex.pending > 0,
					disabled: reindex.pending > 0,
					onClick: startReindex,
				}, reindex.pending > 0
					? sprintf( __( 'Reindexing… %d left', 'loupe-cross-site-search' ), reindex.pending )
					: __( 'Reindex now', 'loupe-cross-site-search' ) ) : null,
				el( 'p', { className: 'lcss-hint' }, __( 'Reindex rebuilds the combined index for all participating sites in the background.', 'loupe-cross-site-search' ) )
			)
		);
	}

	wp.domReady( function () {
		var node = document.getElementById( 'lcss-settings-root' );
		if ( ! node ) { return; }
		if ( wp.element.createRoot ) {
			wp.element.createRoot( node ).render( el( App ) );
		} else {
			wp.element.render( el( App ), node );
		}
	} );
} )( window.wp );
