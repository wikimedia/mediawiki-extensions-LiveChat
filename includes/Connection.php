<?php

namespace LiveChat;

use Exception;
use FormatJson;
use Hooks;
use MWDebug;
use Title;
use User;
use WebRequest;
use Wikimedia\Timestamp\ConvertibleTimestamp;
use Workerman\Connection\ConnectionInterface;

class Connection {

	/**
	 * @var ConnectionInterface
	 */
	private $connection;

	/**
	 * @var User
	 */
	private $user;

	/**
	 * @var string
	 */
	private $userSession;

	/**
	 * @var Title|null
	 */
	private $title;

	/**
	 * @var Room[]
	 */
	private $rooms = [];

	/**
	 * @var User[]
	 */
	private $lpUsers;

	/**
	 * @var array
	 */
	private $data = [];

	/**
	 * @var Connection[][]
	 */
	private static $anonymous = [];

	/**
	 * @var Connection[][]
	 */
	private static $registered = [];

	const COUNT_ALL = 1;
	const COUNT_REGISTERED = 2;
	const COUNT_ANONYMOUS = 3;

	const EVENT_CONNECT = 'connect';
	const EVENT_PING = 'ping';
	const EVENT_PONG = 'pong';
	const EVENT_SEND_WRONG_ROOM = 'LiveChatWrongRoom';

	/**
	 * Connection constructor.
	 * @param ConnectionInterface $connection
	 * @param User $user
	 */
	public function __construct( ConnectionInterface $connection, User $user ) {
		$this->connection = $connection;
		$this->user = $user;

		if ( $user->isAnon() ) {
			if ( !isset( self::$anonymous[$user->getName()] ) ) {
				self::$anonymous[$user->getName()] = [];
			}
			$this->lpUsers =& self::$anonymous[$user->getName()];
		} else {
			if ( !isset( self::$registered[$user->getName()] ) ) {
				self::$registered[$user->getName()] = [];
			}
			$this->lpUsers =& self::$registered[$user->getName()];
		}

		$this->lpUsers[] = $this;
	}

	/**
	 * @param ConnectionInterface $connection
	 * @return Connection
	 */
	public static function factory( ConnectionInterface $connection ) {
		$_SERVER['REQUEST_TIME_FLOAT'] = microtime( true );
		$_SERVER['REMOTE_ADDR'] = $connection->getRemoteIp();
		$request = new WebRequest();
		$user = User::newFromSession( $request );

		return new self( $connection, $user );
	}

	/**
	 * @return User
	 */
	public function getUser(): User {
		return $this->user;
	}

	/**
	 * @param int $count
	 * @return int|null
	 */
	public function getUsersCount( $count = self::COUNT_ALL ) {
		switch ( $count ) {
			case self::COUNT_ALL:
				return self::getUsersCount( self::COUNT_REGISTERED ) + self::getUsersCount( self::COUNT_ANONYMOUS );
			case self::COUNT_REGISTERED:
				return count( self::$registered );
			case self::COUNT_ANONYMOUS:
				return count( self::$anonymous );
		}
		return null;
	}

	/**
	 * @param string $value
	 */
	public function onMessage( $value ) {
		$data = FormatJson::decode( $value, true ) ?? [];
		$event = $data['event'] ?? null;

		if ( $event === self::EVENT_CONNECT ) {
			$this->onConnectEvent( $data );
			return;
		} elseif ( $event === self::EVENT_PING ) {
			$this->send(
				self::EVENT_PONG,
				[ 'clientTime' => $data['time'] ?? null ]
			);
			return;
		}

		foreach ( $this->rooms as $room ) {
			$room->onEvent( $this, $event, $data );
		}
	}

	/**
	 * @param string $event
	 * @param array $data
	 * @param string|null $time
	 * @return bool|void
	 */
	public function send( string $event, array $data = [], ?string $time = null ) {
		$buffer = self::makeSendBuffer( $event, $data, $time );
		return $this->sendBuffer( $buffer );
	}

	/**
	 * @param string $buffer
	 * @return bool|void
	 */
	public function sendBuffer( string $buffer ) {
		return $this->connection->send( $buffer );
	}

	/**
	 * @param string $event
	 * @param array $data
	 * @param string|null $time
	 * @return string
	 */
	public static function makeSendBuffer( string $event, array $data = [], ?string $time = null ): string {
		if ( !$time ) {
			$time = self::getTime();
		}
		$data['event'] = $event;
		$data['time'] = $time;

		return FormatJson::encode( $data );
	}

	/**
	 * @return string
	 */
	public static function getTime() {
		return ConvertibleTimestamp::now( TS_UNIX );
	}

	/**
	 * @param array $data
	 */
	private function onConnectEvent( array $data ) {
		if ( empty( $this->userSession ) ) {
			$this->userSession = $data['session'] ?? null;
		}

		$pageName = $data['pageName'] ?? null;
		if ( $pageName ) {
			$title = Title::newFromText( $pageName );
			if ( $title ) {
				$this->title = $title;
			}
		}

		try {
			Hooks::run( 'LiveChatConnected', [ $this, $data ] );
		} catch ( Exception $e ) {
			MWDebug::warning( $e->getMessage() );
		}
	}

	public function onClose() {
		foreach ( $this->rooms as $room ) {
			$room->removeConnection( $this );
		}

		$key = array_search( $this, $this->lpUsers );
		if ( $key !== false ) {
			unset( $this->lpUsers[$key] );
			if ( !$this->lpUsers ) { // It is the last connection for the user
				$user = $this->getUser();
				if ( $user->isAnon() ) {
					unset( self::$anonymous[$user->getName()] );
				} else {
					unset( self::$registered[$user->getName()] );
				}
			}
		}
	}

	/**
	 * @param Room $room
	 * @param string|null $name
	 */
	public function addRoom( Room $room, ?string $name = null ) {
		if ( !$name ) {
			$name = get_class( $room );
		}

		if ( !empty( $this->rooms[$name] ) ) {
			$this->rooms[$name]->removeConnection( $this );
		}
		$this->rooms[$name] = $room;
		if ( $room ) {
			$room->addConnection( $this );
		}
	}

	/**
	 * @param string $name
	 * @return Room|null
	 */
	public function getRoom( string $name ): ?Room {
		return $this->rooms[$name] ?? null;
	}

	/**
	 * @return Title|null
	 */
	public function getTitle() {
		return $this->title;
	}

	/**
	 * @return int|string
	 */
	public function getUserKey() {
		$user = $this->user;
		return $user->isAnon() ? $user->getName() : $user->getId();
	}

	/**
	 * @param string $name
	 * @return mixed|null
	 */
	public function getData( $name ) {
		return $this->data[$name] ?? null;
	}

	/**
	 * @param string $name
	 * @param mixed $value
	 */
	public function setData( $name, $value ) {
		$this->data[$name] = $value;
	}

	/**
	 * @param string $error
	 */
	public function sendErrorMessage( string $error ) {
		$this->send(
			'ErrorMessage',
			[ 'error' => $error ]
		);
	}

	/**
	 * @return int|null
	 */
	public function getId() {
		return $this->connection->id ?? null;
	}
}
