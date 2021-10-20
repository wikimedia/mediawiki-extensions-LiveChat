<?php

namespace LiveChat;

use User;
use wAvatar;
use Wikimedia\Rdbms\Database;
use Wikimedia\Timestamp\ConvertibleTimestamp;

class ChatRoom extends Room {

	const ROOM_TYPE = 10;

	// const
	const O_PERM_CAN_POST_MESSAGE = 'canPostMessagePermission';
	const O_PERM_CAN_POST_REACTION = 'canPostReactionPermission';

	const I_CAN_POST = 'canPost';

	const TABLE = 'lch_messages';
	const C_ID = 'lchm_id';
	const C_PARENT = 'lchm_parent_id';
	const C_ROOM_TYPE = 'lchm_room_type';
	const C_ROOM_ID = 'lchm_room_id';
	const C_USER_ID = 'lchm_user_id';
	const C_USER_TEXT = 'lchm_user_text';
	const C_MESSAGE = 'lchm_message';
	const C_TIMESTAMP = 'lchm_timestamp';

	const MAX_MESSAGE_TEXT_SIZE = 1000;

	const EVENT_MESSAGE = 'LiveChatMessage';
	const EVENT_REACTION = 'LiveChatReaction';
	const EVENT_GET_HISTORY = 'getLiveChatHistory';
	const EVENT_GET_PERMISSIONS = 'getPermissions';
	const EVENT_GET_ROOM_STATISTICS = 'getRoomStatistics';
	const EVENT_SEND_HISTORY = 'LiveChatHistory';
	const EVENT_SEND_MESSAGE = 'LiveChatMessage';
	const EVENT_SEND_REACTION = 'LiveChatReaction';
	const EVENT_SEND_USER_STATUS = 'LiveChatUserStatus';
	const EVENT_SEND_MESSAGE_CONFIRM = 'LiveChatMessageConfirm';
	const EVENT_SEND_REACTION_CONFIRM = 'LiveChatReactionConfirm';
	const EVENT_SEND_ROOM_STATISTICS = 'LiveChatRoomStatistics';
	const EVENT_SEND_PERMISSIONS = 'permissions';

	/**
	 * @var Database|null
	 */
	private static $dbw;

	/**
	 * @var Database|null
	 */
	private static $dbr;

	/**
	 * @var array
	 */
	private $messages = [];

	/**
	 * @var array
	 */
	private $parents = [];

	/**
	 * @var Reactions
	 */
	private $reactions;

	/**
	 * @var int
	 */
	private $cacheSize = 100;

	/**
	 * Room constructor.
	 * @param int $id
	 * @param array $options
	 */
	public function __construct( int $id = 0, array $options = [] ) {
		parent::__construct( $id, $options );

		$this->loadMessagesToCache();
		$this->reactions = new Reactions( $this->messages, $this );
		$this->reactions->loadForMessages();
	}

	/**
	 * @param array &$message
	 * @param string $userName
	 */
	private function addUserReaction( array &$message, string $userName ) {
		$userReaction = $this->reactions->getUserReaction( $message['id'], $userName, true );
		if ( $userReaction ) {
			$message['userReaction'] = $userReaction;
		}
	}

	/**
	 * @param Connection $connection
	 */
	public function addConnection( Connection $connection ) {
		parent::addConnection( $connection );

		$onlineCount = $this->getOnlineUsersCount();
		$statistics = [ 'online' => $onlineCount ];
		$connection->send( self::EVENT_SEND_ROOM_STATISTICS, [ 'statistics' => $statistics ] );
	}

