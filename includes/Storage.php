<?php
namespace LiveChat;

use Hooks;
use Workerman\Connection\ConnectionInterface;

class Storage extends Worker {

	/**
	 * @var array
	 */
	protected $providers = [];

	/** @inheritDoc */
	public function __construct( string $socket_name = '', array $context_option = [] ) {
		if ( !$socket_name ) {
			$socket_name = self::getConfigValue( 'LiveChatStorageSocketName' );
		}
		parent::__construct( $socket_name, $context_option );
		$this->name = 'LiveChat Storage';
	}

	public function run() {
		Hooks::run( 'LiveChatStorageInit', [ &$this->providers ] );

		parent::run();
	}

	/**
	 * @param ConnectionInterface $connection
	 * @param string $command
	 * @param array $data
	 */
	protected function onCommand( ConnectionInterface $connection, string $command, array $data ) {
		parent::onCommand( $connection, $command, $data );

		$message = $data['message'] ?? null;
		if ( !$message ) {
			self::debugLog( __FUNCTION__, 'ERROR: Message is empty' );
			return;
		}

		$providerName = $message['providerName'] ?? null;
		if ( !$providerName ) {
			self::debugLog( __FUNCTION__, 'ERROR: Provider name is empty' );
			return;
		}

		$className = $this->providers[$providerName] ?? null;
		if ( !$className ) {
			self::debugLog( __FUNCTION__, 'ERROR', 'Unknown provider for name', $providerName );
			return;
		}

		call_user_func( [ $className, 'onCommand' ], $connection, $providerName, $command, $data );
	}
}
