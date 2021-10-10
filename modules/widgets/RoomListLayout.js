/**
 *
 */
( function ( $, mw, OO ) {
	"use strict";

	function RoomListLayout( config ) {
		config = $.extend( {
			connectToServer: true
		}, config );

		// Parent constructor
		RoomListLayout.parent.call( this, config );

		this.online = mw.livechat.getReadyState() === WebSocket.OPEN;

		// Events
		mw.livechat.events.connect( this, {
			reconnect: 'onConnectionReconnected',
			close: 'onConnectionClose',
			LiveChatManagerListRooms: 'onLiveChatManagerListRooms'
		} );

		if ( config.connectToServer ) {
			this.connectToServer();
		}
	}

	mw.livechat.widgets.RoomListLayout = RoomListLayout;

	OO.inheritClass( RoomListLayout, mw.livechat.widgets.ItemsLayout );

	RoomListLayout.prototype.connectToServer = function () {
		this.connected = true;
		mw.livechat.send( 'getRoomList' );
		this.online = true;
	};

	RoomListLayout.prototype.onConnectionClose = function () {
		this.online = false;
	};

	RoomListLayout.prototype.onConnectionReconnected = function () {
		if ( this.connected ) {
			this.connectToServer();
		}
	};

	RoomListLayout.prototype.onLiveChatManagerListRooms = function ( response ) {
		var answer = response && response.answer || {},
			id, items = [];

		mw.livechat.removeGroupItems( this );
		for ( id in answer ) {
			if ( answer.hasOwnProperty( id ) ) {
				items.push( new OO.ui.LabelWidget( { label: answer[id], data: id } ) );
			}
		}
		if ( items.length ) {
			this.addItems( items );
		}
	};
} )( jQuery, mediaWiki, OO );
