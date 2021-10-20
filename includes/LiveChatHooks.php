<?php

use LiveChat\ChatData;
use LiveChat\ChatRoom;
use LiveChat\Connection;
use LiveChat\ManagerRoom;
use MediaWiki\MediaWikiServices;

class LiveChatHooks {

	/**
	 * @var ChatRoom[]
	 */
	private static $chatRooms = [];

	/**
	 * @var ManagerRoom
	 */
	private static $managerRoom;

	/**
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/BeforePageDisplay
	 * @param OutputPage &$out
	 * @param Skin &$skin
	 */
	public static function onBeforePageDisplay( OutputPage &$out, Skin &$skin ) {
		// $out->addModules( 'ext.LiveChat.client' );
	}

	/**
	 * @param Connection $connection
	 */
	public static function onLiveChatConnected( Connection $connection ) {
		static $specialLiveChatText, $specialLiveStatusText;

		$title = $connection->getTitle();
		if ( $title && $title->getNamespace() === NS_SPECIAL ) {
			if ( !$specialLiveChatText ) {
				$specialLiveChatText = SpecialPage::getTitleFor( 'LiveChat' )->getText();
				$specialLiveStatusText = SpecialPage::getTitleFor( 'LiveStatus' )->getText();
			}
			$rootText = $title->getRootText();
			echo "####################### $rootText\n";
			echo "$ $specialLiveChatText $$$ $specialLiveStatusText\n";
			if ( $rootText === $specialLiveChatText ) {
				$subpageText = $title->getSubpageText();
				if ( empty( self::$chatRooms[$subpageText] ) ) {
					self::$chatRooms[$subpageText] = new ChatRoom();
				}
				$connection->addRoom( self::$chatRooms[$subpageText] );
			} elseif ( $rootText === $specialLiveStatusText ) {
				echo '$connection->addRoom( self::getManagerRoom() );';
				$connection->addRoom( self::getManagerRoom() );
			}
		}
	}

	/**
	 * @param array &$providers
	 */
	public static function onLiveChatStorageInit( &$providers ) {
		$providers[ChatData::class] = ChatData::class;
	}

	/**
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/MakeGlobalVariablesScript
	 * @param array &$vars
	 * @param OutputPage $out
	 * @throws ConfigException
	 */
	public static function onMakeGlobalVariablesScript( &$vars, OutputPage $out ) {
		$config = MediaWikiServices::getInstance()->getConfigFactory()
			->makeConfig( 'main' );

		$domain = $config->get( 'LiveChatClientDomain' ) ?: self::getDomain();
		$port = $config->get( 'LiveChatClientPort' );
		$path = $config->get( 'LiveChatClientPath' );
		$protocol = $config->get( 'LiveChatClientTLS' ) ? 'wss' : 'ws';

		$vars['LiveChatClientURL'] = "$protocol://$domain:$port/$path";
	}

	/**
	 * @return string
	 */
	private static function getDomain() {
		global $wgServer, $wgServerName;

		$serverParts = wfParseUrl( $wgServer );
		return $serverParts && isset( $serverParts['host'] ) ? $serverParts['host'] : $wgServerName;
	}

	/**
	 * @return ManagerRoom
	 */
	public static function getManagerRoom() {
		if ( !self::$managerRoom ) {
			self::$managerRoom = new ManagerRoom(
				0,
				[
					ManagerRoom::O_ROOM_RESTRICTION => 'LiveChatManager',
				]
			);
		}
		return self::$managerRoom;
	}

	/**
	 * This is attached to the MediaWiki 'LoadExtensionSchemaUpdates' hook.
	 * Fired when MediaWiki is updated to allow extensions to update the database
	 * @param DatabaseUpdater $updater
	 */
	public static function onLoadExtensionSchemaUpdates( DatabaseUpdater $updater ) {
		$updater->addExtensionTable( 'lch_messages', __DIR__ . '/../sql/messages.sql' );
		$updater->addExtensionField( 'lch_messages', 'lchm_has_children', __DIR__ . '/../sql/patch_messages_add_has_children.sql' );
		$updater->addExtensionTable( 'lch_msg_reactions', __DIR__ . '/../sql/msg_reactions.sql' );
	}
}
