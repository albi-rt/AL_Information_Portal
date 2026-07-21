/**
 * Editor integration for the theme's dynamic (server-rendered) blocks.
 *
 * Two kinds of blocks are registered client-side here:
 *   1. Editorial blocks (hero, stats, cta…) — driven by the PHP field config
 *      localized as window.NSW_BLOCKS. Each gets a Settings-sidebar panel
 *      (text / textarea / image controls) plus a live ServerSideRender preview.
 *      The block stays server-rendered (one source of markup); the sidebar just
 *      feeds it attributes.
 *   2. Structural blocks (footer pieces, nav, page hero…) — no user fields, just
 *      a ServerSideRender preview so the editor recognizes them.
 *
 * apiVersion 3 renders the block inside the editor iframe. The edit wrapper must
 * carry useBlockProps() (otherwise the block can't be selected / moved / removed);
 * all our styles reach the iframe via add_editor_style(), and ServerSideRender /
 * MediaUpload are iframe-safe, so no per-block changes are needed.
 */
( function ( wp ) {
	if ( ! wp || ! wp.blocks || ! wp.element || ! wp.blockEditor || ! wp.components || ! wp.serverSideRender ) {
		return;
	}
	var el       = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var SSR      = wp.serverSideRender;

	var useBlockProps     = wp.blockEditor.useBlockProps;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var MediaUpload       = wp.blockEditor.MediaUpload;
	var MediaUploadCheck  = wp.blockEditor.MediaUploadCheck;

	var PanelBody       = wp.components.PanelBody;
	var TextControl     = wp.components.TextControl;
	var TextareaControl = wp.components.TextareaControl;
	var Button          = wp.components.Button;

	var CONFIG = window.NSW_BLOCKS || {};

	/** Build the attribute schema from a field list (must match PHP). */
	function attrsFromFields( fields ) {
		var attrs = {};
		( fields || [] ).forEach( function ( f ) {
			if ( 'media' === f.type ) {
				// Image-or-video. Defaults come from the config so the picker
				// shows the current hero background as selected.
				attrs[ f.attr ]          = { type: 'string', default: f.default || '' };
				attrs[ f.attr + 'Id' ]   = { type: 'number', default: f.defaultId || 0 };
				attrs[ f.attr + 'Mime' ] = { type: 'string', default: f.defaultMime || '' };
			} else if ( 'image' === f.type ) {
				attrs[ f.attr ]        = { type: 'string', default: '' };
				attrs[ f.attr + 'Id' ] = { type: 'number', default: 0 };
			} else {
				attrs[ f.attr ] = { type: 'string', default: '' };
			}
		} );
		return attrs;
	}

	/** True if a mime/url looks like a video. */
	function isVideoMedia( mime, url ) {
		if ( mime ) {
			return mime.indexOf( 'video' ) === 0;
		}
		return /\.(mp4|webm|ogv|mov)(\?.*)?$/i.test( url || '' );
	}

	/** Render one sidebar control for a field. */
	function fieldControl( props, f ) {
		var value = props.attributes[ f.attr ] || '';

		function setOne( v ) {
			var patch = {};
			patch[ f.attr ] = v;
			props.setAttributes( patch );
		}

		if ( 'textarea' === f.type ) {
			return el( TextareaControl, {
				key: f.attr, label: f.label, value: value,
				placeholder: f.default || '', onChange: setOne,
				__nextHasNoMarginBottom: true,
			} );
		}

		if ( 'media' === f.type ) {
			// Image OR video, with a live preview of the current selection.
			var mId    = f.attr + 'Id';
			var mMime  = f.attr + 'Mime';
			var video  = isVideoMedia( props.attributes[ mMime ] || '', value );
			return el( MediaUploadCheck, { key: f.attr },
				el( 'div', { className: 'nsw-field-media', style: { marginBottom: '16px' } },
					el( 'p', { style: { margin: '0 0 4px' } }, f.label ),
					value ? ( video
						? el( 'video', { src: value, muted: true, loop: true, autoPlay: true, playsInline: true, style: { display: 'block', maxWidth: '100%', marginBottom: '8px', borderRadius: '4px' } } )
						: el( 'img', { src: value, alt: '', style: { display: 'block', maxWidth: '100%', marginBottom: '8px', borderRadius: '4px' } } )
					) : null,
					el( MediaUpload, {
						allowedTypes: [ 'image', 'video' ],
						value: props.attributes[ mId ],
						onSelect: function ( media ) {
							var patch = {};
							patch[ f.attr ] = media.url;
							patch[ mId ]    = media.id;
							patch[ mMime ]  = media.mime || media.mime_type || '';
							props.setAttributes( patch );
						},
						render: function ( o ) {
							return el( Button, { variant: 'secondary', onClick: o.open }, value ? 'Replace media' : 'Select media' );
						},
					} ),
					value ? el( Button, {
						variant: 'link', isDestructive: true,
						style: { marginLeft: '8px' },
						onClick: function () {
							var patch = {};
							patch[ f.attr ] = '';
							patch[ mId ]    = 0;
							patch[ mMime ]  = '';
							props.setAttributes( patch );
						},
					}, 'Remove' ) : null
				)
			);
		}

		if ( 'image' === f.type ) {
			var idAttr = f.attr + 'Id';
			return el( MediaUploadCheck, { key: f.attr },
				el( 'div', { className: 'nsw-field-image', style: { marginBottom: '16px' } },
					el( 'p', { style: { margin: '0 0 4px' } }, f.label ),
					value ? el( 'img', { src: value, alt: '', style: { display: 'block', maxWidth: '100%', marginBottom: '8px', borderRadius: '4px' } } ) : null,
					el( MediaUpload, {
						allowedTypes: [ 'image' ],
						value: props.attributes[ idAttr ],
						onSelect: function ( media ) {
							var patch = {};
							patch[ f.attr ]  = media.url;
							patch[ idAttr ]  = media.id;
							props.setAttributes( patch );
						},
						render: function ( o ) {
							return el( Button, { variant: 'secondary', onClick: o.open }, value ? 'Replace image' : 'Select image' );
						},
					} ),
					value ? el( Button, {
						variant: 'link', isDestructive: true,
						style: { marginLeft: '8px' },
						onClick: function () {
							var patch = {};
							patch[ f.attr ] = '';
							patch[ idAttr ] = 0;
							props.setAttributes( patch );
						},
					}, 'Remove' ) : null
				)
			);
		}

		// text / url
		return el( TextControl, {
			key: f.attr, label: f.label, value: value,
			type: 'url' === f.type ? 'url' : 'text',
			placeholder: f.default || '', onChange: setOne,
			__next40pxDefaultSize: true,
			__nextHasNoMarginBottom: true,
		} );
	}

	function makeEdit( name, fields ) {
		return function ( props ) {
			// useBlockProps() applies the Dimensions (spacing) styles to THIS
			// wrapper in the editor. So we render the server preview WITHOUT the
			// spacing (strip `style`) to avoid applying it twice in the canvas —
			// the real front-end spacing is added by get_block_wrapper_attributes()
			// in PHP.
			var blockProps = useBlockProps( { className: 'nsw-ssr-preview' } );
			var ssrAttrs = Object.assign( {}, props.attributes );
			delete ssrAttrs.style;
			return el( Fragment, {},
				( fields && fields.length )
					? el( InspectorControls, {},
						el( PanelBody, { title: 'Content', initialOpen: true },
							fields.map( function ( f ) { return fieldControl( props, f ); } )
						)
					  )
					: null,
				el( 'div', blockProps, el( SSR, { block: name, attributes: ssrAttrs } ) )
			);
		};
	}

	function register( name, title, attributes, fields, supports ) {
		if ( wp.blocks.getBlockType( name ) ) {
			return;
		}
		wp.blocks.registerBlockType( name, {
			apiVersion: 3,
			title: title,
			category: 'nsw-theme',
			attributes: attributes || {},
			supports: supports || {},
			edit: makeEdit( name, fields ),
			save: function () { return null; },
		} );
	}

	// Editorial section blocks also get Dimensions (margin/padding) controls,
	// shown under the block's "Styles" tab.
	var SECTION_SUPPORTS = { spacing: { margin: [ 'top', 'bottom' ], padding: true } };

	// 1) Editorial blocks with sidebar fields (from PHP config).
	Object.keys( CONFIG ).forEach( function ( name ) {
		var c = CONFIG[ name ];
		register( name, c.title || name, attrsFromFields( c.fields ), c.fields, SECTION_SUPPORTS );
	} );

	// 2) Structural / data blocks — preview only, no user fields.
	[
		[ 'nsw-theme/primary-nav', 'NSW Primary Navigation' ],
		[ 'nsw-theme/page-hero', 'NSW Page Hero' ],
		[ 'nsw-theme/news-list', 'NSW News List' ],
		[ 'nsw-theme/single-post', 'NSW Single Article' ],
		[ 'nsw-theme/not-found', 'NSW Not Found (404)' ],
		[ 'nsw-theme/footer-logo', 'NSW Footer Logo' ],
		[ 'nsw-theme/footer-contact', 'NSW Footer Contact' ],
		// Data-driven page blocks.
		[ 'nsw-theme/agencies', 'NSW Agencies (full list)' ],
		[ 'nsw-theme/partners-page', 'NSW Partners (full page)' ],
		[ 'nsw-theme/faq', 'NSW FAQ' ],
		[ 'nsw-theme/documents', 'NSW Documents' ],
		[ 'nsw-theme/events', 'NSW Events' ],
		[ 'nsw-theme/contact-form', 'NSW Contact Form' ],
	].forEach( function ( b ) {
		register( b[0], b[1], {} );
	} );

	// Attribute-driven structural blocks (config, not user-facing fields).
	register( 'nsw-theme/footer-text', 'NSW Footer Text', {
		tkey: { type: 'string', default: '' },
		tag:  { type: 'string', default: 'p' },
		cls:  { type: 'string', default: '' },
		year: { type: 'boolean', default: false },
	} );
	register( 'nsw-theme/nav', 'NSW Navigation Menu', {
		menu: { type: 'string', default: '' },
	} );
} )( window.wp );
