<?php
namespace LiveChat;

use ConfigException;
use Exception;
use FormatJson;
use MWDebug;
use RequestContext;
use Workerman\Connection\AsyncTcpConnection;
use Workerman\Connection\ConnectionInterface;

class Room {
	const O_ROOM_RESTRICTION = 'roomRestriction';
	const MANAGER_ACTION_SEND_TO_ALL = 'SendToAll';
	const MANAGER_ACTION_SYNC = 'sync';
	const TARGET_CONNECTION = 'cId';
	// const TARGET_USER = 'uId';

	/**
	 * @var int
	 */
	protected $roomId;

	const ROOM_TYPE = 0;

	/**
	 * @var array
	 */
	protected $options;

	/**
	 * @var Connection[]
	 */
	protected $connections = [];

	/**
	 * @var AsyncTcpConnection
	 */
	protected $managerConnection;

	/**
	 * @var array
	 */
	protected $users = [];

	/**
	 * @var array
	 */
	protected $managerTimers = [];

	/**
	 * Room constructor.
	 * @param int $id
	 * @param array $options
	 */
	public function __construct( int $id = 0, array $options = [] ) {
		$this->roomId = $id;
		$this->options = $options;
		$this->connectToManager();
	}

	/**
	 * @param Connection $connection
	 */
	public function addConnection( Connection $connection ) {
		$user = $connection->getUser();

		$restriction = $this->options[self::O_ROOM_RESTRICTION] ?? null;
		if ( $restriction ) {
			if ( !$user->isAllowed( $restriction ) ) {
				$this->debugLog( __FUNCTION__, 'Room is not allowed for user', static::class, $restriction, $user->getName() );
				$connection->sendErrorMessage( 'You are not allowed to connect to room ' . static::class );
				return;
			}
		}
		$this->debugLog( __FUNCTION__, $connection->getId() );
		$this->connections[$connection->getId()] = $connection;

		$userKey = $connection->getUserKey();
		if ( empty( $this->users[$userKey] ) ) {
			$this->users[$userKey] = [
				'count' => 1,
				'name' => $user->getName(),
			];
			if ( !$user->isAnon() ) {
				$this->users[$userKey]['id'] = $user->getId();
				$this->users[$userKey]['realName'] = $user->getRealName();
			}
			$this->onUserJoin( $connection, $userKey );
		} else {
			$this->users[$userKey]['count']++;
		}
	}

	/**
	 * @param array|null $target
	 * @return Connection[]
	 */
	public function getConnections( ?array $target = null ): array {
		if ( !$target ) {
			return $this->connections;
		}

		$return = [];

		$toConn = $target[self::TARGET_CONNECTION] ?? null;
		if ( $toConn ) {
			$c = $this->connections[$toConn] ?? null;
			if ( $c ) {
				$return[] = $c;
			}
		}

		// $toUser = $target[self::TARGET_USER] ?? null;
		// if ( $toUser ) {
			// $this->users[$toUser]
		// }

		return $return;
	}

	/**
	 * @param Connection $connection
	 */
	public function removeConnection( Connection $connection ) {
		unset( $this->connections[$connection->getId()] );

		$userKey = $connection->getUserKey();
		if ( isset( $this->users[$userKey] ) ) {
			$this->users[$userKey]['count']--;
			if ( !$this->users[$userKey]['count'] ) {
				$this->onUserLeft( $connection, $userKey );
			}
		}
	}

	/**
	 * @param Connection $connection
	 * @param string $event
	 * @param array $data
	 */
	public function onEvent( Connection $connection, string $event, array $data ) {
		$this->debugLog( __FUNCTION__, $connection->getId(), 'UNHANDLED EVENT', $event );
	}

	/**
	 * @param Connection $connection
	 * @param string $userKey
	 */
	protected function onUserJoin( Connection $connection, string $userKey ) {
	}

	/**
	 * @param Connection $connection
	 * @param string $userKey
	 */
	protected function onUserLeft( Connection $connection, string $userKey ) {
	}

	/**
	 * @return array
	 */
	public function getOptions(): array {
		return $this->options;
	}

	/**
	 * @return int
	 */
	public function getOnlineUsersCount() {
		return count( $this->users );
	}

	/**
	 * @return int
	 */
	public function getConnectionsCount() {
		return count( $this->connections );
	}

	/**
	 * @param string $event
	 * @param array $data
	 * @param string|null $time
	 */
	public function sendToAllInRoom( string $event, array $data = [], ?string $time = null ) {
		$buffer = Connection::makeSendBuffer( $event, $data, $time );
		$this->sendBufferToAllInRoom( $buffer );
	}

	/**
	 * @param string|null $buffer
	 */
	protected function sendBufferToAllInRoom( ?string $buffer ) {
		if ( !$buffer ) {
			return;
		}
		foreach ( $this->getConnections() as $connection ) {
			$connection->sendBuffer( $buffer );
		}
	}

