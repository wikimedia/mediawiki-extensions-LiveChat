<?php
namespace LiveChat;

use Language;
use Linker;
use Parser;
use Sanitizer;
use Title;

class MessageParser {
	const TYPE_TEXT = 'text';
	const TYPE_INTERNAL_LINK = 'internalLink';
	const TYPE_EXTERNAL_LINK = 'externalLink';

	/**
	 * @var array
	 */
	private $result = [];

	/**
	 * @var string
	 */
	private $mUrlProtocols;

	/**
	 * @var string
	 */
	private $mExtLinkBracketedRegex;

	public function __construct() {
		$this->mUrlProtocols = wfUrlProtocols();
		$this->mExtLinkBracketedRegex = '/\[(((?i)' . $this->mUrlProtocols . ')' .
			Parser::EXT_LINK_ADDR .
			Parser::EXT_LINK_URL_CLASS . '*)\p{Zs}*([^\]\\x00-\\x08\\x0a-\\x1F\\x{FFFD}]*?)\]/Su';
	}

	/**
	 * @param string $text
	 * @return array
	 */
	public static function parse( $text ) {
		$parser = new self();
		$parser->parseInternalLinks( $text );
		$parser->parseExternalLinks();
		$parser->parseFreeExternalLinks();
		return $parser->getResult();
	}

	/**
	 * @param string $text
	 */
	private function parseInternalLinks( $text ) {
		static $tc = false, $e1;

		// Parse Internal links
		if ( !$tc ) {
			$tc = Title::legalChars() . '#%';
			# Match a link having the form [[namespace:link|alternate]]trail
			$e1 = "/^(.*?)\[\[([{$tc}]+)(?:\\|(.+?))?]](.*)\$/sD";
		}

		while ( preg_match( $e1, $text, $matches ) ) {
			if ( $matches[1] ) {
				$this->result[] = self::makeText( $matches[1] );
			}
			$this->result[] = self::makeInternalLink( $matches[2], $matches[3] );
			// var_dump( $matches );
			$text = $matches[4];
		}

		if ( $text ) {
			$this->result[] = self::makeText( $text );
		}
	}

	private function parseExternalLinks() {
		/** @var Language $wgLang */
		global $wgLang;

		$key = 0;
		while ( isset( $this->result[$key] ) ) {
			$result = $this->result[$key];
			if ( $result['type'] !== self::TYPE_TEXT || empty( $result['text'] ) ) {
				$key++;
				continue;
			}

			$text = $result['text'];
			$newResult = [];

			$bits = preg_split( $this->mExtLinkBracketedRegex, $text, -1, PREG_SPLIT_DELIM_CAPTURE );
			$tmp = array_shift( $bits );
			if ( $tmp ) {
				$newResult[] = self::makeText( $tmp );
			}

			$i = 0;
			while ( $i < count( $bits ) ) {
				$url = $bits[$i++];
				$i++; // protocol
				$text = $bits[$i++];
				$trail = $bits[$i++];

				# The characters '<' and '>' (which were escaped by
				# removeHTMLtags()) should not be included in
				# URLs, per RFC 2396.
				$m2 = [];
				if ( preg_match( '/&(lt|gt);/', $url, $m2, PREG_OFFSET_CAPTURE ) ) {
					$text = substr( $url, $m2[0][1] ) . ' ' . $text;
					$url = substr( $url, 0, $m2[0][1] );
				}

				$dtrail = '';

				# No link text, e.g. [http://domain.tld/some.link]
				if ( $text !== '' ) {
					# Have link text, e.g. [http://domain.tld/some.link text]s
					# Check for trail
					[ $dtrail, $trail ] = Linker::splitTrail( $trail );

					// Excluding protocol-relative URLs may avoid many false positives.
					if ( preg_match( '/^(?:' . wfUrlProtocolsWithoutProtRel() . ')/', $text ) ) {
						$text = $wgLang->getConverter()->markNoConversion( $text );
					}
				}

				$newResult[] = self::makeExternalLink( $url, $text );
				$newResult[] = self::makeText( $dtrail . $trail );
			}

			$newCount = count( $newResult );
			if ( $newCount === 1 ) {
				$this->result[$key] = $newResult[0];
			} elseif ( $newResult ) {
				array_splice( $this->result, $key, 1, $newResult );
				$key += $newCount;
				continue;
			}
			$key++;
		}
	}

	public function parseFreeExternalLinks() {
		$prots = wfUrlProtocolsWithoutProtRel();
		$urlChar = Parser::EXT_LINK_URL_CLASS;
		$addr = Parser::EXT_LINK_ADDR;

		$key = 0;
		while ( isset( $this->result[$key] ) ) {
			$result = $this->result[$key];
			if ( $result['type'] !== self::TYPE_TEXT || empty( $result['text'] ) ) {
				$key++;
				continue;
			}

			$text = $result['text'];
			$newResult = [];

			while ( preg_match( "!(.*?)(\b(?i:$prots)($addr$urlChar*))(.*)!xu", $text, $matches ) ) {
				// var_dump( $matches );
				if ( $matches[1] ) {
					$newResult[] = self::makeText( $matches[1] );
				}
				$newResult[] = self::makeExternalLink( $matches[2] );
				$text = $matches[4];
			}

			if ( $text === $result['text'] ) {
				$key++;
				continue;
			} elseif ( $text ) {
				$newResult[] = self::makeText( $text );
			}

			$newCount = count( $newResult );
			if ( $newCount === 1 ) {
				$this->result[$key] = $newResult[0];
			} elseif ( $newResult ) {
				array_splice( $this->result, $key, 1, $newResult );
				$key += $newCount;
				continue;
			}
			$key++;
		}
	}

	/**
	 * @param string $text
	 * @return string[]
	 */
	private static function makeText( $text ) {
		return [
			'type' => self::TYPE_TEXT,
			'text' => $text,
		];
	}

	/**
	 * @param string $titleText
	 * @param string $text
	 * @return string[]
	 */
	private static function makeInternalLink( $titleText, $text ) {
		$title = Title::newFromText( $titleText );
		if ( !$title ) {
			return self::makeText( '[[' . $titleText . ( $text ? "|$text" : '' ) . ']]' );
		}

		return [
			'type' => self::TYPE_INTERNAL_LINK,
			'url' => $title->getCanonicalURL(),
			'text' => $text ?: $titleText,
		];
	}

	/**
	 * @param string $url
	 * @param string|null $text
	 * @return array
	 */
	private static function makeExternalLink( $url, $text = null ) {
		return [
			'type' => self::TYPE_EXTERNAL_LINK,
			'url' => Sanitizer::cleanUrl( $url ),
			'text' => $text ?: $url,
			'free' => !$text
		];
	}

	/**
	 * @return array
	 */
	public function getResult(): array {
		return $this->result;
	}

}