	// protected function onUserJoin( Connection $connection, string $userKey ) {
		// parent::onUserJoin( $connection, $userKey );
		//
		// $msg = [
			// 'status' => 'join',
			// 'data' => array_intersect_key( $this->users[$userKey], [ 'name' => 1, 'realName' => 1 ] ),
		// ];
		// foreach ( $this->connections as $c ) {
			// if ( $c === $connection ) {
				// continue;
			// }
			// $c->send( self::EVENT_SEND_USER_STATUS, $msg );
		// }
	// }
	//
	// protected function onUserLeft( Connection $connection, string $userKey ) {
		// parent::onUserLeft( $connection, $userKey );
		//
		// $msg = [
			// 'status' => 'left',
			// 'data' => array_intersect_key( $this->users[$userKey], ['name' => 1, 'realName' => 1] ),
		// ];
		// unset( $this->users[$userKey] );
		// foreach ( $this->connections as $c ) {
			// if ( $c === $connection ) {
				// continue;
			// }
			// $c->send( self::EVENT_SEND_USER_STATUS, $msg );
		// }
	// }

	/**
	 * @param Connection $connection
	 * @param array $data
	 */
	public function onReaction( Connection $connection, array $data ) {
		$this->debugLog( __FUNCTION__ );
		$user = $connection->getUser();
		$confirm = [ 'clientTime' => $data['time'] ?? null ];
		if ( !$this->canUserPostReaction( $user ) ) {
			$confirm['error'] = Tools::getMessage( $user, 'ext-livechat-error-user-cannot-post-reactions' )->text();
			$connection->send( self::EVENT_SEND_REACTION_CONFIRM, $confirm );
			return;
		}

		if ( empty( $data['message'] ) ) {
			$confirm['error'] = 'No message id provided';
			$connection->send( self::EVENT_SEND_REACTION_CONFIRM, $confirm );
			return;
		}

		$reaction = $data['reaction'] ?? null;
		if ( !$reaction || empty( ChatData::REACTIONS_BY_NAME[$reaction] ) ) {
			$confirm['error'] = "Reaction $reaction is not allowed";
			$connection->send( self::EVENT_SEND_REACTION_CONFIRM, $confirm );
			return;
		}

		$connection->send( self::EVENT_SEND_REACTION_CONFIRM, $confirm );

		$data['userId'] = $user->getId();
		$data['userName'] = $user->getName();

		$this->sendDatabaseCommand( 'update', ChatData::class, $data, true );
	}

	/**
	 * @param Connection $connection
	 * @param array $data
	 */
	public function onMessage( Connection $connection, array $data ) {
		$user = $connection->getUser();
		$parentId = $data['parentId'] ?? null;
		$confirm = [ 'clientTime' => $data['time'] ?? null ];
		if ( $parentId ) {
			$confirm['parentId'] = $parentId;
		}
		if ( !$this->canUserPostMessages( $user ) ) {
			$errorMessage = Tools::getMessage( $user, 'ext-livechat-error-user-cannot-post-comments' )->text();
			$confirm['error'] = $errorMessage;
			$connection->send( self::EVENT_SEND_MESSAGE_CONFIRM, $confirm );
			return;
		}

		$text = trim( $data['message'] ?? '' );
		if ( !$text ) {
			$errorMessage = 'empty message';
			$confirm['error'] = $errorMessage;
			$connection->send( self::EVENT_SEND_MESSAGE_CONFIRM, $confirm );
			return;
		} elseif ( strlen( $text > self::MAX_MESSAGE_TEXT_SIZE ) ) {
			$text = substr( $text, 0, self::MAX_MESSAGE_TEXT_SIZE );
			$data['message'] = $text;
		}
		$connection->send( self::EVENT_SEND_MESSAGE_CONFIRM, $confirm );

		$data['userId'] = $user->getId();
		$data['userName'] = $user->getName();

		$this->sendDatabaseCommand( 'insert', ChatData::class, $data, true );

		// $parentId = $data['parent'] ?? null;
		// if ( $parentId ) {
			// if ( empty( $this->parents[$parentId] ) ) {
				// if ( empty( $this->messages[$parentId] ) ) {
					// $parent = $this->loadMessage( $parentId, true );
				// } else {
					// $parent =& $this->messages[$parentId];
				// }
			// if ( !$parent || isset( $parent['parent'] ) ) {
				// $parentId = null; // don't allow wrong parent and parent of children
			// } else {
				// $this->parents[$parentId] =& $parent;
			// }
		// } else {
			// $parent =& $this->parents[$parentId];
		// }
		// }
		//
		// $time = Connection::getTime();
		// $text = substr( $data['message'] ?? '', 0, self::MAX_MESSAGE_TEXT_SIZE );
		// $msg = self::makeMessage( $text, $user->getName(), $user->getId(), $time, $parentId );
		// $id = $this->saveMessage( $connection, $msg, $text, $time );
		// $msg['id'] = $id;
		// if ( !$id ) {
			// $msg['clientTime'] = $data['time'] ?? null;
			// $connection->send( self::EVENT_SEND_MESSAGE_CONFIRM, $msg, $time );
			// return;
		// }
		//
		// $this->messages[$id] = $msg;
		// if ( $parentId ) {
			// $parent['children'][] =& $this->messages[$id];
		// }
		//
		// if ( count( $this->messages ) > $this->cacheSize ) {
			// $min = min( array_keys( $this->messages ) );
			// unset( $this->messages[$min] );
		// }
		//
		// foreach ( $this->connections as $c ) {
			// if ( $c === $connection ) {
				// $tmp = $msg;
				// $tmp['clientTime'] = $data['time'] ?? null;
				// $c->send( self::EVENT_SEND_MESSAGE_CONFIRM, $tmp, $time );
			// } else {
				// $tmp = $msg;
				// $this->addUserReaction( $tmp );
				// $c->send( self::EVENT_SEND_MESSAGE, $tmp, $time );
			// }
		// }
	}

