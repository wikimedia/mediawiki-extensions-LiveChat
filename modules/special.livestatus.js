/**
 *
 */
( function ( $, mw, OO ) {
	"use strict";

	var roomListLayout = new mw.livechat.widgets.RoomListLayout();

	$( function () {
		$( '#mw-content-text' ).append( roomListLayout.$element );
	} );

} )( jQuery, mediaWiki, OO );
