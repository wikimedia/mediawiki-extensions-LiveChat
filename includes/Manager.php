<?php
namespace LiveChat;

use Exception;
use FormatJson;
use MWDebug;
use Workerman\Connection\AsyncTcpConnection;
use Workerman\Connection\ConnectionInterface;
use Workerman\Lib\Timer;

class Manager extends Worker {
	const TIMER_ONE_SECOND = 'OneSecondTimer';
	const TIMER_FIVE_SECONDS = 'FiveSecondsTimer';
	const TIMER_TEN_SECONDS = 'TenSecondsTimer';
	const TIMER_ONE_MINUTE = 'OneMinuteTimer';
	const EVENT_TIMER = 'ManagerTimer';
	const TIMER_ITERATION = 'TimerIteration';
	const TIMER_NUMBER = 'TimerNumber';
	const ADD_TIMERS = 'ManagerAddTimers';
	const TARGET_ROOM_CONNECTION = 'trcId';

	/**
	 * @var callable[][]
	 */
	protected $commandCallbacks = [];

	/**
	 * @var ConnectionInterface[]
	 */
	protected $subscribed = [];

	/**
	 * @var ConnectionInterface[][][]
	 */
	protected $rooms = [];

	/**
	 * @var array
	 */
	protected $roomClasses = [];

	/**
	 * @var ConnectionInterface[][]
	 */
	protected $keys = [];

	/**
	 * @var array
	 */
	protected $timers = [];

	/**
	 * @var ConnectionInterface[][]
	 */
	protected $timerConnections = [];

	/**
	 * @var AsyncTcpConnection
	 */
	protected $storageConnection;

	/**
	 * @var int[]
	 */
	protected $timerIterations = [
		self::TIMER_ONE_SECOND => 0,
		self::TIMER_FIVE_SECONDS => 0,
		self::TIMER_TEN_SECONDS => 0,
		self::TIMER_ONE_MINUTE => 0,
	];

	/**
	 * @var array
	 */
	protected $data = [];

	/** @inheritDoc */
	public function __construct( string $socket_name = '', array $context_option = [] ) {
		if ( !$socket_name ) {
			$socket_name = self::getConfigValue( 'LiveChatManagerSocketName' );
		}
		parent::__construct( $socket_name, $context_option );
		$this->name = 'LiveChat Manager';
	}

	/** @inheritDoc */
	public function run() {
		$this->addCommandCallbacks( 'subscribe', [ $this, 'onSubscribeCommand' ] );
		$this->addCommandCallbacks( 'unsubscribe', [ $this, 'onUnsubscribeCommand' ] );
		$this->addCommandCallbacks( 'send', [ $this, 'onSendCommand' ] );
		$this->addCommandCallbacks( 'status', [ $this, 'onStatusCommand' ] );
		$this->addCommandCallbacks( 'get', [ $this, 'onGetCommand' ] );
		$this->addCommandCallbacks( 'set', [ $this, 'onSetCommand' ] );
		$this->addCommandCallbacks( 'insert', [ $this, 'onDatabaseCommand' ] );
		$this->addCommandCallbacks( 'update', [ $this, 'onDatabaseCommand' ] );
		$this->addCommandCallbacks( 'select', [ $this, 'onDatabaseCommand' ] );

		parent::run();
	}

	public function onWorkerStart() {
		parent::onWorkerStart();

		$this->connectToStorage();

		$timerIntervals = [
			self::TIMER_ONE_SECOND => 1,
			self::TIMER_FIVE_SECONDS => 5.2,
			self::TIMER_TEN_SECONDS => 10.4,
			self::TIMER_ONE_MINUTE => 60.6,
		];
		foreach ( $timerIntervals as $timer => $interval ) {
			$timerId = Timer::add( $interval, [ $this, 'onTimer' ], [ $timer ] );
			if ( $timerId ) {
				$this->timers[] = $timerId;
				self::debugLog( __FUNCTION__, 'added timer', $timer, (string)$interval );
			} else {
				self::debugLog( __FUNCTION__, 'ERROR: cannot add timer', $timer, (string)$interval );
				MWDebug::warning( 'Cannot add timer ' . $timer . ' interval ' . $interval );
			}
		}
	}

	/**
	 * @param string $timerName
	 */
	public function onTimer( string $timerName ) {
		$data = [
			self::EVENT_TIMER => $timerName,
			self::TIMER_ITERATION => $this->timerIterations[$timerName]++,
		];
		$timerNumber = 0;

		/** @var ConnectionInterface $connection */
		foreach ( $this->timerConnections[$timerName] ?? [] as $connection ) {
			$data[self::TIMER_NUMBER] = $timerNumber++;
			$buffer = FormatJson::encode( $data );
			$connection->send( $buffer );
		}
	}

