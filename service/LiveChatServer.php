<?php

namespace LiveChat;

use ConfigException;
use FatalError;
use Hooks;
use Maintenance;
use MWException;
use Workerman\Connection\ConnectionInterface;
use Workerman\Worker;

if ( getenv( 'MW_INSTALL_PATH' ) ) {
	$IP = getenv( 'MW_INSTALL_PATH' );
} else {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";

class LiveChatServer extends Maintenance {

	/**
	 * @var Worker
	 */
	public $wsWorker;

	public function __construct() {
		parent::__construct();
		$this->requireExtension( 'LiveChat' );
	}

	/**
	 * @inheritDoc
	 * @throws ConfigException
	 * @throws FatalError
	 * @throws MWException
	 */
	public function execute() {
		$config = $this->getConfig();

		$liveChatDebugLogFile = $config->get( 'LiveChatDebugLogFile' );
		if ( $liveChatDebugLogFile ) {
			global $wgDebugLogFile;
			$wgDebugLogFile = $liveChatDebugLogFile;
		}

		$unique_prefix = str_replace( '/', '_', __FILE__ );
		$pidPatch = $config->get( 'LiveChatPidPath' ); // /tmp
		Worker::$pidFile = "{$pidPatch}/{$unique_prefix}.pid";

		$logFile = $config->get( 'LiveChatLogFile' );
		if ( $logFile ) {
			Worker::$logFile = $logFile;
		}

		// Create a Manager server
		$this->managerWorker = new Manager();

		// Create Storage Worker
		$this->storageWorker = new Storage();

		// Create a Websocket server
		$address = $config->get( 'LiveChatServerAddress' ); // 0.0.0.0
		$port = $config->get( 'LiveChatServerPort' ); // 2346
		$this->wsWorker = new Worker( "websocket://$address:$port" );
		$this->wsWorker->name = 'LiveChar websocket';
		$this->wsWorker->count = $config->get( 'LiveChatServerThreads' );
		// $this->wsWorker->user = $config->get( 'LiveChatSystemUser' );
		// $this->wsWorker->group = $config->get( 'LiveChatSystemGroup' );

		// 4 processes
		// $ws_worker->count = 4;

		// Emitted when new connection come
		$this->wsWorker->onConnect = function ( ConnectionInterface $connection ) {
			$connection->onWebSocketConnect = function ( ConnectionInterface $connection, $buffer ) {
				$c = Connection::factory( $connection );
				$connection->liveChatConnection = $c;

				$this->output( "New connection, user <" . $c->getUser()->getName() .
					">. Connections: " . count( $this->wsWorker->connections ) .
					", users: " . $c->getUsersCount( $c::COUNT_REGISTERED ) .
					", anons: " . $c->getUsersCount( $c::COUNT_ANONYMOUS ) . " \n" );
			};
		};

		// Emitted when data received
		$this->wsWorker->onMessage = function ( ConnectionInterface $connection, $value ) {
			$c = self::getLiveChatConnection( $connection );
			$c->onMessage( $value );

			$this->output( "Message from <" . $c->getUser()->getName() . ">: " . $value . "\n" );
			// $connection->send( $data );
		};

		// Emitted when connection closed
		$this->wsWorker->onClose = function ( ConnectionInterface $connection ) {
			$c = self::getLiveChatConnection( $connection );
			if ( $c ) {
				$c->onClose();
				$this->output( "Connection closed, user <" . $c->getUser()->getName() .
					">. Connections: " . count( $this->wsWorker->connections ) .
					", users: " . $c->getUsersCount( $c::COUNT_REGISTERED ) .
					", anons: " . $c->getUsersCount( $c::COUNT_ANONYMOUS ) . " \n" );
			} else {
				$this->output( "Unknown Connection closed\n" );
			}
		};

		$this->wsWorker->onWorkerReload = static function ( Worker $worker ) {
			/** @var ConnectionInterface $connection */
			foreach ( $worker->connections as $connection ) {
				$connection->close();
			}
		};

		$this->wsWorker->onWorkerStop = static function ( Worker $worker ) {
			/** @var ConnectionInterface $connection */
			foreach ( $worker->connections as $connection ) {
				$connection->close();
			}
		};

		Hooks::run( 'BeforeLiveChatRunAllWorker', [ $this ] );

		// Run worker
		Worker::runAll();
	}

	/**
	 * @param ConnectionInterface $connection
	 * @return Connection|null
	 */
	private static function getLiveChatConnection( ConnectionInterface $connection ): ?Connection {
		return $connection->liveChatConnection ?? null;
	}
}

$maintClass = LiveChatServer::class;
require_once RUN_MAINTENANCE_IF_MAIN;
