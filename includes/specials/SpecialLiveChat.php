<?php

namespace LiveChat;

use SpecialPage;

class SpecialLiveChat extends SpecialPage {

	public function __construct() {
		parent::__construct( 'LiveChat' );
	}

	public function execute( $subPage ) {
		$output = $this->getOutput();
		$this->setHeaders();
		$htmlTitle = wfMessage( 'livechat' )->text();
		$output->setPageTitle( $htmlTitle );
		$output->setHTMLTitle( $htmlTitle );

		$output->addModules( 'ext.LiveChat.special.LiveChat' );
	}

}