	public function onWorkerStop() {
		parent::onWorkerStop();

		foreach ( $this->timers as $timerId ) {
			Timer::del( $timerId );
		}
	}

	/** @inheritDoc */
	public function onConnect( ConnectionInterface $connection ) {
		parent::onConnect( $connection );

		self::sendAnswer( $connection, 'connected' );
	}

	/** @inheritDoc */
	public function onClose( ConnectionInterface $connection ) {
		parent::onClose( $connection );

		$this->onCommand(
			$connection,
			'unsubscribe',
			[ 'command' => 'unsubscribe' ]
		);
	}

	/**
	 * @param string $command
	 * @param callable $callback
	 */
	public function addCommandCallbacks( string $command, callable $callback ) {
		self::debugLog( __FUNCTION__, $command );
		$this->commandCallbacks[$command][] = $callback;
	}

	/**
	 * @param ConnectionInterface $connection
	 * @param string $command
	 * @param array $data
	 */
	protected function onCommand( ConnectionInterface $connection, string $command, array $data ) {
		parent::onCommand( $connection, $command, $data );

		if ( !empty( $this->commandCallbacks[$command] ) ) {
			foreach ( $this->commandCallbacks[$command] as $callback ) {
				call_user_func( $callback, $connection, $data );
			}
		} else {
			self::debugLog( __FUNCTION__, "no callback for command: $command" );
		}
	}

	/**
	 * @param ConnectionInterface $connection
	 * @param array $data
	 */
	public function onSubscribeCommand( ConnectionInterface $connection, array $data ) {
		$id = $connection->id ?? null;
		self::debugLog( __FUNCTION__, $id );
		if ( $id !== null ) {
			$this->subscribed[$id] = $connection;
		} else {
			$this->subscribed[] = $connection;
		}

		$message = $data['message'] ?? null;
		if ( $message && is_array( $message ) ) {
			$roomType = $message['roomType'] ?? 0;
			$roomId = $message['roomId'] ?? 0;
			$connection->roomType = $roomType;
			$connection->roomId = $roomId;
			if ( $id !== null ) {
				$this->rooms[$roomType][$roomId][$id] = $connection;
			} else {
				$this->rooms[$roomType][$roomId][] = $connection;
			}
			if ( !isset( $this->roomClasses[$roomType] ) ) {
				$this->roomClasses[$roomType] = $message['roomClass'] ?? null;
			}

			$key = $message['key'] ?? null;
			if ( $key ) {
				$connection->key = $key;
				if ( $id !== null ) {
					$this->keys[$key][$id] = $connection;
				} else {
					$this->keys[$key][] = $connection;
				}
			}
		}
		foreach ( $message[self::ADD_TIMERS] ?? [] as $timerName ) {
			self::debugLog( __FUNCTION__, $id, "ADD $timerName TIMER" );
			if ( $id !== null ) {
				$this->timerConnections[$timerName][$id] = $connection;
			} else {
				$this->timerConnections[$timerName][] = $connection;
			}
			if ( $connection->timers ?? false ) {
				$connection->timers = [];
			}
			$connection->timers[] = $timerName;
		}
	}

	/**
	 * @param ConnectionInterface $connection
	 * @param array $data
	 */
	public function onUnsubscribeCommand( ConnectionInterface $connection, array $data ) {
		$id = $connection->id ?? null;
		self::debugLog( __FUNCTION__, $id );
		if ( $id !== null ) {
			unset( $this->subscribed[$id] );
		} else {
			$key = array_search( $connection, $this->subscribed, true );
			if ( $key !== false ) {
				unset( $this->subscribed[$key] );
			}
		}

		foreach ( $connection->timers ?? [] as $timerName ) {
			self::debugLog( __FUNCTION__, $id, 'REMOVE TIMER', $timerName );
			if ( $id !== null ) {
				unset( $this->timerConnections[$timerName][$id] );
			} else {
				$key = array_search( $connection, $this->timerConnections[$timerName], true );
				if ( $key !== false ) {
					unset( $this->timerConnections[$timerName][$key] );
				}
			}
		}

		if ( isset( $connection->roomType ) && isset( $connection->roomId ) ) {
			$roomType = $connection->roomType;
			$roomId = $connection->roomId;
			if ( $id !== null ) {
				unset( $this->rooms[$roomType][$roomId][$id] );
			} else {
				$key = array_search( $connection, $this->rooms[$roomType][$roomId], true );
				if ( $key !== false ) {
					unset( $this->rooms[$roomType][$roomId][$key] );
				}
			}
		}

		$key = $connection->key ?? null;
		if ( $key ) {
			if ( $id !== null ) {
				unset( $this->keys[$key][$id] );
			} else {
				$k = array_search( $connection, $this->keys[$key], true );
				if ( $k !== false ) {
					unset( $this->keys[$key][$k] );
				}
			}
		}
	}

