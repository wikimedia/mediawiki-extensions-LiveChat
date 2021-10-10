/**
 *
 */
( function ( mw, OO, $ ) {
	"use strict";

	mw.livechat = mw.livechat || {};
	mw.livechat.widgets = mw.livechat.widgets || {};
	mw.livechat.mixin = mw.livechat.mixin || {};

	mw.livechat.onApiError = function( code, data ) {
		var error;

		if ( data && data.exception ) {
			error = data.exception;
		} else {
			error = 'Error: ' + code;
			if ( data && data.error && data.error.info ) {
				error = error + ', ' + data.error.info;
			}
		}
		mw.log( arguments );
		mw.log.error( error );
		mw.notify( error, { title: 'Error', type: 'error' } );
		return error;
	};

	function pad( n ) {
		return n < 10 ? '0' + n : n;
	}

	/**
	 * Returns timestamp string like '20190106005141' from Date object
	 * @param {Date} date
	 * @returns {string}
	 */
	mw.livechat.getTimeStamp = function( date ) {
		return date.getFullYear() + pad( date.getMonth() + 1 ) + pad( date.getDate() ) +
				pad( date.getHours() ) + pad( date.getMinutes() ) + pad( date.getSeconds() );
	};

	/**
	 * It returns the number of milliseconds since January 1, 1970, 00:00:00 UTC
	 * from timestamp string like '20190106005141'
	 * @param {string} timestamp
	 * @returns number
	 */
	mw.livechat.parseTimeStamp = function( timestamp ) {
		if ( !timestamp ) {
			mw.log.warn( 'timestamp is null' );
			return Date.now();
		}

		return Date.UTC(
			parseInt( timestamp.substr( 0, 4 ) ), // YYYY
			parseInt( timestamp.substr( 4, 2 ) ) - 1, // MM
			parseInt( timestamp.substr( 6, 2 ) ), // DD
			parseInt( timestamp.substr( 8, 2 ) ), // hh
			parseInt( timestamp.substr( 10, 2 ) ), // mm
			parseInt( timestamp.substr( 12, 2 ) )// ss
		);
	};

	mw.livechat.timeDifferenceShort = function( previous, current ) {
		var msPerMinute = 60 * 1000,
			msPerHour = msPerMinute * 60,
			msPerDay = msPerHour * 24,
			msPerWeek = msPerDay * 7,
			msPerMonth = msPerDay * 30,
			msPerYear = msPerDay * 365,
			difference = (current || Date.now()) - previous,
			elapsed = Math.abs( difference ),
			text;

		if ( elapsed < msPerMinute ) {
			text = '< 1 minute';
		} else if ( elapsed < msPerHour ) {
			text = Math.round( elapsed / msPerMinute ) + ' minute(s)';
		} else if ( elapsed < msPerDay ) {
			text = Math.round( elapsed / msPerHour ) + ' hour(s)';
		} else if ( elapsed < msPerMonth ) {
			text = Math.round( elapsed / msPerDay ) + ' day(s)';
		} else if ( elapsed < msPerYear ) {
			text=  Math.round( elapsed / msPerWeek ) + ' week(s)';
		} else {
			text = Math.round( elapsed / msPerYear ) + ' year(s)';
		}

		if ( difference > 0 ) {
			return text + ' ago';
		}
		return 'in ' + text;
	};

	mw.livechat.removeGroupItems = function ( uiGroupElement ) {
		var oldItems = uiGroupElement.getItems();

		if ( oldItems.length > 0 ) {
			uiGroupElement.clearItems();
			$.each( oldItems, function( key, item ) {
				item.$element.remove();
			} );
		}
	};

}( mediaWiki, OO, jQuery ) );
