<?php
namespace LiveChat;

use User;

class Reactions {
	const TABLE = 'lch_msg_reactions';

	const C_MESSAGE_ID = 'lchmr_msg_id';
	const C_USER_ID = 'lchmr_user_id';
	const C_USER_TEXT = 'lchmr_user_text';
	const C_TYPE = 'lchmr_type';
	const C_TIMESTAMP = 'lchmr_timestamp';

	/**
	 * @var array
	 */
	private $messages;

	/**
	 * @var ChatRoom
	 */
	private $room;

	/**
	 * @var array
	 */
	private $reactions = [];

	private $reactionsByName = [
		'like' => 1,
	];

	private $reactionsById = [
		1 => 'like',
	];

	/**
	 * Reactions constructor.
	 * @param array &$messages
	 * @param ChatRoom $room
	 */
	public function __construct( array &$messages, ChatRoom $room ) {
		$this->messages = &$messages;
		$this->room = $room;
	}

	// public function addReaction( Connection $connection, array $data ) {
		// $user = $connection->getUser();
		// if ( !$this->canUserPostReaction( $user ) ) {
			// $errorMessage = Tools::getMessage( $user, 'ext-livechat-error-user-cannot-post-reactions' )->text();
			// $tmp = [
				// 'clientTime' => $data['time'] ?? null,
				// 'error' => $errorMessage,
			// ];
			// $connection->send( ChatRoom::EVENT_SEND_REACTION_CONFIRM, $tmp );
			// return;
		// }
		//
		// $id = $data['message'] ?? null;
		// if ( !$id ) {
			// MWDebug::log( 'No message id provided' );
			// return;
		// }
		//
		// $newReaction = $data['reaction'] ?? null;
		// $newReactionId = $this->reactionsByName[$newReaction] ?? null;
		// if ( !$newReactionId ) {
			// MWDebug::log( "Reaction $newReaction is not allowed" );
			// return;
		// }
		//
		// $data['userId'] = $user->getId();
		// $data['userName'] = $user->getName();
		// $this->updateManagerData( ChatRoom::EVENT_REACTION, $data, true );
		//
		// $msgInCache = self::updateReaction( $this->messages, $this->reactions, $id, $user->getName(), $newReaction );
		// if ( $msgInCache ) {
			// $messageReactions = $this->messages[$id]['reactions'];
		// } else {
			// $row = $this->room->loadMessage( $id );
			// if ( !$row ) {
				// MWDebug::log( "Message does not exist" );
				// return;
			// }
			// $tmp = [];
			// $this->loadForMessagesInternal( $row, $tmp );
			// self::updateReaction( $row, $tmp, $id, $user->getName(), $newReaction );
			// $messageReactions = current( $row )['reactions'];
		// }
		//
		// $dbw = $this->room->getDBW();
		// $time = Connection::getTime();
		// $timestamp = ConvertibleTimestamp::convert( TS_MW, $time );
		// $index = [
			// self::C_MESSAGE_ID => $id,
			// self::C_USER_ID => $connection->getUser()->getId(),
			// self::C_USER_TEXT => $connection->getUser()->getName(),
			// self::C_TIMESTAMP => $timestamp,
		// ];
		// $set = [
			// self::C_TYPE => $newReactionId,
		// ];
		// $dbw->upsert(
			// self::TABLE,
			// [ $index + $set ],
			// [ self::C_MESSAGE_ID, self::C_USER_TEXT ],
			// $set,
			// __METHOD__
		// );
		//
		// $msg = [
			// 'id' => $id,
			// 'reaction' => $newReaction,
			// 'messageReactions' => $messageReactions,
			// 'time' => $timestamp,
		// ];
		// $tmp = $msg;
		// $tmp['clientTime'] = $data['time'] ?? null;
		// $connection->send( ChatRoom::EVENT_SEND_REACTION_CONFIRM, $tmp, $time );
		// if ( !$msgInCache ) {
			// return;
		// }
		//
		// foreach ( $this->room->getConnections() as $c ) {
			// if ( $c === $connection ) {
				// continue;
			// } else {
				// $c->send( ChatRoom::EVENT_SEND_REACTION, $msg, $time );
			// }
		// }
	// }

	public function canUserPostReaction( User $user ) {
		$options = $this->room->getOptions();
		if ( empty( $options[ChatRoom::O_PERM_CAN_POST_REACTION] ) ) {
			return true;
		}
		return $user->isAllowed( $options[ChatRoom::O_PERM_CAN_POST_REACTION] );
	}

	public function loadForMessages() {
		$this->loadForMessagesInternal( $this->messages, $this->reactions );
	}

	private function loadForMessagesInternal( &$messages = [], &$reactions = [] ) {
		if ( !$messages ) {
			return;
		}

		$dbr = $this->room->getDBR();
		$vars = [
			self::C_MESSAGE_ID,
			self::C_USER_ID,
			self::C_USER_TEXT,
			self::C_TYPE,
		];
		$cond = [
			self::C_MESSAGE_ID => array_keys( $messages ),
		];
		$res = $dbr->select( self::TABLE, $vars, $cond, __METHOD__ );

		foreach ( $res as $row ) {
			$a = (array)$row;
			$id = $a[self::C_MESSAGE_ID];
			$userText = $a[self::C_USER_ID] ? User::newFromId( $a[self::C_USER_ID] )->getName() : $a[self::C_USER_TEXT];
			$reactionName = $this->reactionsById[ $a[self::C_TYPE] ] ?? 'undefined';
			self::updateReaction( $messages, $reactions, $id, $userText, $reactionName );
		}
	}

	/**
	 * @param array &$messages
	 * @param array &$reactions
	 * @param string $id
	 * @param string $userName
	 * @param string $newReaction
	 * @return bool
	 */
	private static function updateReaction( &$messages, &$reactions, $id, $userName, $newReaction ) {
		if ( empty( $messages[$id] ) ) {
			// echo 'empty( $messages[$id] )', "\n";
			return false;
		}

		if ( !isset( $messages[$id]['reactions'] ) ) {
			// echo 'empty( $messages[$id][\'reactions\'] )', "\n";
			$messages[$id]['reactions'] = [];
		}
		if ( !isset( $reactions[$id] ) ) {
			// echo 'empty( $reactions[$id] )', "\n";
			$reactions[$id] = [];
		}
		$messageReactions =& $messages[$id]['reactions'];
		if ( isset( $reactions[$id][$userName] ) ) {
			// echo 'isset( $reactions[$id][$userName] )', "\n";
			$oldReaction = $reactions[$id][$userName];
			// echo '$oldReaction=', $oldReaction, "\n";
			if ( $oldReaction === $newReaction ) {
				// echo '$oldReaction === $newReaction', "\n";
				return true;
			}
			if ( ( $messageReactions[$oldReaction] ?? 0 ) > 0 ) {
				$messageReactions[$oldReaction]--;
				// echo '$messageReactions[ $oldReaction ]--', "\n";
			}
		}
		if ( empty( $messageReactions[$newReaction] ) ) {
			$messageReactions[$newReaction] = 1;
			// echo '$messageReactions[$newReaction] = 1;', "\n";
		} else {
			$messageReactions[$newReaction]++;
			// echo '$messageReactions[$newReaction]++;', "\n";
		}
		$reactions[$id][$userName] = $newReaction;

		return true;
	}

	public function getUserReaction( $id, $userName, $fromCache ) {
		if ( isset( $this->reactions[$id] ) ) {
			return $this->reactions[$id][$userName] ?? null;
		} elseif ( $fromCache ) {
			return null;
		}
		// return null;
	}

}