	/**
	 * @param string|null $text
	 * @param string $userName
	 * @param int|null $userId
	 * @param string $time
	 * @param int|null $parentId
	 * @return array
	 */
	private static function makeMessage( $text, $userName, $userId, $time, $parentId = null ) {
		$msg = [
			'message' => MessageParser::parse( $text ),
			'userName' => $userName,
			'timestamp' => ConvertibleTimestamp::convert( TS_MW, $time ),
		];
		if ( $userId ) {
			$msg['userId'] = $userId;
		}
		if ( $parentId ) {
			$msg['parent'] = $parentId;
		}
		$userAvatar = self::getUserAvatar( $userId );
		if ( $userAvatar ) {
			$msg['userAvatar'] = $userAvatar;
		}
		return $msg;
	}

	/**
	 * @param Connection $connection
	 * @param array $data
	 */
	public function onGetHistory( Connection $connection, array $data ) {
		$target = [ self::TARGET_CONNECTION => $connection->getId() ];
		$this->sendDatabaseCommand( 'select', ChatData::class, $data, false, $target );
	}

	/**
	 * @param Connection $connection
	 * @param array $data
	 */
	public function onGetPermissions( Connection $connection, array $data ) {
		$user = $connection->getUser();
		$list = $data['list'] ?? [];

		$permissions = [];
		foreach ( $list as $value ) {
			switch ( $value ) {
				case self::I_CAN_POST:
					$permissions[self::I_CAN_POST] = $this->canUserPostMessages( $user );
					break;
				default:
					$permissions[$value] = null;
			}
		}
		$connection->send( self::EVENT_SEND_PERMISSIONS, [ 'permissions' => $permissions ] );
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
	 * @param User $user
	 * @return bool
	 */
	public function canUserPostMessages( User $user ) {
		if ( empty( $this->options[self::O_PERM_CAN_POST_MESSAGE] ) ) {
			return true;
		}
		return $user->isAllowed( $this->options[self::O_PERM_CAN_POST_MESSAGE] );
	}

	private function loadMessagesToCache() {
		$dbr = $this->getDBR();
		$conds = [
			self::C_ROOM_TYPE => static::ROOM_TYPE,
			self::C_ROOM_ID => $this->roomId,
		];
		$options = [
			'LIMIT' => $this->cacheSize,
			'ORDER BY' => self::C_ID,
		];
		// TODO get user name from user table
		$res = $dbr->select( self::TABLE, '*', $conds,  __METHOD__, $options );
		if ( !$res ) {
			return;
		}

		foreach ( $res as $row ) {
			$msg = self::messageFromRow( (array)$row );
			$id = $msg['id'];
			$this->messages[$id] = $msg;
		}
	}

	// /**
	//  * @param $messageId
	//  * @param bool $withChildren
	//  * @return array
	//  */
	// public function loadMessage( $messageId, $withChildren = false ) {
		// $dbr = $this->getDBR();
		// $conds = [
			// self::C_ROOM_TYPE => static::ROOM_TYPE,
			// self::C_ROOM_ID => $this->roomId,
		// ];
		// if ( $withChildren ) {
			// $ids = $dbr->makeList( [
				// self::C_ID => $messageId,
				// self::C_PARENT => $messageId,
			// ], LIST_OR );
			// $conds[] = 	$ids;
		// } else {
			// $conds[self::C_ID] = $messageId;
		// }
		//
		// // TODO get user name from user table
		// $res = $dbr->select( self::TABLE, '*', $conds,  __METHOD__ );
		// if ( !$res ) {
			// return [];
		// }
		//
		// $parent = [];
		// $children = [];
		// foreach ( $res as $row ) {
			// $msg = self::messageFromRow( (array)$row );
			// $id = $msg['id'];
			// if ( $id == $messageId ) {
				// $parent = $msg;
			// } else {
				// $children[$id] = $msg;
			// }
		// }
		// if ( $children ) {
			// $parent['children'] = $children;
		// }
		//
		// if ( $parent[self::C_ROOM_TYPE] != static::ROOM_TYPE ||
			// $parent[self::C_ROOM_ID] != $this->roomId
		// ) {
			// return [];
		// }
		// return $parent;
	// }

	/**
	 * @param array $row
	 * @return array
	 */
	private static function messageFromRow( array $row ) {
		$msg = self::makeMessage(
			$row[self::C_MESSAGE],
			$row[self::C_USER_TEXT],
			$row[self::C_USER_ID],
			$row[self::C_TIMESTAMP],
			$row[self::C_PARENT]
		);
		$id = $row[self::C_ID];
		$msg['id'] = $id;
		return $msg;
	}

	/**
	 * @param Connection $connection
	 * @param string $event
	 * @param array $data
	 */
	public function onEvent( Connection $connection, string $event, array $data ) {
		switch ( $event ) {
			case self::EVENT_MESSAGE:
				$this->onMessage( $connection, $data );
				break;
			case self::EVENT_GET_HISTORY:
				$this->onGetHistory( $connection, $data );
				break;
			case self::EVENT_GET_PERMISSIONS:
				$this->onGetPermissions( $connection, $data );
				break;
			case self::EVENT_REACTION:
				$this->onReaction( $connection, $data );
				break;
			default:
				parent::onEvent( $connection, $event, $data );
		}
	}

	/**
	 * @return Database
	 */
	public function getDBW() {
		if ( !self::$dbw ) {
			self::$dbw = wfGetDB( DB_PRIMARY );
		}
		return self::$dbw;
	}

	/**
	 * @return Database
	 */
	public function getDBR() {
		if ( !self::$dbr ) {
			self::$dbr = wfGetDB( DB_REPLICA );
		}
		return self::$dbr;
	}

	/**
	 * @param User $user
	 * @return bool
	 */
	protected function canUserPostReaction( User $user ) {
		$options = $this->getOptions();
		if ( empty( $options[self::O_PERM_CAN_POST_REACTION] ) ) {
			return true;
		}
		return $user->isAllowed( $options[self::O_PERM_CAN_POST_REACTION] );
	}
}
