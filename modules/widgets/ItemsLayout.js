/**
 *
 */
( function ( $, mw, OO ) {
	"use strict";

	function ItemsLayout( config ) {
		// Configuration initialization
		config = $.extend( {
			expanded: false,
			framed: true,
			padded: true,
			emptyLabel: true
		}, config );

		// Parent constructor
		ItemsLayout.parent.call( this, config );

		// Mixin constructors
		this.$itemsElement = config.$itemsElement || $( '<div class="widget-livechat-items-layout">' );
		OO.ui.mixin.PendingElement.call( this, $.extend( { $pending: this.$element }, config ) );
		OO.ui.mixin.GroupElement.call( this, $.extend( {}, config, { $group: this.$itemsElement } ) );

		// Initialization
		this.$topElement = config.$topElement || $( '<div class="widget-livechat-top-element">');
		this.$bottomElement = config.$bottomElement || $( '<div class="widget-livechat-bottom-element">');
		this.$element.append( this.$topElement, this.$itemsElement, this.$bottomElement );

		this.emptyLabelText = config.emptyLabelText;

		if ( config.emptyLabel ) {
			this.$itemsElement.append( $( '<div class="widget-livechat-list-empty">' ).append( new OO.ui.ButtonWidget( {
				label: this.emptyLabelText || 'Empty list',
				framed: false,
				icon: 'info',
				disabled: true
			} ).$element ) );
		}

		if ( config.items ) {
			this.addItems( config.items );
		}
	}

	mw.livechat.widgets.ItemsLayout = ItemsLayout;

	OO.inheritClass( ItemsLayout, OO.ui.PanelLayout );
	OO.mixinClass( ItemsLayout, OO.ui.mixin.PendingElement );
	OO.mixinClass( ItemsLayout, OO.ui.mixin.GroupElement );

	ItemsLayout.prototype.addItems = function( items ) {
		$.each( items, function( index, obj ) {
			obj.$element.toggleClass( 'widget-livechat-item', true );
		} );

		return OO.ui.mixin.GroupElement.prototype.addItems.apply( this, arguments );
	};

	ItemsLayout.prototype.destroyItems = function () {
		mw.marefa.removeGroupItems( this );
	};

}( jQuery, mediaWiki, OO ) );
