<?php
namespace LiveChat;

use FormatJson;
use InvalidArgumentException;
use User;
use wAvatar;
use Wikimedia\Rdbms\Database;
use Wikimedia\Timestamp\ConvertibleTimestamp;
use Workerman\Connection\ConnectionInterface;

class ChatData {

	const TABLE = 'lch_messages';
	const C_ID = 'lchm_id';
	const C_PARENT = 'lchm_parent_id';
	const C_ROOM_TYPE = 'lchm_room_type';
	const C_ROOM_ID = 'lchm_room_id';
	const C_USER_ID = 'lchm_user_id';
	const C_USER_TEXT = 'lchm_user_text';
	const C_MESSAGE = 'lchm_message';
	const C_HAS_CHILDREN = 'lchm_has_children';
	const C_TIMESTAMP = 'lchm_timestamp';

	const TABLE_REACTION = 'lch_msg_reactions';

	const C_REACTION_MESSAGE_ID = 'lchmr_msg_id';
	const C_REACTION_USER_ID = 'lchmr_user_id';
	const C_REACTION_USER_TEXT = 'lchmr_user_text';
	const C_REACTION_TYPE = 'lchmr_type';
	const C_REACTION_TIMESTAMP = 'lchmr_timestamp';

	const CACHE_SIZE = 100;

	/**
	 * @var array
	 */
	protected static $cache = [];

	const REACTIONS_BY_NAME = [
		'like' => 1,
	];

	const REACTIONS_BY_ID = [
		1 => 'like',
	];

	/**
	 * @var Database
	 */
	private static $dbw;
	/**
	 * @var Database
	 */
	private static $dbr;

	/**
	 * @param ConnectionInterface $connection
	 * @param string $name
	 * @param string $command
	 * @param array $data
	 */
	public static function onCommand( ConnectionInterface $connection, string $name, string $command, array $data ) {
		self::debugLog( __FUNCTION__, $name, $command, var_export( $data, true ) );
		switch ( $command ) {
			case 'insert':
				self::onInsertCommand( $connection, $data );
				break;
			case 'select':
				self::onSelectCommand( $connection, $data );
				break;
			case 'update':
				self::onUpdateCommand( $connection, $data );
				break;
			default:
				self::debugLog( __FUNCTION__, 'ERROR: Unknown command' );
		}
	}

	/**
	 * @param ConnectionInterface $connection
	 * @param array $data
	 */
	protected static function onSelectCommand( ConnectionInterface $connection, array $data ) {
		$roomId = $data['roomId'];
		$roomType = $data['roomType'];
		$target = $data['target'] ?? null;

		$value = $data['message']['value'] ?? [];
		// $userId = $value['userId'] ?? 0;
		$userName = $value['userName'] ?? 'Undefined';

		$fromId = $value['fromId'] ?? null;
		$parentId = $value['parentId'] ?? null;
		$roomCache = &self::getCache( [ $roomType, $roomId ] );
		if ( !$roomCache ) {
			self::loadMessagesToCache( $roomType, $roomId );
		}
		$reactions = &$roomCache['reactions'];
		$messagesCache = &$roomCache['messages'];
		$return = [];
		if ( $parentId ) {
			$parent = self::getParent( $roomType, $roomId, $parentId );
			if ( $parent && !$fromId ) {
				// Add parent message if this is not a history request for disconnect events
				$return[] = $parent;
			}
			if ( $parent && isset( $roomCache['children'][$parentId] ) ) {
				$messagesCache = &$roomCache['children'][$parentId];
			} else {
				$emptyArray = [];
				$messagesCache = &$emptyArray;
			}
		}

		if ( !$fromId || isset( $messagesCache[$fromId] ) ) { // TODO send reaction also when fromId provided
			foreach ( $messagesCache as $msgId => $message ) { // TODO optimize me
				if ( !$fromId || $msgId > $fromId ) {
					// Add user reaction
					$userReaction = $reactions[$msgId][$userName] ?? null;
					if ( $userReaction ) {
						$message['userReaction'] = $userReaction;
					}
					$return[] = $message;
				}
			}
		} else {
			// TODO load from database
		}

		$bufferData = [
			'event' => ChatRoom::EVENT_SEND_HISTORY,
			'messages' => $return,
		];
		if ( $parentId ) {
			$bufferData['parentId'] = $parentId;
		}

		self::sendDataToManager(
			$connection,
			[
				'command' => 'send',
				'message' => [
					'action' => Room::MANAGER_ACTION_SEND_TO_ALL,
					'buffer' => FormatJson::encode( $bufferData ),
				],
				'key' => $data['key'] ?? null,
				'roomId' => $roomId,
				'roomType' => $roomType,
			],
			$target
		);
	}

