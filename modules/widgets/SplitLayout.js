/**
 *
 */
( function ( $, mw, OO ) {
	"use strict";

	mw.livechat.widgets.SplitLayout = SplitLayout;

	OO.inheritClass( SplitLayout, OO.ui.PanelLayout );

	function SplitLayout( config ) {
		// Configuration initialization
		config = $.extend( {
			expanded: false,
			framed: true,
			padded: false,
			minColumnWidth: 200
		}, config );

		// Parent constructor
		SplitLayout.parent.call( this, config );

		// Properties
		this.minColumnWidth = config.minColumnWidth;

		// Initialization
		this.$splitElement = config.$splitElement || $( '<div class="livechat-split-layout">' );
		this.$topElement = config.$topElement || $( '<div class="livechat-split-top">');
		this.$bottomElement = config.$bottomElement || $( '<div class="livechat-split-bottom">');

		this.$splitElement.append( this.$element.children() );
		this.$element.append( this.$topElement, this.$splitElement, this.$bottomElement );

		this.split();
	}

	SplitLayout.prototype.setContent = function ( content ) {
		this.$splitElement.empty().append( content.map( function ( v ) {
			if ( typeof v === 'string' ) {
				// Escape string so it is properly represented in HTML.
				return document.createTextNode( v );
			} else if ( v instanceof OO.ui.HtmlSnippet ) {
				// Bypass escaping.
				return v.toString();
			} else if ( v instanceof OO.ui.Element ) {
				return v.$element;
			}
			return v;
		} ) );

		this.split();
	};

	SplitLayout.prototype.split = function () {
		var widget = this,
			children = this.$splitElement.children();

		this.$splitElement.toggleClass( 'livechat-splitLayout', true );

		$.each( children, function ( index, $element ) {
			var wrapper = $( '<div>' ).append( $element );
			widget.$splitElement.append( wrapper );
		} );

		this.$splitElement.children( ':first' ).resizable( {
			handles: 'e',
			minWidth: this.minColumnWidth,
			resize: this.onSplitResize.bind( this )
		} );
	};

	SplitLayout.prototype.onSplitResize = function ( event, ui ) {
		var $nextElement = ui.element.next(),
			nextElemWidth = this.$splitElement.width() - ui.element.width(),
			minColumnWidth = this.minColumnWidth;

		if ( nextElemWidth < minColumnWidth ) {
			ui.element.width( this.$splitElement.width() - minColumnWidth );
			$nextElement.width( minColumnWidth );
		} else {
			$nextElement.width( nextElemWidth );
		}

		ui.element.height( '' );
	};

}( jQuery, mediaWiki, OO ) );
