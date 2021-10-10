/**
 *
 */
( function ( $, mw, OO ) {
	"use strict";

	var roomListLayout = new mw.livechat.widgets.RoomListLayout();

	$( document ).ready( function() {
		$( '#mw-content-text' ).append( roomListLayout.$element );
	} );

} )( jQuery, mediaWiki, OO );
