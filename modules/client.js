/**
 *
 */
( function ( $, mw, Visibility, OO ) {
	'use strict';

	mw.livechat = mw.livechat || {};
	mw.livechat.events = new OO.EventEmitter();

	var url = mw.config.get( 'LiveChatClientURL' ),
		dateStart = Date.now(),
		session = Math.random().toString().split(".")[1],
		pingDelay = 60000 + Math.random() * 10000,
		pool = [],
		connectCount = 0,
		attemptCount = 0,
		connected = false,
		lastVisibleState = null,
		roomStatistics = {},
		ping, ws;

	mw.livechat.getReadyState = function () {
		return ws && ws.readyState;
	};

	mw.livechat.events.on( 'LiveChatRoomStatistics', function ( data ) {
		var statistics = data.statistics || {};

		roomStatistics = $.extend( { online: 0 }, statistics );
		mw.livechat.events.emit( 'roomStatisticsChanged', roomStatistics );
	} );

	mw.livechat.events.on( 'LiveChatUserStatus', function ( data ) {
		var status = data.status;

		if ( status === 'join' ) {
			roomStatistics.online++;
		} else if ( status === 'left' ) {
			roomStatistics.online--;
		} else {
			return;
		}
		mw.livechat.events.emit( 'roomStatisticsChanged', roomStatistics );
	} );

	mw.livechat.getRoomStatistics = function () {
		return roomStatistics;
	};

	function getTime() {
		return Date.now() - dateStart;
	}

	function send( event, data ) {
		var time = getTime();

		data = $.extend( {
			event: event,
			time: time
		}, data );

		if ( ws.readyState === WebSocket.OPEN && pool.length === 0 ) {
			ws.send( JSON.stringify( data ) );
			mw.log( 'LiveChat send message: ' + event, data );
		} else {
			pool.push( data );
			mw.log( 'LiveChat push message to pool: ' + event, data );
		}

		ping();
		return time;
	}

	mw.livechat.send = send;

	function connectToServer() {
		attemptCount++;

		ws = new WebSocket( url );

		ws.onopen = function () {
			var config = mw.config,
				mwUri = new mw.Uri(),
				data = {
					event: 'connect',
					session: session,
					attemptCount: attemptCount,
					connectCount: ++connectCount,
					time: getTime(),
					pageName: config.get( 'wgPageName' ),
					isArticle: config.get( 'wgIsArticle' ),
					query: mwUri.query,
					visible: !Visibility.hidden(),
					state: Visibility.state(),
					backendResponse: config.get( 'wgBackendResponseTime' )
				},
				poolData;

			connected = true;
			ws.send( JSON.stringify( data ) );
			mw.log( 'LiveChat send connect event', data );

			while ( pool.length && ws.readyState === WebSocket.OPEN ) {
				poolData = pool.shift();
				poolData.poolTime = getTime();
				ws.send( JSON.stringify( poolData ) );
				mw.log( 'LiveChat send pool data', poolData );
			}

			mw.livechat.events.emit( 'connect', data );
			if ( connectCount > 1 ) {
				mw.livechat.events.emit( 'reconnect', data );
			}
			ping();
		};

		ws.onmessage = function ( e ) {
			var data = JSON.parse( e.data ) || {},
				event = data.event;

			mw.livechat.events.emit( event, data );
			// mw.hook( 'livechat.onmessage' ).fire( data );
			mw.log( 'LiveChat received message: ' + event, data );
			ping();
		};

		ws.onclose = function( e ) {
			if ( connected ) {
				connected = false;
				mw.livechat.events.emit( 'close', { time: getTime() } );
			}
			mw.log( 'LiveChat socket is closed. Reconnect will be attempted in 1 second. ' + e.reason );
			setTimeout( function() {
				connectToServer();
			}, 2000 + Math.random() * 3000 );
		};

		ws.onerror = function ( err ) {
			mw.log.warn( 'LiveChat error: ' + err.message );
			ws.close();
		};
	}

	Visibility.change( function ( e, state ) {
		if ( state === lastVisibleState ) {
			return;
		}

		var data = {
			visible: !Visibility.hidden(),
			state: state
		};

		lastVisibleState = state;
		send( 'visibility', data );
	} );

	mw.livechat.events.on( 'ErrorMessage', function ( data ) {
		var error = data.error;

		if ( error ) {
			mw.notification.notify( error, { type: 'error' } );
		}
	} );

	ping = mw.util.debounce( pingDelay, function () {
		if ( ws.readyState === WebSocket.OPEN && pool.length === 0 ) {
			send( 'ping' );
		} else {
			ping();
		}
	} );

	connectToServer();

} )( jQuery, mediaWiki, Visibility, OO );
