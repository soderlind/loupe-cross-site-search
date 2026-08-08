/**
 * Editor script for the Cross-Site Search block.
 */
import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import {
	PanelBody,
	RangeControl,
	TextControl,
	ToggleControl,
	SelectControl,
	Disabled,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import metadata from './block.json';
import './style.scss';

function Toggle( { label, attr, attributes, setAttributes } ) {
	return (
		<ToggleControl
			label={ label }
			checked={ !! attributes[ attr ] }
			onChange={ ( value ) => setAttributes( { [ attr ]: value } ) }
			__nextHasNoMarginBottom
		/>
	);
}

registerBlockType( metadata.name, {
	edit( { attributes, setAttributes } ) {
		const blockProps = useBlockProps( { className: 'lcss-search lcss-search--editor' } );

		const enabled = [];
		if ( attributes.showSiteFilter ) enabled.push( __( 'Site filter', 'loupe-cross-site-search' ) );
		if ( attributes.showTypeFilter ) enabled.push( __( 'Type filter', 'loupe-cross-site-search' ) );
		if ( attributes.showSort ) enabled.push( __( 'Sorting', 'loupe-cross-site-search' ) );
		if ( attributes.highlight ) enabled.push( __( 'Highlighting', 'loupe-cross-site-search' ) );

		return (
			<>
				<InspectorControls>
					<PanelBody title={ __( 'Content', 'loupe-cross-site-search' ) }>
						<TextControl
							label={ __( 'Heading', 'loupe-cross-site-search' ) }
							value={ attributes.heading }
							onChange={ ( value ) => setAttributes( { heading: value } ) }
							__nextHasNoMarginBottom
						/>
						<TextControl
							label={ __( 'Placeholder', 'loupe-cross-site-search' ) }
							value={ attributes.placeholder }
							onChange={ ( value ) => setAttributes( { placeholder: value } ) }
							__nextHasNoMarginBottom
						/>
					</PanelBody>
					<PanelBody title={ __( 'Results', 'loupe-cross-site-search' ) }>
						<RangeControl
							label={ __( 'Results per page', 'loupe-cross-site-search' ) }
							value={ attributes.perPage }
							min={ 1 }
							max={ 50 }
							onChange={ ( value ) => setAttributes( { perPage: value || 10 } ) }
							__nextHasNoMarginBottom
						/>
						<Toggle label={ __( 'Show excerpt', 'loupe-cross-site-search' ) } attr="showExcerpt" attributes={ attributes } setAttributes={ setAttributes } />
						<Toggle label={ __( 'Show date', 'loupe-cross-site-search' ) } attr="showDate" attributes={ attributes } setAttributes={ setAttributes } />
						<Toggle label={ __( 'Highlight matches', 'loupe-cross-site-search' ) } attr="highlight" attributes={ attributes } setAttributes={ setAttributes } />
					</PanelBody>
					<PanelBody title={ __( 'Sorting', 'loupe-cross-site-search' ) }>
						<Toggle label={ __( 'Show sort control', 'loupe-cross-site-search' ) } attr="showSort" attributes={ attributes } setAttributes={ setAttributes } />
						<SelectControl
							label={ __( 'Default sort', 'loupe-cross-site-search' ) }
							value={ attributes.defaultSort }
							options={ [
								{ label: __( 'Relevance', 'loupe-cross-site-search' ), value: 'relevance' },
								{ label: __( 'Newest', 'loupe-cross-site-search' ), value: 'newest' },
								{ label: __( 'Oldest', 'loupe-cross-site-search' ), value: 'oldest' },
								{ label: __( 'Title', 'loupe-cross-site-search' ), value: 'title' },
							] }
							onChange={ ( value ) => setAttributes( { defaultSort: value } ) }
							__nextHasNoMarginBottom
						/>
					</PanelBody>
					<PanelBody title={ __( 'Filters', 'loupe-cross-site-search' ) }>
						<Toggle label={ __( 'Site filter', 'loupe-cross-site-search' ) } attr="showSiteFilter" attributes={ attributes } setAttributes={ setAttributes } />
						<Toggle label={ __( 'Post type filter', 'loupe-cross-site-search' ) } attr="showTypeFilter" attributes={ attributes } setAttributes={ setAttributes } />
					</PanelBody>
				</InspectorControls>

				<div { ...blockProps }>
					{ attributes.heading ? <h2 className="lcss-search__heading">{ attributes.heading }</h2> : null }
					<Disabled>
						<input
							type="search"
							className="lcss-search__input"
							placeholder={ attributes.placeholder }
							readOnly
						/>
					</Disabled>
					<p className="lcss-search__hint">
						{ enabled.length
							? __( 'Enabled:', 'loupe-cross-site-search' ) + ' ' + enabled.join( ', ' )
							: __( 'Cross-site search results appear here on the front end.', 'loupe-cross-site-search' ) }
					</p>
				</div>
			</>
		);
	},
	// Dynamic block: server renders the container, view.js hydrates it.
	save: () => null,
} );
