/**
 *
 */
( function ( $, mw, OO ) {
	"use strict";

	function MessagesLayout( config ) {
		var $notificationPlaceholder;

		// Configuration initialization
		config = $.extend( {
			emptyLabelText: OO.ui.msg( 'ext-livechat-no-messages' ),
			replyButton: true,
			scrollDownOnDocumentReady: true,
			connectToServer: true
		}, config );

		// Parent constructor
		MessagesLayout.parent.call( this, config );

		// Initialization
		this.lastMessageId = 0;
		this.replyButton = config.replyButton;

		this.newMessages = 0;
		$notificationPlaceholder = $( '<div>' )
			.addClass( 'notification' )
			.insertAfter( this.$itemsElement );
		this.$newMessageNotification = $( '<span>' )
			.toggle( false )
			.addClass( 'notification' )
			.click( this.scrollDown.bind( this ) )
			.appendTo( $notificationPlaceholder );
		this.$itemsElement.scroll( this.onScrollItemsArea.bind( this ) );

		this.messageInput = new mw.livechat.widgets.MessageInput();
		this.$bottomElement.append( this.messageInput.$element );
		this.$element.addClass( 'oo-ui-MessagesLayout' );

		if ( config.scrollDownOnDocumentReady ) {
			$( function () {
				setTimeout( this.scrollDown, 1000 );
			}.bind( this ) );
		}

		// Events
		mw.livechat.events.connect( this, {
			LiveChatMessageConfirm: 'onLiveChatMessageConfirm',
			LiveChatMessage: 'onLiveChatMessage',
			LiveChatHistory: 'onLiveChatHistory',
			LiveChatReaction: 'onLiveChatReaction',
			// LiveChatReactionConfirm: 'onLiveChatReactionConfirm',
			close: 'onLiveChatClose'
		} );

		this.aggregate( { replyClick: 'messageReplyClick' } );

		if ( config.connectToServer ) {
			this.connectToServer();
		}
	}

	mw.livechat.widgets.MessagesLayout = MessagesLayout;

	OO.inheritClass( MessagesLayout, mw.livechat.widgets.ItemsLayout );

	MessagesLayout.prototype.connectToServer = function () {
		var data = {};

		this.connected = true;
		if ( this.parentMessageId ) {
			data.parentId = this.parentMessageId;
		}
		mw.livechat.removeGroupItems( this );
		mw.livechat.send( 'getLiveChatHistory', data );
		this.messageInput.connectToServer();
	};

	MessagesLayout.prototype.disconnectFromServer = function () {
		this.connected = false;
	};

	MessagesLayout.prototype.canScrollDownAutomatically = function() {
		return this.$itemsElement.scrollTop() >= this.$itemsElement.prop( "scrollHeight" ) - this.$itemsElement.innerHeight() - 77;
	};

	MessagesLayout.prototype.scrollDown = OO.ui.debounce( function() {
		if ( this.$itemsElement ) {
			this.$itemsElement.scrollTop( this.$itemsElement.prop( "scrollHeight" ) - this.$itemsElement.innerHeight() + 1 );
			this.newMessages = 0;
			this.$newMessageNotification.toggle( false );
		}
	}, 200 );

	MessagesLayout.prototype.informAboutNewMessage = function() {
		this.newMessages++;
		if ( this.newMessages === 1 ) {
			this.$newMessageNotification.toggle( true );
		}
		this.$newMessageNotification.text( mw.msg( 'ext-livechat-new-comment-notification', this.newMessages ) );
	};

	MessagesLayout.prototype.onScrollItemsArea = OO.ui.debounce( function() {
		if ( this.newMessages && this.canScrollDownAutomatically() ) {
			this.newMessages = 0;
			this.$newMessageNotification.toggle( false );
		}
	}, 50 );

	MessagesLayout.prototype.onLiveChatMessage = function ( msg ) {
		var messageData = msg.messageData || {},
			parentItem, parentMessageData;

		if ( !this.connected ||
			( this.parentMessageId && this.parentMessageId !== messageData.parentId && this.parentMessageId !== messageData.id )
		) {
			return;
		}

		if ( !this.parentMessageId && messageData.parentId ) {
			// update reply button text if not updated
			parentItem = this.findItemFromData( messageData.parentId );
			if ( parentItem ) {
				parentMessageData = parentItem.getMessageData();
				if ( !parentMessageData.hasChildren ) {
					parentMessageData.hasChildren = true;
					parentItem.updateReplyButtonText();
				}
			}
		}

		var canScrollDown = this.canScrollDownAutomatically(),
			classes = this.parentMessageId && this.parentMessageId !== messageData.id ? [ 'livechat-Message-reply' ] : null,
			message = new mw.livechat.widgets.Message( {
				messageData: messageData,
				replyButton: this.replyButton,
				classes: classes
			} );

		this.updateLastId( messageData.id );

		if ( message.isValid() ) {
			this.addItems( [ message ] );

			if ( canScrollDown ) {
				this.scrollDown();
			} else {
				this.informAboutNewMessage();
			}
		}
	};

	MessagesLayout.prototype.onLiveChatHistory = function( historyData ) {
		if ( !this.connected || this.parentMessageId !== historyData.parentId ) {
			// Skip history of replies on main chat or history of another replies
			return ;
		}

		var messages = historyData.messages || [];

		$.each( messages, function ( i, messageData ) {
			this.onLiveChatMessage( { messageData: messageData } );
		}.bind( this ) );

		//this.updateLastId( historyData.id );
	};

	MessagesLayout.prototype.onLiveChatMessageConfirm = function( messageData ) {
		this.onLiveChatMessage( messageData );
	};

	MessagesLayout.prototype.onLiveChatClose = function() {
		this.loadHistoryFromLastMessage();
	};

	MessagesLayout.prototype.loadHistoryFromLastMessage = function () {
		var lastId = this.lastMessageId,
			data;

		if ( lastId && this.connected ) {
			data = { fromId: lastId };
			if ( this.parentMessageId ) {
				data.parentId = this.parentMessageId;
			}
			mw.livechat.send( 'getLiveChatHistory', data );
		}
	};

	MessagesLayout.prototype.updateLastId = function( id ) {
		var intId = parseInt( id );

		if ( intId && intId > this.lastMessageId ) {
			this.lastMessageId = id;
		}
	};

	MessagesLayout.prototype.onLiveChatReactionConfirm = function ( messageData ) {
		var msg = this.onLiveChatReaction( messageData );
		if ( msg ) {
			msg.setUserReaction( messageData.reaction );
		}
	};

	MessagesLayout.prototype.onLiveChatReaction = function ( messageData ) {
		var id = messageData.id,
			msg = this.findItemFromData( id );

		if ( msg ) {
			msg.setReactions( messageData.messageReactions );
		}
		return msg;
	};

	MessagesLayout.prototype.setParentItemId = function( parentId ) {
		this.parentMessageId = parentId;
		this.messageInput.setParentId( parentId );
		this.connectToServer();
	};

} )( jQuery, mediaWiki, OO );