	/**
	 * @param ConnectionInterface $connection
	 * @param array $data
	 */
	public function onSendCommand( ConnectionInterface $connection, array $data ) {
		self::debugLog( __FUNCTION__, $connection->id ?? 'undefined', var_export( $data, true ) );

		$message = $data['message'] ?? null;
		if ( !$message ) {
			return;
		}
		if ( is_string( $message ) ) {
			$sendBuffer = $message;
		} else {
			$sendBuffer = FormatJson::encode( $message );
		}

		$trcId = $message['target'][self::TARGET_ROOM_CONNECTION] ?? null;
		if ( $trcId ) {
			self::debugLog( __FUNCTION__, "Send by target room connection: $trcId", $sendBuffer );
			$roomConnection = $this->connections[$trcId] ?? null;
			if ( $roomConnection ) {
				self::sendBuffer( $sendBuffer, [ $roomConnection ] );
			}
			return;
		}

		$key = $data['key'] ?? null;
		if ( $key ) {
			self::debugLog( __FUNCTION__, "Send by key: $key", $sendBuffer );
			foreach ( (array)$key as $k ) {
				$array = $this->keys[$k] ?? [];
				if ( $array ) {
					self::sendBuffer( $sendBuffer, $array, $connection );
				}
			}
			return;
		}

		$roomType = $data['roomType'] ?? null;
		$roomId = $data['roomId'] ?? null;
		if ( $roomType !== null ) {
			foreach ( (array)$roomType as $rt ) {
				if ( $roomId !== null ) {
					foreach ( (array)$roomId as $rid ) {
						self::debugLog( __FUNCTION__, "Send to room type: $rt, id: $rid", $sendBuffer );
						$array = $this->rooms[$rt][$rid] ?? [];
						if ( $array ) {
							self::sendBuffer( $sendBuffer, $array, $connection );
						}
					}
					return;
				}

				self::debugLog( __FUNCTION__, "Send to all rooms by type: $rt", $sendBuffer );
				foreach ( $this->rooms[$rt] ?? [] as $rooms ) {
					foreach ( $rooms as $array ) {
						if ( $array ) {
							self::sendBuffer( $sendBuffer, $array, $connection );
						}
					}
				}
			}
			return;
		}

		self::debugLog( __FUNCTION__, "Send to ALL subscribed connections", $sendBuffer );
		self::sendBuffer( $sendBuffer, $this->subscribed, $connection );
	}

	/**
	 * @param ConnectionInterface $connection
	 * @param array $data
	 */
	public function onStatusCommand( ConnectionInterface $connection, array $data ) {
		$message = $data['message'] ?? [];
		$info = $message['info'] ?? null;
		$target = $message['target'] ?? null;
		self::debugLog( __FUNCTION__, $info );
		switch ( $info ) {
			case 'rooms':
				$return = [];
				foreach ( $this->rooms as $roomType => $rooms ) {
					$return[$roomType] = $this->roomClasses[$roomType] ?? null;
				}
				self::sendAnswer( $connection, 'LiveChatManagerListRooms', $return, $target );
				break;
		}
	}

	/**
	 * @param ConnectionInterface $connection
	 * @param array $data
	 */
	public function onGetCommand( ConnectionInterface $connection, array $data ) {
		$name = $data['message']['name'] ?? null;
		$target = $data['target'] ?? null;
		if ( !$name ) {
			self::debugLog( __FUNCTION__, $connection->id ?? 'undefined', 'ERROR: name is null' );
			$value = null;
		} else {
			// self::debugLog( __FUNCTION__, var_export( $this->data, true ) );
			$value = $this->data[$name] ?? null;
			self::debugLog( __FUNCTION__, $name, var_export( $value, true ) );
		}
		self::sendAnswer( $connection, $name, $value, $target );
	}

