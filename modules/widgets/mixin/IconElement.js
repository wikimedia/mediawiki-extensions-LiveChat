/**
 * @author Pavel Astakhov <pastakhov@yandex.ru>
 */
( function ( OO, $, mw ) {
	"use strict";

	function IconElement( config ) {
		// Configuration initialization
		config = $.extend( {
			faClass: 'far',
		}, config );

		// Properties
		this.$icon = null;
		this.icon = null;
		this.iconTitle = null;
		this.iconType = null;
		this.iconFixedWidth = !!config.iconFixedWidth;
		this.faClass = config.faClass;

		// Initialization
		this.setIcon( config.icon || this.constructor.static.icon );
		this.setIconTitle( config.iconTitle || this.constructor.static.iconTitle );
		this.setIconElement( config.$icon || $( '<i>' ) );
	}

	mw.livechat.mixin.IconElement = IconElement;

	/* Setup */
	OO.initClass( mw.livechat.mixin.IconElement );

	IconElement.static.icon = null;
	IconElement.static.iconTitle = null;

	IconElement.prototype.setIconElement = function ( $icon ) {
		if ( this.$icon ) {
			this.$icon
				.removeClass( 'livechat-iconElement-icon livechat-icon-' + this.icon )
				.removeAttr( 'title' );
		}

		this.$icon = $icon.addClass( 'livechat-iconElement-icon' );
		if ( this.iconType === 'FA' ) {
			$icon.toggleClass( this.faClass, !!this.icon );
			$icon.toggleClass( 'fa-' + this.icon, !!this.icon );
			$icon.toggleClass( 'fa-fw', this.iconFixedWidth );
		} else {
			$icon.toggleClass( 'livechat-icon-' + this.icon, !!this.icon );
		}

		this.updateThemeClasses();
	};

	IconElement.prototype.setFAClass = function ( faClass ) {
		if ( this.faClass !== faClass ) {
			if ( this.faClass ) {
				this.$icon.removeClass( this.faClass );
			}
			if ( faClass ) {
				this.$icon.addClass( faClass );
			}
			this.faClass = faClass;
		}
	};

	IconElement.prototype.setIcon = function ( icon ) {
		var iconType;

		icon = OO.isPlainObject( icon ) ? OO.ui.getLocalValue( icon, null, 'default' ) : icon;
		icon = typeof icon === 'string' && icon.trim().length ? icon.trim() : null;
		if ( icon && icon.substr( 0, 3 ) === 'fa-' ) {
			icon = icon.substr( 3 );
			iconType = 'FA';
		}

		if ( this.icon !== icon ) {
			if ( this.$icon ) {
				if ( this.icon !== null ) {
					if ( this.iconType === 'FA' ) {
						this.$icon.removeClass( this.faClass );
						this.$icon.removeClass( 'fa-' + this.icon );
						this.$icon.removeClass( 'fa-fw' );
					} else {
						this.$icon.removeClass( 'livechat-icon-' + this.icon );
					}
				}
				if ( icon !== null ) {
					if ( iconType === 'FA' ) {
						this.$icon.addClass( this.faClass );
						this.$icon.addClass( 'fa-' + icon );
						this.$icon.toggleClass( 'fa-fw', this.iconFixedWidth );
					} else {
						this.$icon.addClass( 'livechat-icon-' + icon );
					}
				}
			}
			this.icon = icon;
			this.iconType = iconType;
		}

		this.$element.toggleClass( 'livechat-iconElement', !!this.icon );
		this.updateThemeClasses();
		return this;
	};

	IconElement.prototype.setIconTitle = function ( iconTitle ) {
		iconTitle = typeof iconTitle === 'function' ||
			( typeof iconTitle === 'string' && iconTitle.length ) ?
				OO.ui.resolveMsg( iconTitle ) : null;

		if ( this.iconTitle !== iconTitle ) {
			this.iconTitle = iconTitle;
			if ( this.$icon ) {
				if ( this.iconTitle !== null ) {
					this.$icon.attr( 'title', iconTitle );
				} else {
					this.$icon.removeAttr( 'title' );
				}
			}
		}

		return this;
	};

}( OO, jQuery, mediaWiki ) );
