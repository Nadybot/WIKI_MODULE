<?php declare(strict_types=1);

namespace Nadybot\User\Modules\WIKI_MODULE;

use StdClass;
use JsonException;
use Nadybot\Core\CommandReply;
use Nadybot\Core\Http;
use Nadybot\Core\HttpResponse;
use Nadybot\Core\Nadybot;
use Nadybot\Core\Text;

/**
 * Authors:
 *	- Nadyita (RK5)
 *
 * @Instance
 *
 * Commands this controller contains:
 *	@DefineCommand(
 *		command     = 'wiki',
 *		accessLevel = 'all',
 *		description = 'Look up a word in Wikipedia',
 *		help        = 'wiki.txt'
 *	)
 */
class WikiController {

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	/** @Inject */
	public Http $http;

	/** @Inject */
	public Text $text;

	/**
	 * Command to look up wiki entries
	 *
	 * @HandlesCommand("wiki")
	 * @Matches("/^wiki\s+(.+)$/i")
	 */
	public function wikiCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$this->http
			->get('https://en.wikipedia.org/w/api.php')
			->withQueryParams([
				'format' => 'json',
				'action' => 'query',
				'prop' => 'extracts',
				'exintro' => 1,
				'explaintext' => 1,
				'redirects' => 1,
				'titles' => html_entity_decode($args[1]),
			])
			->withTimeout(5)
			->withCallback([$this, "handleExtractResponse"], $sendto);
	}

	/**
	 * Handle the response for a list of links origination from a page
	 */
	public function handleLinksResponse(HttpResponse $response, CommandReply $sendto, WikiPage $page): void {
		$linkPage = $this->parseResponseIntoWikiPage($response, $sendto);
		if ($linkPage === null) {
			return;
		}
		$blobs = array_map(
			function($link) {
				return $this->text->makeChatCmd($link->title, '/tell <myname> wiki ' . $link->title);
			},
			$linkPage->links
		);
		$blob = join("\n", $blobs);
		$msg = $this->text->makeBlob($linkPage->title . ' (disambiguation)', $blob);
		$sendto->reply($msg);
	}

	/**
	 * Handle the response for a wiki page
	 */
	public function handleExtractResponse(HttpResponse $response, CommandReply $sendto): void {
		$page = $this->parseResponseIntoWikiPage($response, $sendto);
		if ($page === null) {
			return;
		}

		// In case we have a page that gives us a list of terms, but no exact match,
		// query for all links in that page and present them
		if (preg_match('/may refer to:$/', trim($page->extract))) {
			$this->http
				->get('https://en.wikipedia.org/w/api.php')
				->withQueryParams([
					'format' => 'json',
					'action' => 'query',
					'prop' => 'links',
					'pllimit' => 'max',
					'redirects' => 1,
					'plnamespace' => 0,
					'titles' => $page->title,
				])
				->withTimeout(5)
				->withCallback([$this, "handleLinksResponse"], $sendto, $page);
			return;
		}
		$this->http
			->get('https://en.wikipedia.org/w/api.php')
			->withQueryParams([
				'format' => 'json',
				'action' => 'parse',
				'prop' => 'text',
				'pageid' => $page->id,
			])
			->withTimeout(5)
			->withCallback([$this, "handleParseResponse"], $sendto, $page);
		return;
	}

	/**
	 * Handle the response for a list of links origination from a page
	 */
	public function handleParseResponse(HttpResponse $response, CommandReply $sendto, WikiPage $page): void {
		if (isset($response->error)) {
			$msg = "There was an error getting data from Wikipedia: ".
				$response->error . ". Please try again later.";
			$sendto->reply($msg);
			return;
		}
		try {
			$wikiData = json_decode($response->body, false, 8, JSON_THROW_ON_ERROR);
		} catch (JsonException $e) {
			$msg = "Unable to parse Wikipedia's reply.";
			$sendto->reply($msg);
			return;
		}
		$blob = $page->extract;
		$blob = preg_replace('/([a-z0-9])\.([A-Z])/', '$1. $2', $blob);
		$searches = [];
		$replaces = [];
		$links = [];
		preg_match_all("/(.)<a href=\"\/wiki\/(.+?)\".*?>(.*?)<\/a>(.)/", $wikiData->parse->text->{"*"}, $matches);
		for ($i = 0; $i < count($matches[1]); $i++) {
			if (!strlen($matches[3][$i]) || !strlen($matches[2][$i])) {
				continue;
			}
			$links[$matches[1][$i].$matches[3][$i].$matches[4][$i]] = urldecode(str_replace("_", " ", $matches[2][$i]));
		}
		uksort(
			$links,
			function(string $key1, string $key2): int {
				return strlen($key2) <=> strlen($key1);
			}
		);
		foreach ($links as $text => $link) {
			$blob = preg_replace_callback(
				"/" . preg_quote($text, "/") . "/",
				function (array $matches) use ($blob, $text, $link): string {
					$leftOpen = strrpos(substr($blob, 0, $matches[0][1]), "<a");
					$leftClose = strrpos(substr($blob, 0, $matches[0][1]), "</a");
					if ($leftOpen > $leftClose) {
						return $matches[0][0];
					}
					return substr($text, 0, 1).
						$this->text->makeChatCmd(substr($text, 1, -1), "/tell <myname> wiki {$link}").
						substr($text, -1);
				},
				$blob,
				1,
				$count,
				PREG_OFFSET_CAPTURE
			);
		}
		$msg = $this->text->makeBlob($page->title, $blob);
		$sendto->reply($msg);
	}

	/**
	 * Parse the AsyncHttp reply into a WikiPage object or null on error
	 */
	protected function parseResponseIntoWikiPage(HttpResponse $response, CommandReply $sendto): ?WikiPage {
		if (isset($response->error)) {
			$msg = "There was an error getting data from Wikipedia: ".
				$response->error . ". Please try again later.";
			$sendto->reply($msg);
			return null;
		}
		try {
			$wikiData = json_decode($response->body, false, 8, JSON_THROW_ON_ERROR);
		} catch (JsonException $e) {
			$msg = "Unable to parse Wikipedia's reply.";
			$sendto->reply($msg);
			return null;
		}
		$pageID = array_keys(get_object_vars($wikiData->query->pages))[0];
		$page = $wikiData->query->pages->{$pageID};
		if ($pageID === -1) {
			$msg = "Couldn't find a Wikipedia entry for <highlight>{$page->title}<end>.";
			$sendto->reply($msg);
			return null;
		}
		$wikiPage = new WikiPage();
		$wikiPage->fromJSON($page);
		$wikiPage->id = (int)$pageID;
		return $wikiPage;
	}
}
