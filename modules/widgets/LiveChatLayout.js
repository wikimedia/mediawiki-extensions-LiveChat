/**
 *
 */
( function ( $, mw, OO ) {
	"use strict";

	function LiveChatLayout( config ) {
		// Parent constructor
		LiveChatLayout.parent.call( this, { padded: false, framed: false } );

		var messagesTitle = new OO.ui.LabelWidget( { label: 'Comments', classes: [ 'livechat-titleElement' ] } ),
			replyGoBackButton = new OO.ui.ButtonWidget( { icon: 'previous', framed: false } ),
			replyUserLabel = new OO.ui.LabelWidget(),
			replyTitle = new OO.ui.HorizontalLayout( { items: [ replyGoBackButton, replyUserLabel ], classes: [ 'livechat-titleElement' ] });

		this.replyUserLabel = replyUserLabel;
		this.messages = new mw.livechat.widgets.MessagesLayout( config );
		this.messages.$topElement.append( messagesTitle.$element );

		this.replyMessages = new mw.livechat.widgets.MessagesLayout( $.extend( config, { connectToServer: false, replyButton: false } ) );
		this.replyMessages.toggle( false );
		this.replyMessages.$topElement.append( replyTitle.$element );

		this.$element.append( this.messages.$element, this.replyMessages.$element );

		// Events
		this.messages.connect( this, { messageReplyClick: 'onMessageReplyClick' } );
		replyGoBackButton.connect( this, { click: 'onReplyGoBackClick' } );
	}

	mw.livechat.widgets.LiveChatLayout = LiveChatLayout;

	OO.inheritClass( LiveChatLayout, OO.ui.PanelLayout );

	LiveChatLayout.prototype.connectToServer = function () {
		this.messages.connectToServer();
	};

	LiveChatLayout.prototype.onMessageReplyClick = function ( item ) {
		var itemMessageData = item.getMessageData(),
			itemId = itemMessageData.parentId || itemMessageData.id,
			userName = itemMessageData.parentUserName || itemMessageData.userName,
			labelText = 'Reply to: ' + userName;

		this.replyUserLabel.setLabel( labelText );
		this.messages.toggle( false );
		this.replyMessages.toggle( true );
		this.replyMessages.setParentItemId( itemId );
	};

	LiveChatLayout.prototype.onReplyGoBackClick = function () {
		this.replyMessages.toggle( false );
		this.messages.toggle( true );
	};

} )( jQuery, mediaWiki, OO );
