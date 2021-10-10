/**
 *
 */
( function ( $, mw, OO ) {
	"use strict";

	function MessageInput( config ) {
		config = config || {};

		// Construct buttons before parent method is called (calling setDisabled)
		this.sendButton = new OO.ui.ButtonWidget( $.extend( {
			$element: $( '<label>' ),
			classes: [ 'livechat-MessageInput-sendButton' ],
			label: OO.ui.msg( 'ext-livechat-messageinput-button-send' )
		}, config.button ) );

		// Configuration initialization
		config = $.extend( {
			placeholder: OO.ui.msg( 'ext-livechat-messageinput-placeholder' )
		}, config );

		this.messageInput = new OO.ui.TextInputWidget( {
			classes: [ 'livechat-MessageInput-message' ],
			placeholder: config.placeholder
		} );

		// Parent constructor
		MessageInput.parent.call( this, config );

		// Mixin constructors
		OO.ui.mixin.PendingElement.call( this, $.extend( {}, config, { $pending: this.$element } ) );

		this.fieldLayout = new OO.ui.ActionFieldLayout( this.messageInput, this.sendButton, { align: 'top' } );

		//this.sendButton.$button.append( this.$input );

		this.$element
			.addClass( 'livechat-MessageInput' )
			.append( this.fieldLayout.$element );

		// Events
		this.sendButton.connect( this, { click: 'onSendClick' } );
		this.messageInput.connect( this, { enter: 'onSendClick' } );
		mw.livechat.events.connect( this, {
			LiveChatMessageConfirm: 'onMessageConfirm',
			permissions: 'onPermissions',
		} );
	}

	mw.livechat.widgets.MessageInput = MessageInput;

	OO.inheritClass( MessageInput, OO.ui.Widget );
	OO.mixinClass( MessageInput, OO.ui.mixin.PendingElement );

	MessageInput.prototype.connectToServer = function() {
		mw.livechat.send( 'getPermissions', { list: [ 'canPost' ] } );
	};

	MessageInput.prototype.onSendClick = function () {
		var message = this.messageInput.getValue(),
			data = { message: message };

		if ( this.parentId ) {
			data.parentId = this.parentId;
		}

		this.pushPending();
		this.messageInput.setDisabled( true );
		this.sendButton.setDisabled( true );
		this.time = mw.livechat.send( 'LiveChatMessage', data );
	};

	MessageInput.prototype.onMessageConfirm = function( messageData ) {
		if ( ( !messageData.clientTime || messageData.clientTime === this.time ) &&
			( !this.parentId || this.parentId === messageData.parentId )
		) {
			this.messageInput.setValue('');
			this.messageInput.setDisabled( false );
			this.sendButton.setDisabled( false );
			this.popPending();
		}
		if ( messageData.error ) {
			mw.notify( messageData.error, { type: 'error' } );
		}
	};

	MessageInput.prototype.onPermissions = function( info ) {
		var permissions = info && info.permissions || {},
			$loginLink, loginText;

		if ( permissions.canPost === false ) {
			this.messageInput.setDisabled( true );
			this.sendButton.setDisabled( true );
			if ( mw.config.get( 'wgUserName' ) === null ) {
				loginText = OO.ui.msg( 'ext-livechat-login-to-post-comments' );
				$loginLink = $( '<a>' )
					.text( loginText )
					.attr( { href: mw.util.getUrl( 'Special:UserLogin' ) } );
				this.$element.prepend( $loginLink );
				this.messageInput.setTitle( loginText );
				this.sendButton.setTitle( loginText );
			}
		}
	};

	MessageInput.prototype.setParentId = function( parentId ) {
		if ( this.parentId !== parentId ) {
			this.messageInput.setValue('');
			this.messageInput.setDisabled( false );
			this.sendButton.setDisabled( false );
			if ( this.isPending() ) {
				this.popPending();
			}
			this.parentId = parentId;
		}
	};

}( jQuery, mediaWiki, OO ) );
