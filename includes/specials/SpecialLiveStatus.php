<?php

namespace LiveChat;

use SpecialPage;

class SpecialLiveStatus extends SpecialPage {

	public function __construct() {
		parent::__construct( 'LiveStatus', 'LiveChatManager' );
	}

	/**
	 * @param string|null $subPage
	 */
	public function execute( $subPage ) {
		$output = $this->getOutput();
		$this->setHeaders();
		$htmlTitle = $this->msg( 'livestatus' )->text();
		$output->setPageTitle( $htmlTitle );
		$output->setHTMLTitle( $htmlTitle );

		$output->addModules( 'ext.LiveChat.special.LiveStatus' );
	}

}
