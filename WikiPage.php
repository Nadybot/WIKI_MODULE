<?php declare(strict_types=1);

namespace Nadybot\User\Modules\WIKI_MODULE;

use Spatie\DataTransferObject\{
	Attributes\CastWith,
	Casters\ArrayCaster,
	DataTransferObject,
};

class WikiPage extends DataTransferObject {
	public int $pageid;
	public int $ns;
	public string $title;
	public ?string $extract = null;

	/** @var WikiLink[] */
	#[CastWith(ArrayCaster::class, itemType: WikiLink::class)]
	public array $links = [];
}