	/**
	 * @param int $roomId
	 * @param string $event
	 * @param array $data
	 * @param array|null $target
	 * @param string|null $time
	 * @return bool
	 */
	public static function sendToAllByRoomId( int $roomId, string $event, array $data = [], ?array $target = null, ?string $time = null ) {
		$message = [
			'action' => self::MANAGER_ACTION_SEND_TO_ALL,
			'buffer' => Connection::makeSendBuffer( $event, $data, $time ),
		];
		$data = [
			'command' => 'send',
			'roomType' => static::ROOM_TYPE,
			'roomId' => $roomId,
			'message' => $message,
		];

		if ( $target ) {
			$data['target'] = $target;
		}

		return Manager::sendDataToItself( $data );
	}

	/**
	 * @param string $event
	 * @param array $data
	 * @param string|null $time
	 */
	public function sendToAll( string $event, array $data = [], ?string $time = null ) {
		$buffer = Connection::makeSendBuffer( $event, $data, $time );
		$message = [
			'action' => self::MANAGER_ACTION_SEND_TO_ALL,
			'buffer' => $buffer,
		];
		$this->sendToManager( 'send', $message );
	}

	/**
	 * @param string $command
	 * @param array|null $message
	 * @param array|null $target
	 */
	protected function sendToManager( string $command, ?array $message = null, ?array $target = null ) {
		if ( !$this->managerConnection ) {
			$this->debugLog( __FUNCTION__, 'ERROR: managerConnection is undefined' );
			return;
		}

		$data = [
			'command' => $command,
			'roomType' => static::ROOM_TYPE,
			'roomId' => $this->roomId,
			'key' => $this->getManagerKey(),
		];
		if ( $target ) {
			$data['target'] = $target;
		}
		if ( $message ) {
			$data['message'] = $message;
		}
		$buffer = FormatJson::encode( $data );
		$this->debugLog( __FUNCTION__, $buffer );
		$this->managerConnection->send( $buffer );
	}

	// /**
	//  * @param string $name
	//  * @param mixed $value
	//  */
	// protected function syncManagerData( string $name, $value ) {
		// $this->sendToManager(
			// 'sync',
			// [
				// 'name' => $name,
				// 'value' => $value,
			// ]
		// );
	// }

	/**
	 * @param string $command
	 * @param string $providerName
	 * @param mixed $value
	 * @param bool $sync
	 * @param array|null $target
	 */
	protected function sendDatabaseCommand( string $command, string $providerName, $value, bool $sync = false, ?array $target = null ) {
		$this->debugLog( __FUNCTION__, $command, $providerName );
		$this->sendToManager(
			$command,
			[
				'providerName' => $providerName,
				'value' => $value,
				'sync' => $sync,
			],
			$target
		);
	}

	/**
	 * @param string $name
	 * @param mixed $value
	 * @param bool $sync
	 */
	protected function setManagerData( string $name, $value, bool $sync = false ) {
		$this->sendToManager(
			'set',
			[
				'name' => $name,
				'value' => $value,
				'sync' => $sync,
			]
		);
	}

	/**
	 * @param string $name
	 * @param array|null $target
	 */
	protected function getManagerData( string $name, ?array $target = null ) {
		$this->sendToManager(
			'get',
			[ 'name' => $name ],
			$target
		);
	}

	/**
	 * @param string $name
	 * @param Connection $connection
	 */
	protected function getManagerDataForConnection( string $name, Connection $connection ) {
		$this->getManagerData( $name, [ self::TARGET_CONNECTION => $connection->getId() ] );
	}

	/**
	 * @param string $action
	 * @param array $data
	 * @param array|null $target
	 */
	protected function onManagerAction( string $action, array $data, ?array $target = null ) {
		$this->debugLog( __FUNCTION__, $action );
		switch ( $action ) {
			case self::MANAGER_ACTION_SEND_TO_ALL:
				$this->sendBufferToAllInRoom( $data['buffer'] ?? null );
				break;
			case self::MANAGER_ACTION_SYNC:
				$this->onManagerSyncAction(
					$data['sync'] ?? null,
					$data['name'] ?? null,
					$data['value'] ?? null
				);
				break;
		}
	}

	/**
	 * @param string $info
	 * @param array|null $answer
	 * @param array|null $target
	 */
	protected function onManagerInfo( string $info, ?array $answer, ?array $target = null ) {
		if ( $target === null ) {
			$this->onManagerDataReceived( $info, $answer );
			return;
		}

		if ( $answer === null ) {
			$this->debugLog( __FUNCTION__, 'ERROR: answer is NULL' );
			return;
		}

		$connections = $this->getConnections( $target );
		foreach ( $connections as $c ) {
			$this->debugLog( __FUNCTION__, 'SEND to client', $c->getId(), $info );
			$c->send( $info, [ 'answer' => $answer ] );
		}
	}

