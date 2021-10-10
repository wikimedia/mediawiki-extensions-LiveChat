/**
 * @author Pavel Astakhov <pastakhov@yandex.ru>
 */
( function ( OO, $, mw ) {
	"use strict";

	function IconWidget( config ) {
		// Configuration initialization
		config = config || {};
		this.faIcon = config.faIcon;

		// Parent constructor
		IconWidget.parent.call( this, config );

		// Mixin constructors
		mw.livechat.mixin.IconElement.call( this, $.extend( {}, config, { $icon: this.$element } ) );
	}

	mw.livechat.widgets.IconWidget = IconWidget;

	OO.inheritClass( IconWidget, OO.ui.Widget );
	OO.mixinClass( IconWidget, mw.livechat.mixin.IconElement );

	IconWidget.static.tagName = 'i';

}( OO, jQuery, mediaWiki ) );
