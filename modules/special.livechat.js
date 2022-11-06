/**
 *
 */
( function ( $, mw, OO ) {
	"use strict";

	var uiUsersList = new mw.livechat.widgets.ItemsLayout( { emptyLabelText: 'No users', framed: false, padded: true } ),
		uiLiveChatLayout = new mw.livechat.widgets.LiveChatLayout( { framed: false, padded: true } ),
		uiSplitLayout = new mw.livechat.widgets.SplitLayout( { content: [uiUsersList, uiLiveChatLayout] } );

	$( function () {
		$( '#mw-content-text' ).append( uiSplitLayout.$element );
	} );

} )( jQuery, mediaWiki, OO );