	/**
	 * @param ConnectionInterface $connection
	 * @param array $data
	 */
	public function onSetCommand( ConnectionInterface $connection, array $data ) {
		$cId = $connection->id ?? 'undefined';
		$message = $data['message'] ?? [];
		$name = $message['name'] ?? null;
		if ( !$name ) {
			self::debugLog( __FUNCTION__, $cId, 'ERROR: name is null' );
			return;
		}

		$value = $message['value'] ?? null;
		$this->data[$name] = $value;
		self::debugLog( __FUNCTION__, $cId, $name, print_r( $value, true ) );

		if ( $message['sync'] ?? false ) {
			self::debugLog( __FUNCTION__, $cId, 'SYNCHRONIZE', $name );
			$data['message']['action'] = 'sync';
			$data['message']['sync'] = 'set';
			$this->onSendCommand( $connection, $data );
		}
	}

	/**
	 * @param ConnectionInterface $connection
	 * @param array $data
	 */
	public function onDatabaseCommand( ConnectionInterface $connection, array $data ) {
		if ( !empty( $data['target'][Room::TARGET_CONNECTION] ) ) {
			$data['target'][self::TARGET_ROOM_CONNECTION] = $connection->id;
		}
		$buffer = FormatJson::encode( $data );
		$this->storageConnection->send( $buffer );
	}

	/**
	 * @param ConnectionInterface $connection
	 * @param string $info
	 * @param array|null $answer
	 * @param array|null $target
	 */
	protected static function sendAnswer( ConnectionInterface $connection, string $info, ?array $answer = null, ?array $target = null ) {
		$data = [ 'info' => $info ];
		$data['answer'] = $answer;
		if ( $target ) {
			$data['target'] = $target;
		}
		self::sendData( $data, [ $connection ] );
	}

	/**
	 * @param array $data
	 * @param array $connections
	 * @param ConnectionInterface|null $except
	 */
	protected static function sendData( array $data, array $connections, ?ConnectionInterface $except = null ) {
		$buffer = FormatJson::encode( $data );
		self::sendBuffer( $buffer, $connections, $except );
	}

	/**
	 * @param string $buffer
	 * @param ConnectionInterface[] $connections
	 * @param ConnectionInterface|null $except
	 */
	protected static function sendBuffer( string $buffer, array $connections, ?ConnectionInterface $except = null ) {
		self::debugLog( __FUNCTION__, $buffer );
		foreach ( $connections as $c ) {
			if ( $c === $except ) {
				continue;
			}
			$c->send( $buffer );
		}
	}

	// /**
	// * @param string $name
	// * @param mixed $value
	// * @return bool
	// */
	// public static function setData( string $name, $value ): bool {
		// $data = [
			// 'command' => 'set',
			// 'message' => [
				// 'name' => $name,
				// 'value' => $value,
			// ],
		// ];
		// return self::sendDataToItself( $data );
	// }

	/**
	 * @param array $data
	 * @return bool
	 */
	public static function sendDataToItself( array $data ) {
		$buffer = FormatJson::encode( $data );
		return self::sendBufferToItself( $buffer );
	}

	/**
	 * @param string $buffer
	 * @return bool
	 */
	public static function sendBufferToItself( string $buffer ) {
		self::debugLog( __FUNCTION__, $buffer );
		$managerSocketName = self::getConfigValue( 'LiveChatManagerSocketName' );
		$instance = stream_socket_client( $managerSocketName );
		fwrite( $instance, $buffer );
		fclose( $instance );
		return true;
	}

	protected function connectToStorage() {
		$socketName = self::getConfigValue( 'LiveChatStorageSocketName' );
		$this->debugLog( __FUNCTION__, $socketName );
		try {
			$this->storageConnection = new AsyncTcpConnection( $socketName );
			$this->storageConnection->onMessage = [ $this, 'onStorageMessage' ];
			$this->storageConnection->onClose = [ $this, 'onStorageClose' ];
			$this->storageConnection->onError = [ $this, 'onStorageError' ];
			$this->storageConnection->connect();
		} catch ( Exception $e ) {
			$this->debugLog( __FUNCTION__, 'ERROR', $e->getMessage() );
			wfLogWarning( $e->getMessage() );
		}
	}

	/**
	 * @param ConnectionInterface $connection
	 * @param string $value
	 */
	public function onStorageMessage( ConnectionInterface $connection, string $value ) {
		$this->debugLog( __FUNCTION__ );
		$this->onMessage( $connection, $value );
	}

	public function onStorageClose() {
		$this->debugLog( __FUNCTION__ );
	}

	public function onStorageError() {
		$this->debugLog( __FUNCTION__ );
	}
}
