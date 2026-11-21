( function ( blocks, element, blockEditor, components, i18n ) {
	var el = element.createElement;
	var __ = i18n.__;
	var SelectControl = components.SelectControl;
	var TextControl = components.TextControl;
	var ToggleControl = components.ToggleControl;
	var useBlockProps = blockEditor.useBlockProps;

	blocks.registerBlockType( 'metamanager/sitemap', {
		title: __( 'Sitemap', 'metamanager' ),
		description: __( 'Displays the HTML sitemap with pages, posts, and custom post types.', 'metamanager' ),
		icon: 'index-card',
		category: 'widgets',
		attributes: {
			columns:  { type: 'number', default: 1 },
			orderBy:  { type: 'string', default: 'menu_order' },
			depth:    { type: 'number', default: 3 },
			showDate: { type: 'boolean', default: false }
		},

		edit: function ( props ) {
			var attrs = props.attributes;
			var setProps = props.setAttributes;
			var blockProps = useBlockProps();

			return el( 'div', blockProps,
				el( 'h3', {}, __( 'Sitemap', 'metamanager' ) ),
				el( TextControl, {
					label: __( 'Columns (1-3)', 'metamanager' ),
					type: 'number',
					value: attrs.columns,
					onChange: function ( v ) { setProps( { columns: parseInt( v ) || 1 } ); }
				} ),
				el( SelectControl, {
					label: __( 'Order By', 'metamanager' ),
					value: attrs.orderBy,
					options: [
						{ value: 'menu_order', label: __( 'Menu Order', 'metamanager' ) },
						{ value: 'title', label: __( 'Title', 'metamanager' ) },
						{ value: 'date', label: __( 'Date', 'metamanager' ) }
					],
					onChange: function ( v ) { setProps( { orderBy: v } ); }
				} ),
				el( TextControl, {
					label: __( 'Depth', 'metamanager' ),
					type: 'number',
					value: attrs.depth,
					onChange: function ( v ) { setProps( { depth: parseInt( v ) || 3 } ); }
				} ),
				el( ToggleControl, {
					label: __( 'Show Dates', 'metamanager' ),
					checked: attrs.showDate,
					onChange: function ( v ) { setProps( { showDate: v } ); }
				} )
			);
		},

		save: function () {
			return null; // Server-side rendering.
		}
	} );
} )( window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.components, window.wp.i18n );
