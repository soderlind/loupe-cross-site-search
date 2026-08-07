/**
 * Editor script for the Cross-Site Search block.
 * Plain JS (no build step): uses the global wp.* runtime.
 */
( function ( wp ) {
	var registerBlockType = wp.blocks.registerBlockType;
	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var c = wp.components;
	var __ = wp.i18n.__;

	function toggle( label, key, attributes, setAttributes ) {
		return el( c.ToggleControl, {
			label: label,
			checked: !! attributes[ key ],
			onChange: function ( value ) {
				var next = {};
				next[ key ] = value;
				setAttributes( next );
			},
		} );
	}

	registerBlockType( 'loupe-cross-site/search', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			var blockProps = useBlockProps( { className: 'lcss-search lcss-search--editor' } );

			var enabled = [];
			if ( attributes.showSiteFilter ) enabled.push( __( 'Site filter', 'loupe-cross-site-search' ) );
			if ( attributes.showTypeFilter ) enabled.push( __( 'Type filter', 'loupe-cross-site-search' ) );
			if ( attributes.showSort ) enabled.push( __( 'Sorting', 'loupe-cross-site-search' ) );
			if ( attributes.highlight ) enabled.push( __( 'Highlighting', 'loupe-cross-site-search' ) );

			return el(
				Fragment,
				null,
				el(
					InspectorControls,
					null,
					el(
						c.PanelBody,
						{ title: __( 'Content', 'loupe-cross-site-search' ) },
						el( c.TextControl, {
							label: __( 'Heading', 'loupe-cross-site-search' ),
							value: attributes.heading,
							onChange: function ( value ) { setAttributes( { heading: value } ); },
						} ),
						el( c.TextControl, {
							label: __( 'Placeholder', 'loupe-cross-site-search' ),
							value: attributes.placeholder,
							onChange: function ( value ) { setAttributes( { placeholder: value } ); },
						} )
					),
					el(
						c.PanelBody,
						{ title: __( 'Results', 'loupe-cross-site-search' ) },
						el( c.RangeControl, {
							label: __( 'Results per page', 'loupe-cross-site-search' ),
							value: attributes.perPage,
							min: 1,
							max: 50,
							onChange: function ( value ) { setAttributes( { perPage: value || 10 } ); },
						} ),
						toggle( __( 'Show excerpt', 'loupe-cross-site-search' ), 'showExcerpt', attributes, setAttributes ),
						toggle( __( 'Show date', 'loupe-cross-site-search' ), 'showDate', attributes, setAttributes ),
						toggle( __( 'Highlight matches', 'loupe-cross-site-search' ), 'highlight', attributes, setAttributes )
					),
					el(
						c.PanelBody,
						{ title: __( 'Sorting', 'loupe-cross-site-search' ) },
						toggle( __( 'Show sort control', 'loupe-cross-site-search' ), 'showSort', attributes, setAttributes ),
						el( c.SelectControl, {
							label: __( 'Default sort', 'loupe-cross-site-search' ),
							value: attributes.defaultSort,
							options: [
								{ label: __( 'Relevance', 'loupe-cross-site-search' ), value: 'relevance' },
								{ label: __( 'Newest', 'loupe-cross-site-search' ), value: 'newest' },
								{ label: __( 'Oldest', 'loupe-cross-site-search' ), value: 'oldest' },
								{ label: __( 'Title', 'loupe-cross-site-search' ), value: 'title' },
							],
							onChange: function ( value ) { setAttributes( { defaultSort: value } ); },
						} )
					),
					el(
						c.PanelBody,
						{ title: __( 'Filters', 'loupe-cross-site-search' ) },
						toggle( __( 'Site filter', 'loupe-cross-site-search' ), 'showSiteFilter', attributes, setAttributes ),
						toggle( __( 'Post type filter', 'loupe-cross-site-search' ), 'showTypeFilter', attributes, setAttributes )
					)
				),
				el(
					'div',
					blockProps,
					attributes.heading ? el( 'h2', { className: 'lcss-search__heading' }, attributes.heading ) : null,
					el(
						c.Disabled,
						null,
						el( 'input', {
							type: 'search',
							className: 'lcss-search__input',
							placeholder: attributes.placeholder,
							readOnly: true,
						} )
					),
					el(
						'p',
						{ className: 'lcss-search__hint' },
						enabled.length
							? __( 'Enabled:', 'loupe-cross-site-search' ) + ' ' + enabled.join( ', ' )
							: __( 'Cross-site search results appear here on the front end.', 'loupe-cross-site-search' )
					)
				)
			);
		},
		// Dynamic block: server renders the container, view.js hydrates it.
		save: function () {
			return null;
		},
	} );
} )( window.wp );