	/**
	 * Updates reactions
	 * @param ConnectionInterface $connection
	 * @param array $data
	 */
	protected static function onUpdateCommand( ConnectionInterface $connection, array $data ) {
		$target = $data['target'] ?? null;
		$message = $data['message'] ?? [];
		$value = $message['value'] ?? [];
		$id = $value['message'] ?? null;
		if ( !$id ) {
			self::debugLog( __FUNCTION__, 'ERROR: No message id provided' );
			return;
		}

		$newReaction = $value['reaction'] ?? null;
		$newReactionId = self::REACTIONS_BY_NAME[$newReaction] ?? null;
		if ( !$newReactionId ) {
			self::debugLog( __FUNCTION__, "ERROR: Reaction $newReaction is not allowed" );
			return;
		}

		$userId = $value['userId'];
		$userName = $value['userName'];

		$roomId = $data['roomId'];
		$roomType = $data['roomType'];
		$roomCache = &self::getCache( [ $roomType, $roomId ] );
		if ( !$roomCache ) {
			self::loadMessagesToCache( $roomType, $roomId );
		}
		$messagesCache = &$roomCache['messages'];
		$reactionsCache = &$roomCache['reactions'];

		$msgInCache = self::updateReaction( $messagesCache, $reactionsCache, $id, $userName, $newReaction );
		if ( !$msgInCache ) {
			self::debugLog( __FUNCTION__, "ERROR: Message does not exist $roomType $roomId $id" );
			return;
		}
		$messageReactions = $messagesCache[$id]['reactions'];

		$dbw = self::getDBW();
		$time = Connection::getTime();
		$timestamp = ConvertibleTimestamp::convert( TS_MW, $time );
		$index = [
			self::C_REACTION_MESSAGE_ID => $id,
			self::C_REACTION_USER_ID => $userId,
			self::C_REACTION_USER_TEXT => $userName,
			self::C_REACTION_TIMESTAMP => $timestamp,
		];
		$set = [
			self::C_REACTION_TYPE => $newReactionId,
		];
		$dbw->upsert(
			self::TABLE_REACTION,
			[ $index + $set ],
			[ self::C_REACTION_MESSAGE_ID, self::C_REACTION_USER_TEXT ],
			$set,
			__METHOD__
		);

		$msg = [
			'event' => ChatRoom::EVENT_SEND_REACTION,
			'id' => $id,
			'reaction' => $newReaction,
			'messageReactions' => $messageReactions,
			'time' => $timestamp,
		];

		self::sendDataToManager(
			$connection,
			[
				'command' => 'send',
				'message' => [
					'action' => Room::MANAGER_ACTION_SEND_TO_ALL,
					'buffer' => FormatJson::encode( $msg ),
				],
				'key' => $data['key'] ?? null,
				'roomId' => $roomId,
				'roomType' => $roomType,
			],
			$target
		);
	}

