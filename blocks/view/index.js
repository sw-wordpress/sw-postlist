/**
 * Editor script of the Postlist block (no build step: WordPress globals only).
 * The block is dynamic: the markup is rendered on the server with the same renderer as shortcode and REST preview.
 */
( function ( wp ) {
	const { registerBlockType } = wp.blocks;
	const { createElement: el, Fragment, useEffect, useState } = wp.element;
	const { InspectorControls, useBlockProps } = wp.blockEditor;
	const { PanelBody, SelectControl, TextControl, Placeholder, Spinner } = wp.components;
	const { __ } = wp.i18n;
	const apiFetch = wp.apiFetch;
	const ServerSideRender = wp.serverSideRender;

	registerBlockType( 'sw-postlist/view', {
		edit: function ( { attributes, setAttributes } ) {
			const blockProps = useBlockProps();
			const [ views, setViews ] = useState( null );

			useEffect( () => {
				apiFetch( { path: '/sw-postlist/v1/views' } )
					.then( ( items ) => setViews( items ) )
					.catch( () => setViews( [] ) );
			}, [] );

			const options = [
				{ value: 0, label: __( 'Select a view', 'sw-postlist' ) },
				...( views || [] ).map( ( view ) => ( { value: view.id, label: view.title } ) ),
			];

			const controls = el(
				InspectorControls,
				null,
				el(
					PanelBody,
					{ title: __( 'View', 'sw-postlist' ) },
					el( SelectControl, {
						label: __( 'View', 'sw-postlist' ),
						value: attributes.viewId,
						options,
						onChange: ( value ) => setAttributes( { viewId: parseInt( value, 10 ) || 0 } ),
					} ),
					el( TextControl, {
						label: __( 'Instance key (optional)', 'sw-postlist' ),
						help: __( 'Needed to tell two identical views on one page apart in URLs.', 'sw-postlist' ),
						value: attributes.instanceKey,
						onChange: ( value ) => setAttributes( { instanceKey: value } ),
					} ),
					el( TextControl, {
						label: __( 'Entries per page (optional)', 'sw-postlist' ),
						value: attributes.perPage,
						onChange: ( value ) => setAttributes( { perPage: value } ),
					} ),
					el( SelectControl, {
						label: __( 'Layout (optional)', 'sw-postlist' ),
						value: attributes.layout,
						options: [
							{ value: '', label: __( 'As defined in the view', 'sw-postlist' ) },
							{ value: 'grid', label: __( 'Grid', 'sw-postlist' ) },
							{ value: 'list', label: __( 'List', 'sw-postlist' ) },
						],
						onChange: ( value ) => setAttributes( { layout: value } ),
					} )
				)
			);

			if ( views === null ) {
				return el( 'div', blockProps, controls, el( Spinner ) );
			}
			if ( ! attributes.viewId ) {
				return el(
					'div',
					blockProps,
					controls,
					el( Placeholder, { icon: 'list-view', label: __( 'Postlist', 'sw-postlist' ) }, __( 'Choose a view in the block settings.', 'sw-postlist' ) )
				);
			}
			return el(
				Fragment,
				null,
				controls,
				el( 'div', blockProps, el( ServerSideRender, { block: 'sw-postlist/view', attributes } ) )
			);
		},
		save: () => null,
	} );
} )( window.wp );
