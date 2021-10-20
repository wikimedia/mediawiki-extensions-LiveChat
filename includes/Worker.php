<?php
namespace LiveChat;

use ConfigException;
use FormatJson;
use MWDebug;
use RequestContext;
use Workerman\Connection\ConnectionInterface;

class Worker extends \Workerman\Worker {
	/**
	 * Manager constructor.
	 * @param string $socket_name
	 * @param array $context_option
	 */
	public function __construct( string $socket_name = '', array $context_option = [] ) {
		self::debugLog( __FUNCTION__, $socket_name );
		parent::__construct( $socket_name, $context_option );
	}

	/**
	 * Run manager instance.
	 *
	 * @see Workerman.Worker::run()
	 */
	public function run() {
		self::debugLog( __FUNCTION__ );

		$this->onWorkerStart = [ $this, 'onWorkerStart' ];
		$this->onWorkerStop = [ $this, 'onWorkerStop' ];
		$this->onMessage = [ $this, 'onMessage' ];
		$this->onClose = [ $this, 'onClose' ];
		$this->onConnect = [ $this, 'onConnect' ];

		parent::run();
	}

	/**
	 * @param ConnectionInterface $connection
	 * @param string $value
	 */
	public function onMessage( ConnectionInterface $connection, string $value ) {
		$exploded = explode( '}{', $value );
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
			$this->onExplodedMessage( $connection, $v );
		}
	}

	/**
	 * @param ConnectionInterface $connection
	 * @param string $value
	 */
	protected function onExplodedMessage( ConnectionInterface $connection, string $value ) {
		self::debugLog( __FUNCTION__, $connection->id ?? 'undefined', $value );

		$data = FormatJson::decode( $value, true ) ?? [];
		if ( $data === null ) {
			self::debugLog( __FUNCTION__, $connection->id ?? 'undefined', 'ERROR: CANNOT DECODE JSON', $value );
			return;
		}

		$command = $data['command'] ?? null;
		if ( !$command ) {
			self::debugLog( __FUNCTION__, $connection->id ?? 'undefined', 'ERROR: EMPTY COMMAND', print_r( $data, true ) );
			return;
		}

		foreach ( (array)$command as $cmd ) {
			$this->onCommand( $connection, $cmd, $data );
		}
	}

	/**
	 * @param ConnectionInterface $connection
	 * @param string $command
	 * @param array $data
	 */
	protected function onCommand( ConnectionInterface $connection, string $command, array $data ) {
		self::debugLog( __FUNCTION__, $connection->id ?? 'undefined', $command );
	}

	/**
	 * @param ConnectionInterface $connection
	 */
	public function onConnect( ConnectionInterface $connection ) {
		self::debugLog( __FUNCTION__, $connection->id ?? 'undefined' );
	}

	public function onWorkerStart() {
		self::debugLog( __FUNCTION__ );
	}

	public function onWorkerStop() {
		self::debugLog( __FUNCTION__ );

		/** @var ConnectionInterface $connection */
		foreach ( $this->connections as $connection ) {
			$connection->close();
		}
	}

	/**
	 * @param ConnectionInterface $connection
	 */
	public function onClose( ConnectionInterface $connection ) {
		self::debugLog( __FUNCTION__ );
	}

	/**
	 * @param string $name
	 * @return mixed|null
	 */
	protected static function getConfigValue( string $name ) {
		$config = RequestContext::getMain()->getConfig();
		try {
			return $config->get( $name );
		} catch ( ConfigException $e ) {
			MWDebug::warning( $e->getMessage() );
		}
		return null;
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
}
