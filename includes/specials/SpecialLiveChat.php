<?php

namespace LiveChat;

use SpecialPage;

class SpecialLiveChat extends SpecialPage {

	public function __construct() {
		parent::__construct( 'LiveChat' );
	}

	/**
	 * @param string|null $subPage
	 */
	public function execute( $subPage ) {
		$output = $this->getOutput();
		$this->setHeaders();
		$htmlTitle = $this->msg( 'livechat' )->text();
		$output->setPageTitle( $htmlTitle );
		$output->setHTMLTitle( $htmlTitle );

		$output->addModules( 'ext.LiveChat.special.LiveChat' );
	}

}
