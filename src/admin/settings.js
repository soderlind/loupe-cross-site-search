/**
 * Network settings screen for Loupe Cross-Site Search.
 * WordPress React (@wordpress/element) + @wordpress/components.
 */
import {
	createElement as el,
	Fragment,
	useState,
	useEffect,
	useRef,
	createRoot,
	render,
} from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';
import domReady from '@wordpress/dom-ready';
import {
	Card,
	CardHeader,
	CardBody,
	SelectControl,
	RadioControl,
	TextControl,
	CheckboxControl,
	SearchControl,
	Button,
	Notice,
	Spinner,
} from '@wordpress/components';
import './settings.scss';

if ( window.lcssSettings ) {
	apiFetch.use( apiFetch.createRootURLMiddleware( window.lcssSettings.root ) );
	apiFetch.use( apiFetch.createNonceMiddleware( window.lcssSettings.nonce ) );
}

function card( title, description, children ) {
	return el(
		Card,
		{ className: 'lcss-card' },
		el(
			CardHeader,
			null,
			el(
				'div',
				null,
				el( 'h2', { className: 'lcss-card__title' }, title ),
				description ? el( 'p', { className: 'lcss-card__desc' }, description ) : null
			)
		),
		el( CardBody, null, children )
	);
}

function App() {
	const d = useState( null ); const data = d[ 0 ], setData = d[ 1 ];
	const l = useState( true ); const loading = l[ 0 ], setLoading = l[ 1 ];
	const s = useState( false ); const saving = s[ 0 ], setSaving = s[ 1 ];
	const n = useState( null ); const notice = n[ 0 ], setNotice = n[ 1 ];
	const f = useState( '' ); const filter = f[ 0 ], setFilter = f[ 1 ];
	const rx = useState( { available: true, pending: 0, queued: 0, started_at: 0, finished_at: 0 } );
	const reindex = rx[ 0 ], setReindex = rx[ 1 ];
	const pollRef = useRef( null );

	useEffect( () => {
		apiFetch( { path: '/loupe-cross-site/v1/settings' } )
			.then( ( res ) => setData( res ) )
			.catch( () => setNotice( { status: 'error', msg: __( 'Failed to load settings.', 'loupe-cross-site-search' ) } ) )
			.finally( () => setLoading( false ) );
	}, [] );

	useEffect( () => {
		loadReindex();
		return () => { if ( pollRef.current ) { clearTimeout( pollRef.current ); } };
	}, [] );

	function loadReindex() {
		apiFetch( { path: '/loupe-cross-site/v1/reindex' } )
			.then( ( r ) => { setReindex( ( prev ) => ( { ...prev, ...r } ) ); scheduleNextPoll( r ); } )
			.catch( () => {} );
	}

	function scheduleNextPoll( r ) {
		if ( pollRef.current ) { clearTimeout( pollRef.current ); }
		if ( r && r.pending > 0 ) { pollRef.current = setTimeout( loadReindex, 3000 ); }
	}

	function startReindex() {
		setNotice( null );
		apiFetch( { path: '/loupe-cross-site/v1/reindex', method: 'POST' } )
			.then( ( r ) => {
				setReindex( ( prev ) => ( { ...prev, ...r } ) );
				setNotice( { status: 'success', msg: sprintf(
					/* translators: %d: number of sites queued */
					__( 'Reindex queued for %d sites. It runs in the background.', 'loupe-cross-site-search' ),
					r.queued || 0
				) } );
				scheduleNextPoll( r );
			} )
			.catch( ( err ) => setNotice( { status: 'error', msg: ( err && err.message ) || __( 'Could not start the reindex.', 'loupe-cross-site-search' ) } ) );
	}

	function update( patch ) {
		setData( ( prev ) => ( { ...prev, settings: { ...prev.settings, ...patch } } ) );
	}

	function toggleIn( list, value, on ) {
		const next = list.slice();
		const i = next.indexOf( value );
		if ( on && i === -1 ) { next.push( value ); }
		if ( ! on && i !== -1 ) { next.splice( i, 1 ); }
		return next;
	}

	function save() {
		setSaving( true );
		setNotice( null );
		apiFetch( { path: '/loupe-cross-site/v1/settings', method: 'POST', data: data.settings } )
			.then( ( res ) => {
				setData( res );
				setNotice( { status: 'success', msg: __( 'Settings saved. Run “wp loupe-cross-site reindex” to apply changes to the index.', 'loupe-cross-site-search' ) } );
			} )
			.catch( ( err ) => setNotice( { status: 'error', msg: ( err && err.message ) || __( 'Save failed.', 'loupe-cross-site-search' ) } ) )
			.finally( () => setSaving( false ) );
	}

	if ( loading ) {
		return el( 'div', { className: 'lcss-settings__loading' }, el( Spinner, null ), ' ', __( 'Loading settings…', 'loupe-cross-site-search' ) );
	}
	if ( ! data ) {
		return el( Notice, { status: 'error', isDismissible: false }, notice ? notice.msg : __( 'No data.', 'loupe-cross-site-search' ) );
	}

	const settings = data.settings;
	const siteOptions = data.sites.map( ( site ) => ( {
		value: String( site.id ),
		label: ( site.name || ( '#' + site.id ) ) + ' — ' + site.url,
	} ) );
	const participating = data.sites.filter( ( x ) => x.participating ).length;

	const q = filter.toLowerCase();
	const visibleSites = data.sites.filter( ( site ) => {
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

		notice ? el( 'div', { className: 'lcss-settings__notice' }, el( Notice, { status: notice.status, onRemove: () => setNotice( null ) }, notice.msg ) ) : null,

		card(
			__( 'Hub site', 'loupe-cross-site-search' ),
			__( 'The search endpoint POST /wp-json/loupe-cross-site/v1/search is registered only on this site.', 'loupe-cross-site-search' ),
			el( SelectControl, {
				label: __( 'Site that exposes the search endpoint', 'loupe-cross-site-search' ),
				hideLabelFromVision: true,
				value: String( settings.hub_blog_id ),
				options: siteOptions,
				onChange: ( v ) => update( { hub_blog_id: parseInt( v, 10 ) } ),
				__nextHasNoMarginBottom: true,
			} )
		),

		card(
			__( 'Participation', 'loupe-cross-site-search' ),
			__( 'Choose which sites are searchable.', 'loupe-cross-site-search' ),
			el(
				Fragment,
				null,
				el( RadioControl, {
					selected: settings.mode,
					options: [
						{ label: __( 'All public sites', 'loupe-cross-site-search' ), value: 'all' },
						{ label: __( 'Only selected sites (allowlist)', 'loupe-cross-site-search' ), value: 'allowlist' },
						{ label: __( 'All public sites except selected (blocklist)', 'loupe-cross-site-search' ), value: 'blocklist' },
					],
					onChange: ( v ) => update( { mode: v } ),
				} ),
				settings.mode !== 'all' ? el(
					'div',
					{ className: 'lcss-siteselect' },
					el( SearchControl, {
						value: filter,
						onChange: setFilter,
						placeholder: __( 'Filter sites…', 'loupe-cross-site-search' ),
						__nextHasNoMarginBottom: true,
					} ),
					el(
						'div',
						{ className: 'lcss-sitelist' },
						visibleSites.map( ( site ) => el( CheckboxControl, {
							key: site.id,
							label: site.name || ( '#' + site.id ),
							help: site.url + ( site.public ? '' : ' · ' + __( 'not public', 'loupe-cross-site-search' ) ),
							checked: settings.sites.indexOf( site.id ) !== -1,
							onChange: ( on ) => update( { sites: toggleIn( settings.sites, site.id, on ) } ),
							__nextHasNoMarginBottom: true,
						} ) )
					)
				) : null
			)
		),

		card(
			__( 'Index language', 'loupe-cross-site-search' ),
			__( 'One language is used to tokenize the whole combined index.', 'loupe-cross-site-search' ),
			el( TextControl, {
				label: __( 'Two-letter language code', 'loupe-cross-site-search' ),
				value: settings.language,
				onChange: ( v ) => update( { language: v.toLowerCase().slice( 0, 2 ) } ),
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
				data.postTypes.map( ( pt ) => el( CheckboxControl, {
					key: pt.slug,
					label: pt.label + ' (' + pt.slug + ')',
					checked: settings.post_types.indexOf( pt.slug ) !== -1,
					onChange: ( on ) => update( { post_types: toggleIn( settings.post_types, pt.slug, on ) } ),
					__nextHasNoMarginBottom: true,
				} ) )
			)
		),

		el(
			'div',
			{ className: 'lcss-settings__actions' },
			el( Button, {
				variant: 'primary',
				isBusy: saving,
				disabled: saving,
				onClick: save,
			}, saving ? __( 'Saving…', 'loupe-cross-site-search' ) : __( 'Save settings', 'loupe-cross-site-search' ) ),
			reindex.available ? el( Button, {
				variant: 'secondary',
				isBusy: reindex.pending > 0,
				disabled: reindex.pending > 0,
				onClick: startReindex,
			}, reindex.pending > 0
			? sprintf(
				/* translators: %d: number of sites left to reindex */
				__( 'Reindexing… %d left', 'loupe-cross-site-search' ),
				reindex.pending
			)
				: __( 'Reindex now', 'loupe-cross-site-search' ) ) : null,
			el( 'p', { className: 'lcss-hint' }, __( 'Reindex rebuilds the combined index for all participating sites in the background.', 'loupe-cross-site-search' ) )
		)
	);
}

domReady( () => {
	const node = document.getElementById( 'lcss-settings-root' );
	if ( ! node ) { return; }
	if ( typeof createRoot === 'function' ) {
		createRoot( node ).render( el( App ) );
	} else {
		render( el( App ), node );
	}
} );
