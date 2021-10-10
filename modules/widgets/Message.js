/**
 *
 */
( function ( $, mw, OO ) {
	"use strict";

	function Message( config ) {
		config = $.extend( {
			replyButton: true
		}, config );

		var messageData = config.messageData || {},
			$message = $( '<div>' ),
			$messageBottom = $( '<div>' ),
			$messageElement = $( '<div>' ),
//			reactionButton = new mw.livechat.widgets.ButtonWidget( { label: 'reaction', icon: 'fa-heart', framed: false, iconAtTheEnd: true } ),
			replyToText = config.replyButton && messageData.parentUserName && 'Reply to: ' + messageData.parentUserName;

		// Parent constructor
		Message.parent.call( this, config );

		this.messageData = messageData;
		if ( config.replyButton ) {
			this.replyButton = new mw.livechat.widgets.ButtonWidget( { icon: 'fa-reply', faClass: 'fas', framed: false, iconAtTheEnd: true } );
			this.updateReplyButtonText();
		}

		if ( replyToText ) {
			$message.append( $( '<div>' ).text( replyToText ).addClass( 'reply-to' ) );
		}

		if ( messageData.userAvatar ) {
			$( '<img>' )
				.attr( { src: messageData.userAvatar } )
				.addClass( 'avatar' )
				.appendTo( this.$element );
		} else {
			// if ( Math.floor(Math.random() * 2 ) ) {
			// 	$( '<img>' )
			// 		.attr( { src: 'https://www.marefa.org/images/avatars/wikidb_52688_m.jpg?r=1567774792' } )
			// 		.addClass( 'avatar' )
			// 		.appendTo( this.$element );
			// } else {
				new OO.ui.IconWidget( { icon: 'userAvatar', classes: ['avatar'] } ).$element.appendTo( this.$element );
			// }
		}

		this.setData( messageData.id );

		this.$reactions = $( '<div>' ).addClass( 'reactions' );
		if ( messageData.reactions ) {
			this.setReactions( messageData.reactions );
		}

		this.timestamp = new Date( mw.livechat.parseTimeStamp( messageData.timestamp ) );
		this.$date = $( '<div>' ).addClass( 'date' );
		this.$comment = $( '<div>' ).addClass( 'comment' );
		this.setComment( messageData.message );
		$messageElement
			.addClass( 'body' )
			.append( this.$comment, this.$date );

		this.likeButton = new mw.livechat.widgets.ButtonWidget( {
			label: mw.msg( 'ext-livechat-reaction-like' ),
			icon: 'fa-thumbs-up',
			framed: false,
			iconAtTheEnd: true
		} );
		this.setUserReaction( messageData.userReaction );

		$messageBottom.append( this.likeButton.$element/*, reactionButton.$element,*/ );
		if ( this.replyButton ) {
			$messageBottom.append( this.replyButton.$element );
		}
		$messageBottom.addClass( 'footer' );

		$message
			.append( $( '<div>' ).text( messageData.userName ) )
			.append( $messageElement, $messageBottom, this.$reactions )
			.addClass( 'message' );

		this.$element
			.addClass( 'livechat-Message' )
			.append( $message );

		this.isValidState = messageData.userName && messageData.message;

		// Events
		this.likeButton.connect( this, { click: 'onLikeButtonClick' } );
		if ( this.replyButton ) {
			this.replyButton.connect( this, { click: 'onReplyButtonClick' } );
		}

		this.updateTimeText();
	}

	mw.livechat.widgets.Message = Message;

	OO.inheritClass( Message, OO.ui.Widget );

	Message.prototype.setComment = function ( message ) {
		if ( !Array.isArray( message ) ) {
			this.$comment.text( message );
			return;
		}

		var html = $.map( message, function ( value ) {
			switch ( value.type ) {
				case 'externalLink':
					return $( '<a>' )
						.text( value.text )
						.attr( { href: value.url, target: '_blank', rel: 'nofollow' } )
						.addClass( 'external link-https ' + (value.free ? 'free' : 'text') )[0].outerHTML;
				case 'internalLink':
					return $( '<a>' )
						.text( value.text )
						.attr( { href: value.url, target: '_blank' } )[0].outerHTML;
				case 'text':
				default:
					return $( '<span>' ).text( value.text || ' *undefined* ' )[0].outerHTML;
			}
		} ).join( '' );

		this.$comment.html( html );
	};

	Message.prototype.isValid = function () {
		return !!this.isValidState;
	};

	Message.prototype.onLikeButtonClick = function () {
		mw.livechat.send( 'LiveChatReaction', {
			reaction: 'like',
			message: this.getData()
		} );
	};

	Message.prototype.onReplyButtonClick = function () {
		this.emit( 'replyClick' );
	};

	Message.prototype.setReactions = function ( reactions ) {
		this.$reactions.empty();
		if ( reactions.like ) {
			this.$reactions.append( new mw.livechat.widgets.IconWidget( { icon: 'fa-thumbs-up', faClass: 'fas', text: reactions.like } ).$element );
		}
	};

	Message.prototype.setUserReaction = function ( reaction ) {
		var icon = 'fa-thumbs-up',
			faClass = reaction ? 'fas' : 'far';

		this.likeButton.setIcon( icon );
		this.likeButton.setFAClass( faClass );
		this.likeButton.$element.toggleClass( 'clicked', !!reaction );
	};

	Message.prototype.updateTimeText = function () {
		var timestamp = this.timestamp,
			dateString = ( '0' + timestamp.getHours() ).slice( -2 ) + ':' + ( '0' + timestamp.getMinutes() ).slice( -2 );

		if ( timestamp.toDateString() !== new Date( Date.now() ).toDateString() ) {
			dateString = timestamp.getDate() + ' ' + mw.config.get( 'wgMonthNamesShort' )[timestamp.getMonth() + 1] + ' ' + dateString;
		}
		this.$date.text( dateString );
	};

	Message.prototype.getMessageData = function () {
		return this.messageData;
	};

	Message.prototype.updateReplyButtonText = function () {
		if ( !this.replyButton ) {
			return;
		}

		var messageData = this.messageData,
			text = ( messageData.parentId || messageData.hasChildren ) ? 'discussion' : 'reply';

		this.replyButton.setLabel( text );
	};

} )( jQuery, mediaWiki, OO );
