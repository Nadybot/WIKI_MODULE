<?php declare(strict_types=1);

namespace Nadybot\User\Modules\WIKI_MODULE;

use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	Http,
	HttpResponse,
	ModuleInstance,
	Text,
};

use Safe\Exceptions\JsonException;
use function Safe\json_decode;

/**
 * @author Nadyita (RK5) <nadyita@hodorraid.org>
 */

#[
	NCA\Instance,
	NCA\DefineCommand(
		command:     'wiki',
		accessLevel: 'guest',
		description: 'Look up a word in Wikipedia',
	)
]
class WikiController extends ModuleInstance {
	#[NCA\Inject]
	public Http $http;

	#[NCA\Inject]
	public Text $text;

	/**
	 * Look up Wikipedia entries
	 */
	#[NCA\HandlesCommand("wiki")]
	public function wikiCommand(CmdContext $context, string $search): void {
		$this->http
			->get('https://en.wikipedia.org/w/api.php')
			->withQueryParams([
				'format' => 'json',
				'action' => 'query',
				'prop' => 'extracts',
				'exintro' => 1,
				'explaintext' => 1,
				'redirects' => 1,
				'titles' => html_entity_decode($search),
			])
			->withTimeout(5)
			->withCallback([$this, "handleExtractResponse"], $context);
	}

	/**
	 * Handle the response for a list of links origination from a page
	 */
	public function handleLinksResponse(HttpResponse $response, CmdContext $context): void {
		$linkPage = $this->parseResponseIntoWikiPage($response, $context);
		if ($linkPage === null) {
			return;
		}
		$blobs = array_map(
			function(WikiLink $link) {
				return $this->text->makeChatCmd($link->title, '/tell <myname> wiki ' . $link->title);
			},
			$linkPage->links
		);
		$blob = join("\n", $blobs);
		$msg = $this->text->makeBlob($linkPage->title . ' (disambiguation)', $blob);
		$context->reply($msg);
	}

	/**
	 * Handle the response for a wiki page
	 */
	public function handleExtractResponse(HttpResponse $response, CmdContext $context): void {
		$page = $this->parseResponseIntoWikiPage($response, $context);
		if ($page === null) {
			return;
		}

		// In case we have a page that gives us a list of terms, but no exact match,
		// query for all links in that page and present them
		if (preg_match('/may refer to:$/', trim($page->extract??""))) {
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
				->withCallback([$this, "handleLinksResponse"], $context);
			return;
		}
		$this->http
			->get('https://en.wikipedia.org/w/api.php')
			->withQueryParams([
				'format' => 'json',
				'action' => 'parse',
				'prop' => 'text',
				'pageid' => $page->pageid,
			])
			->withTimeout(5)
			->withCallback([$this, "handleParseResponse"], $context, $page);
		return;
	}

	/**
	 * Handle the response for a list of links origination from a page
	 */
	public function handleParseResponse(HttpResponse $response, CmdContext $context, WikiPage $page): void {
		if (isset($response->error)) {
			$msg = "There was an error getting data from Wikipedia: ".
				$response->error . ". Please try again later.";
			$context->reply($msg);
			return;
		}
		if (!isset($response->body)) {
			$msg = "Empty reply received from Wikipedia. ".
				"Please try again later.";
			$context->reply($msg);
			return;
		}
		try {
			$wikiData = json_decode($response->body, false, 8);
		} catch (JsonException $e) {
			$msg = "Unable to parse Wikipedia's reply.";
			$context->reply($msg);
			return;
		}
		$blob = $page->extract??"";
		$blob = preg_replace('/([a-z0-9])\.([A-Z])/', '$1. $2', $blob);
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
					/**
					 * @var array<int,array<int|string>> $matches
					 * @phpstan-var array<int,array{string,int}> $matches
					 */
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
		$context->reply($msg);
	}

	/**
	 * Parse the AsyncHttp reply into a WikiPage object or null on error
	 */
	protected function parseResponseIntoWikiPage(HttpResponse $response, CmdContext $context): ?WikiPage {
		if (isset($response->error)) {
			$msg = "There was an error getting data from Wikipedia: ".
				"{$response->error}. Please try again later.";
			$context->reply($msg);
			return null;
		}
		if (!isset($response->body)) {
			$msg = "Empty reply received from Wikipedia. Please try again later.";
			$context->reply($msg);
			return null;
		}
		try {
			$wikiData = json_decode($response->body, true, 8);
		} catch (JsonException $e) {
			$msg = "Unable to parse Wikipedia's reply: " . $e->getMessage();
			$context->reply($msg);
			return null;
		}
		/** @var int */
		$pageID = array_keys($wikiData["query"]["pages"])[0];
		$page = $wikiData["query"]["pages"][$pageID];
		if ($pageID === -1) {
			$msg = "Couldn't find a Wikipedia entry for <highlight>{$page['title']}<end>.";
			$context->reply($msg);
			return null;
		}
		$page["pageid"] = (int)$pageID;
		$wikiRef = new WikiPage($page);
		return $wikiRef;
	}
}