	/**
	 * @param ConnectionInterface $connection
	 * @param string $buffer
	 */
	public function onManagerMessage( ConnectionInterface $connection, string $buffer ) {
		$exploded = explode( '}{', $buffer );
		$lastKey = count( $exploded ) - 1;
		foreach ( $exploded as $k => $v ) {
			if ( $lastKey > 0 ) {
				if ( $k !== 0 ) {
					$v = '{' . $v;
				}
				if ( $k !== $lastKey ) {
					$v .= '}';
				}
			}
			$this->onManagerAnswer( $connection, $v );
		}
	}

	/**
	 * @param ConnectionInterface $connection
	 * @param string $buffer
	 */
	protected function onManagerAnswer( ConnectionInterface $connection, string $buffer ) {
		$this->debugLog( __FUNCTION__, $buffer );
		$data = FormatJson::decode( $buffer, true );

		$timerName = $data[Manager::EVENT_TIMER] ?? null;
		if ( $timerName ) {
			$this->onManagerTimer( $timerName, $data );
			return;
		}

		$action = $data['action'] ?? null;
		$target = $data['target'] ?? null;
		if ( $action ) {
			$this->onManagerAction( $action, $data, $target );
			return;
		}

		$info = $data['info'] ?? null;
		$answer = $data['answer'] ?? null;
		if ( $info ) {
			$this->onManagerInfo( $info, $answer, $target );
			return;
		}

		$this->debugLog( __FUNCTION__, 'ERROR: Unhandled answer', var_export( $data, true ) );
	}

	/**
	 * @param string $name
	 * @return mixed|null
	 */
	public static function getConfigValue( string $name ) {
		$config = RequestContext::getMain()->getConfig();
		try {
			return $config->get( $name );
		} catch ( ConfigException $e ) {
			MWDebug::warning( $e->getMessage() );
		}
		return null;
	}

	/**
	 * @param array $message
	 */
	protected function connectToManager( array $message = [] ) {
		$managerSocketName = self::getConfigValue( 'LiveChatManagerSocketName' );
		$this->debugLog( __FUNCTION__, $managerSocketName );
		try {
			$this->managerConnection = new AsyncTcpConnection( $managerSocketName );
			$this->managerConnection->onMessage = [ $this, 'onManagerMessage' ];
			$this->managerConnection->onClose = [ $this, 'onManagerClose' ];
			$this->managerConnection->onError = [ $this, 'onManagerError' ];
			$this->managerConnection->connect();

			$message += [
				'roomClass' => static::class,
				'roomType' => static::ROOM_TYPE,
				'roomId' => $this->roomId,
				'key' => $this->getManagerKey(),
			];

			if ( $this->managerTimers ) {
				$message[Manager::ADD_TIMERS] = array_keys( $this->managerTimers );
			}
			$this->sendToManager( 'subscribe', $message );
		} catch ( Exception $e ) {
			$this->debugLog( __FUNCTION__, 'ERROR', $e->getMessage() );
			wfLogWarning( $e->getMessage() );
		}
	}

	/**
	 * @param ConnectionInterface $connection
	 */
	public function onManagerClose( ConnectionInterface $connection ) {
		$this->debugLog( __FUNCTION__, $connection->id ?? 'undefined' );
	}

	/**
	 * @param ConnectionInterface $connection
	 * @param mixed $code
	 * @param string $msg
	 */
	public function onManagerError( ConnectionInterface $connection, $code, $msg ) {
		$this->debugLog( __FUNCTION__, $connection->id ?? 'undefined', $code, $msg );
	}

	/**
	 * @return string
	 */
	protected function getManagerKey(): string {
		return static::ROOM_TYPE . '#' . $this->roomId;
	}

	/**
	 * @param string ...$args
	 */
	protected function debugLog( ...$args ) {
		$roomType = static::ROOM_TYPE;
		$roomId = $this->roomId;
		wfDebugLog(
			__CLASS__,
			"$roomType $roomId " . static::class . '::' . implode( '; ', $args ),
			false
		);
	}

	/**
	 * @param string $timerName
	 * @param array $data
	 */
	protected function onManagerTimer( string $timerName, array $data ) {
		$this->debugLog( __FUNCTION__, $timerName, $data[Manager::TIMER_ITERATION], $data[Manager::TIMER_NUMBER] );
	}

	/**
	 * @param string|null $command
	 * @param string|null $name
	 * @param mixed $value
	 */
	protected function onManagerSyncAction( ?string $command, ?string $name, $value ) {
		$this->debugLog( __FUNCTION__, $command, $name );
	}

	/**
	 * @param string $info
	 * @param array|null $answer
	 */
	protected function onManagerDataReceived( string $info, ?array $answer ) {
		$this->debugLog( __FUNCTION__, $info );
	}
}
