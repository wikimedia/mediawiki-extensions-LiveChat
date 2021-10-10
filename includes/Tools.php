<?php

namespace LiveChat;

use Message;
use MWException;
use User;

class Tools {
	/**
	 * @param User $user
	 * @param string $key
	 * @param string|string[] ...$params Normal message parameters
	 * @return Message
	 */
	public static function getMessage( $user, $key, ...$params ) {
		$langCode = $user->getOption( 'language' );
		try {
			$message = wfMessage( $key )->inLanguage( $langCode );
		} catch ( MWException $e ) {
			$message = wfMessage( $key );
		}
		if ( $params ) {
			$message->params( ...$params );
		}
		return $message;
	}
}