	/**
	 * @param array &$messages
	 * @param array &$reactions
	 */
	protected static function loadReactionsForMessages( &$messages = [], &$reactions = [] ) {
		if ( !$messages ) {
			return;
		}

		$dbr = self::getDBR();
		$vars = [
			self::C_REACTION_MESSAGE_ID,
			self::C_REACTION_USER_ID,
			self::C_REACTION_USER_TEXT,
			self::C_REACTION_TYPE,
		];
		$cond = [
			self::C_REACTION_MESSAGE_ID => array_keys( $messages ),
		];
		$res = $dbr->select( self::TABLE_REACTION, $vars, $cond, __METHOD__ );

		foreach ( $res as $row ) {
			$a = (array)$row;
			$id = $a[self::C_REACTION_MESSAGE_ID];
			$userText = $a[self::C_REACTION_USER_ID] ? User::newFromId( $a[self::C_REACTION_USER_ID] )->getName() : $a[self::C_REACTION_USER_TEXT];
			$reactionName = self::REACTIONS_BY_ID[ $a[self::C_REACTION_TYPE] ] ?? 'undefined';
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
			self::debugLog( __FUNCTION__, 'empty( $messages[$id] )', $id );
			return false;
		}

		if ( !isset( $messages[$id]['reactions'] ) ) {
			self::debugLog( __FUNCTION__, 'empty( $messages[$id]["reactions"] )', $id );
			$messages[$id]['reactions'] = [];
		}
		if ( !isset( $reactions[$id] ) ) {
			self::debugLog( __FUNCTION__, 'empty( $reactions[$id] )', $id );
			$reactions[$id] = [];
		}
		$messageReactions =& $messages[$id]['reactions'];
		$oldReaction = $reactions[$id][$userName] ?? null;
		if ( $oldReaction ) {
			if ( $oldReaction === $newReaction ) {
				self::debugLog( __FUNCTION__, '$oldReaction === $newReaction', $id, $userName, $newReaction );
				return true;
			}
			self::debugLog( __FUNCTION__, '$oldReaction', $oldReaction, '$newReaction', $newReaction );
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
	 * @param ConnectionInterface $connection
	 * @param array $data
	 */
	protected static function onInsertCommand( ConnectionInterface $connection, array $data ) {
		$roomId = $data['roomId'];
		$roomType = $data['roomType'];
		$target = $data['target'] ?? null;

		$roomCache = &self::getCache( [ $roomType, $roomId ] );
		$messagesCache = &$roomCache[ 'messages' ];

		$value = $data['message']['value'] ?? [];
		$parentId = $value['parentId'] ?? null;
		$userId = $value['userId'] ?? 0;
		$userName = $value['userName'] ?? 'Undefined';

		if ( $parentId ) {
			$parent = &self::getParent( $roomType, $roomId, $parentId, true );
			if ( !$parent ) {
				$parentId = null;
			}
		}

		$text = $value['message'] ?? '';
		if ( !$text ) {
			self::debugLog( __FUNCTION__, 'ERROR: Message is empty' );
		}
		// Save to the database
		$time = Connection::getTime();
		$row = [
			self::C_ROOM_TYPE => $roomType,
			self::C_ROOM_ID => $roomId,
			self::C_PARENT => $parentId,
			self::C_USER_ID => $userId,
			self::C_USER_TEXT => $userName,
			self::C_MESSAGE => $text,
			self::C_HAS_CHILDREN => false,
			self::C_TIMESTAMP => ConvertibleTimestamp::convert( TS_MW, $time ),
		];
		$dbw = self::getDBW();
		$dbw->insert(
			self::TABLE,
			$row,
			__METHOD__
		);
		$id = $dbw->insertId();

		// Make Message
		$msg = self::makeMessageData( $roomType, $roomId, $id, $text, $userName, $userId, $time, $parentId, false );
		if ( $id ) {
			if ( $parentId ) {
				$roomCache['children'][$parentId][$id] = &$messagesCache[$id];
				if ( empty( $parent['hasChildren'] ) ) {
					$parent['hasChildren'] = 1;
					$dbw->update(
						self::TABLE,
						[ self::C_HAS_CHILDREN => true ],
						[ self::C_ID => $parentId ],
						__METHOD__
					);
				}
			}
			$messagesCache[$id] = $msg;

			// TODO need to develop more smarter cleaner (should work with parents and reactions)
			// if ( count( $messagesCache ) > static::CACHE_SIZE ) {
				// $min = min( array_keys( $messagesCache ) );
				// unset( $messagesCache[$min] );
			// }
		} else {
			self::debugLog( __FUNCTION__, 'ERROR: Cannot insert row' );
		}

		self::sendDataToManager(
			$connection,
			[
				'command' => 'send',
				'message' => [
					'action' => Room::MANAGER_ACTION_SEND_TO_ALL,
					'buffer' => FormatJson::encode( [
						'event' => ChatRoom::EVENT_SEND_MESSAGE,
						'messageData' => $msg,
					] ),
				],
				'key' => $data['key'] ?? null,
				'roomId' => $roomId,
				'roomType' => $roomType,
			],
			$target
		);
	}

	/**
	 * @param int $roomType
	 * @param int $roomId
	 * @param int $id
	 * @param string|null $text
	 * @param string $userName
	 * @param int|null $userId
	 * @param string $time
	 * @param int|null $parentId
	 * @param bool $hasChildren
	 * @return array
	 */
	protected static function makeMessageData( int $roomType, int $roomId, $id, $text, $userName, $userId, $time, $parentId, $hasChildren ) {
		$msg = [
			'id' => $id,
			'message' => MessageParser::parse( $text ),
			'userName' => $userName,
			'timestamp' => ConvertibleTimestamp::convert( TS_MW, $time ),
		];
		if ( $hasChildren ) {
			$msg['hasChildren'] = 1;
		}
		if ( $userId ) {
			$msg['userId'] = $userId;
		}
		if ( $parentId ) {
			$parent = self::getParent( $roomType, $roomId, $parentId, false );
			if ( $parent ) {
				$msg['parentId'] = $parentId;
				$msg['parentUserName'] = $parent['userName'];
			}
		}
		$userAvatar = self::getUserAvatar( $userId );
		if ( $userAvatar ) {
			$msg['userAvatar'] = $userAvatar;
		}
		return $msg;
	}

	/**
	 * @param int|null $userId
	 * @param string $size
	 * @return string|null
	 */
	public static function getUserAvatar( $userId, $size = 'm' ) {
		if ( !$userId ) {
			return null;
		}
		$avatar = new wAvatar( $userId, $size );
		if ( $avatar->isDefault() ) {
			return null;
		}

		global $wgUploadBaseUrl, $wgUploadPath;

		$avatarImg = $avatar->getAvatarImage();
		return "{$wgUploadBaseUrl}{$wgUploadPath}/avatars/{$avatarImg}";
	}

	/**
	 * @param int $roomType
	 * @param int $roomId
	 * @param int $messageId
	 * @param bool $withChildren
	 * @return array
	 */
	protected static function loadMessage( int $roomType, int $roomId, int $messageId, bool $withChildren = false ) {
		$dbr = self::getDBR();
		$conds = [
			self::C_ROOM_TYPE => $roomType,
			self::C_ROOM_ID => $roomId,
		];
		if ( $withChildren ) {
			$ids = $dbr->makeList( [
				self::C_ID => $messageId,
				self::C_PARENT => $messageId,
			], LIST_OR );
			$conds[] = $ids;
		} else {
			$conds[self::C_ID] = $messageId;
		}

		// TODO get user name from user table
		$res = $dbr->select( self::TABLE, '*', $conds,  __METHOD__ );
		if ( !$res ) {
			return [];
		}

		$parent = [];
		$children = [];
		foreach ( $res as $row ) {
			$msg = self::messageDataFromRow( $roomType, $roomId, (array)$row );
			$id = $msg['id'];
			if ( $id == $messageId ) {
				$parent = $msg;
			} else {
				$children[$id] = $msg;
			}
		}
		if ( $children ) {
			$parentId = $parent['id'];
			$roomCache['children'][$parentId] = $children;
		}

		if ( $parent[self::C_ROOM_TYPE] != $roomType ||
			$parent[self::C_ROOM_ID] != $roomId
		) {
			return [];
		}
		return $parent;
	}

	/**
	 * @param int $roomType
	 * @param int $roomId
	 * @param array $row
	 * @return array
	 */
	protected static function messageDataFromRow( int $roomType, int $roomId, array $row ) {
		$msg = self::makeMessageData(
			$roomType,
			$roomId,
			$row[self::C_ID],
			$row[self::C_MESSAGE],
			$row[self::C_USER_TEXT],
			$row[self::C_USER_ID],
			$row[self::C_TIMESTAMP],
			$row[self::C_PARENT],
			$row[self::C_HAS_CHILDREN]
		);
		return $msg;
	}

	/**
	 * @param mixed ...$args
	 */
	protected static function debugLog( ...$args ) {
		wfDebugLog(
			__CLASS__,
			static::class . '::' . implode( '; ', $args ),
			false
		);
	}

	/**
	 * @param array $path
	 * @return array
	 */
	protected static function &getCache( array $path ) {
		$ret = &self::$cache;
		foreach ( $path as $i => $k ) {
			if ( !isset( $ret[$k] ) ) {
				$ret[$k] = [];
			}
			if ( !is_array( $ret[$k] ) ) {
				$fail = implode( '.', array_slice( $path, 0, $i + 1 ) );
				throw new InvalidArgumentException( "Path $fail is not an array" );
			}
			$ret = &$ret[$k];
		}
		return $ret;
	}

	/**
	 * @return Database
	 */
	protected static function getDBW() {
		if ( !self::$dbw ) {
			self::$dbw = wfGetDB( DB_PRIMARY );
		}
		return self::$dbw;
	}

	/**
	 * @return Database
	 */
	protected static function getDBR() {
		if ( !self::$dbr ) {
			self::$dbr = wfGetDB( DB_REPLICA );
		}
		return self::$dbr;
	}

	/**
	 * @param ConnectionInterface $connection
	 * @param array $data
	 * @param array|null $target
	 */
	protected static function sendDataToManager( ConnectionInterface $connection, array $data, ?array $target = null ) {
		if ( $target ) {
			$data['message']['target'] = $target;
		}
		$buffer = FormatJson::encode( $data );
		self::debugLog( __FUNCTION__, $buffer );
		$connection->send( $buffer );
	}

	/**
	 * @param int $roomType
	 * @param int $roomId
	 */
	protected static function loadMessagesToCache( int $roomType, int $roomId ) {
		self::debugLog( __FUNCTION__, $roomType, $roomId );
		$roomCache = &self::getCache( [ $roomType, $roomId ] );
		// if ( !$roomCache ) {
			$roomCache = [
				'messages' => [],
				'parents' => [],
				'children' => [],
				'reactions' => [],
			];
		// }

		$dbr = self::getDBR();
		$conds = [
			self::C_ROOM_TYPE => $roomType,
			self::C_ROOM_ID => $roomId,
		];
		$options = [
			'LIMIT' => static::CACHE_SIZE,
			'ORDER BY' => self::C_ID,
		];
		// TODO get user name from user table
		$res = $dbr->select( self::TABLE, '*', $conds,  __METHOD__, $options );
		if ( !$res ) {
			return;
		}

		$messageCache = &$roomCache['messages'];
		foreach ( $res as $row ) {
			$a = (array)$row;
			$parentId = $a[self::C_PARENT];
			$msg = self::messageDataFromRow( $roomType, $roomId, $a );
			$id = $msg['id'];
			$messageCache[$id] = $msg;
			if ( $parentId ) {
				$parent = &self::getParent( $roomType, $roomId, $parentId, true );
				if ( $parent ) {
					$roomCache['children'][$parentId][$id] = &$messageCache[$id];
				}
			}
		}
		self::loadReactionsForMessages( $messageCache, $roomCache['reactions'] );
	}

	/**
	 * @param int $roomType
	 * @param int $roomId
	 * @param int $parentId
	 * @param bool $withChildren
	 * @return array|null
	 */
	protected static function &getParent(
		int $roomType, int $roomId, int $parentId, bool $withChildren = false
	): ?array {
		$parentsCache = &self::getCache( [ $roomType, $roomId, 'parents' ] );
		if ( empty( $parentsCache[$parentId] ) ) {
			$messagesCache = &self::getCache( [ $roomType, $roomId, 'messages' ] );
			if ( empty( $messagesCache[$parentId] ) ) {
				$parent = self::loadMessage( $roomType, $roomId, $parentId, $withChildren );
			} else {
				$parent = &$messagesCache[$parentId];
			}
			if ( !$parent || isset( $parent['parentId'] ) ) {
				$parent = null; // don't allow wrong parent and parent of children
			} else {
				$parentsCache[$parentId] =& $parent;
			}
		} else {
			$parent = &$parentsCache[$parentId];
		}
		return $parent;
	}
}
