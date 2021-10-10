<?php
namespace LiveChat;

class ManagerRoom extends Room {

	const ROOM_TYPE = 1;

	public function onEvent( Connection $connection, string $event, array $data ) {
		switch ( $event ) {
			case 'getRoomList':
				$this->onGetRoomList( $connection, $data );
				break;
			default:
				parent::onEvent( $connection, $event, $data );
		}
	}

	private function onGetRoomList( Connection $connection, array $data ) {
		$this->debugLog( static::class, $connection->getId() );
		$this->sendToManager(
			'status',
			[ 'info' => 'rooms' ],
			[ self::TARGET_CONNECTION => $connection->getId() ]
		);
	}
}
