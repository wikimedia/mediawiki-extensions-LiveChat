/**
 *
 */
( function ( OO, $, mw ) {
	"use strict";

	mw.livechat.widgets.ButtonWidget = ButtonWidget;

	/* Setup */
	OO.inheritClass( ButtonWidget, OO.ui.Widget );
	OO.mixinClass( ButtonWidget, OO.ui.mixin.ButtonElement );
	OO.mixinClass( ButtonWidget, mw.livechat.mixin.IconElement );
	OO.mixinClass( ButtonWidget, OO.ui.mixin.IndicatorElement );
	OO.mixinClass( ButtonWidget, OO.ui.mixin.LabelElement );
	OO.mixinClass( ButtonWidget, OO.ui.mixin.TitledElement );
	OO.mixinClass( ButtonWidget, OO.ui.mixin.FlaggedElement );
	OO.mixinClass( ButtonWidget, OO.ui.mixin.TabIndexedElement );
	OO.mixinClass( ButtonWidget, OO.ui.mixin.AccessKeyedElement );
	OO.mixinClass( ButtonWidget, OO.ui.mixin.PendingElement );

	function ButtonWidget ( config ) {
		// Configuration initialization
		config = config || {};

		// Parent constructor
		ButtonWidget.parent.call( this, config );

		// Mixin constructors
		OO.ui.mixin.ButtonElement.call( this, config );
		mw.livechat.mixin.IconElement.call( this, config );
		OO.ui.mixin.IndicatorElement.call( this, config );
		OO.ui.mixin.LabelElement.call( this, config );
		OO.ui.mixin.TitledElement.call( this, $.extend( {}, config, { $titled: this.$button } ) );
		OO.ui.mixin.FlaggedElement.call( this, config );
		OO.ui.mixin.TabIndexedElement.call( this, $.extend( {}, config, { $tabIndexed: this.$button } ) );
		OO.ui.mixin.AccessKeyedElement.call( this, $.extend( {}, config, { $accessKeyed: this.$button } ) );
		OO.ui.mixin.PendingElement.call( this, $.extend( {}, config, { $pending: this.$element } ) );

		// Properties
		this.href = null;
		this.target = null;
		this.noFollow = false;

		// Events
		this.connect( this, { disable: 'onDisable' } );

		// Initialization
		if ( config.iconAtTheEnd ) {
			this.$button.append( this.$indicator, this.$label, this.$icon ).addClass( 'iconAtTheEnd' );
		} else {
			this.$button.append( this.$icon, this.$label, this.$indicator );
		}
		this.$element
			.addClass( 'oo-ui-buttonWidget' )
			.append( this.$button );
		this.setActive( config.active );
		this.setHref( config.href );
		this.setTarget( config.target );
		this.setNoFollow( config.noFollow );
	}

	/* Static Properties */

	/**
	 * @static
	 * @inheritdoc
	 */
	ButtonWidget.static.cancelButtonMouseDownEvents = false;

	/**
	 * @static
	 * @inheritdoc
	 */
	ButtonWidget.static.tagName = 'span';

	/* Methods */

	/**
	 * Get hyperlink location.
	 *
	 * @return {string} Hyperlink location
	 */
	ButtonWidget.prototype.getHref = function () {
		return this.href;
	};

	/**
	 * Get hyperlink target.
	 *
	 * @return {string} Hyperlink target
	 */
	ButtonWidget.prototype.getTarget = function () {
		return this.target;
	};

	/**
	 * Get search engine traversal hint.
	 *
	 * @return {boolean} Whether search engines should avoid traversing this hyperlink
	 */
	ButtonWidget.prototype.getNoFollow = function () {
		return this.noFollow;
	};

	/**
	 * Set hyperlink location.
	 *
	 * @param {string|null} href Hyperlink location, null to remove
	 */
	ButtonWidget.prototype.setHref = function ( href ) {
		href = typeof href === 'string' ? href : null;
		if ( href !== null && !OO.ui.isSafeUrl( href ) ) {
			href = './' + href;
		}

		if ( href !== this.href ) {
			this.href = href;
			this.updateHref();
		}

		return this;
	};

	/**
	 * Update the `href` attribute, in case of changes to href or
	 * disabled state.
	 *
	 * @private
	 * @chainable
	 */
	ButtonWidget.prototype.updateHref = function () {
		if ( this.href !== null && !this.isDisabled() ) {
			this.$button.attr( 'href', this.href );
		} else {
			this.$button.removeAttr( 'href' );
		}

		return this;
	};

	/**
	 * Handle disable events.
	 *
	 * @private
	 */
	ButtonWidget.prototype.onDisable = function () {
		this.updateHref();
	};

	/**
	 * Set hyperlink target.
	 *
	 * @param {string|null} target Hyperlink target, null to remove
	 */
	ButtonWidget.prototype.setTarget = function ( target ) {
		target = typeof target === 'string' ? target : null;

		if ( target !== this.target ) {
			this.target = target;
			if ( target !== null ) {
				this.$button.attr( 'target', target );
			} else {
				this.$button.removeAttr( 'target' );
			}
		}

		return this;
	};

	/**
	 * Set search engine traversal hint.
	 *
	 * @param {boolean} noFollow True if search engines should avoid traversing this hyperlink
	 */
	ButtonWidget.prototype.setNoFollow = function ( noFollow ) {
		noFollow = typeof noFollow === 'boolean' ? noFollow : true;

		if ( noFollow !== this.noFollow ) {
			this.noFollow = noFollow;
			if ( noFollow ) {
				this.$button.attr( 'rel', 'nofollow' );
			} else {
				this.$button.removeAttr( 'rel' );
			}
		}

		return this;
	};

}( OO, jQuery, mediaWiki ) );
