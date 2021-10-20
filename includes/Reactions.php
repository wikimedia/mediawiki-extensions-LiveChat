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

	private const REACTIONS_BY_ID = [
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

	/**
	 * @param User $user
	 * @return bool
	 */
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

	/**
	 * @param array &$messages
	 * @param string[][] &$reactions
	 */
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
			$reactionName = self::REACTIONS_BY_ID[ $a[self::C_TYPE] ] ?? 'undefined';
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

	/**
	 * @param int $id
	 * @param User $userName
	 * @param bool $fromCache
	 * @return string|null|void
	 */
	public function getUserReaction( $id, $userName, $fromCache ) {
		if ( isset( $this->reactions[$id] ) ) {
			return $this->reactions[$id][$userName] ?? null;
		} elseif ( $fromCache ) {
			return null;
		}
	}

}
