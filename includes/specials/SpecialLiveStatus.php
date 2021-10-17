<?php

namespace LiveChat;

use SpecialPage;

class SpecialLiveStatus extends SpecialPage {

	public function __construct() {
		parent::__construct( 'LiveStatus', 'LiveChatManager' );
	}

	public function execute( $subPage ) {
		$output = $this->getOutput();
		$this->setHeaders();
		$htmlTitle = wfMessage( 'livestatus' )->text();
		$output->setPageTitle( $htmlTitle );
		$output->setHTMLTitle( $htmlTitle );

		$output->addModules( 'ext.LiveChat.special.LiveStatus' );
	}

}
