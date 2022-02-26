<?php declare(strict_types=1);

namespace Nadybot\User\Modules\WIKI_MODULE;

use Spatie\DataTransferObject\DataTransferObject;

class WikiLink extends DataTransferObject {
	public int $ns;
	public string $title;
}
