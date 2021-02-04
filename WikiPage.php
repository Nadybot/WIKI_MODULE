<?php declare(strict_types=1);

namespace Nadybot\User\Modules\WIKI_MODULE;

use Nadybot\Core\JSONDataModel;

class WikiPage extends JSONDataModel {
	public string $title;
	public int $id;
	public string $extract;
	public array $links = [];
}
