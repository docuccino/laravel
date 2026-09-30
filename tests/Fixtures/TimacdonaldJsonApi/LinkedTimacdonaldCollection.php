<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\TimacdonaldJsonApi;

use Illuminate\Http\Request;
use TiMacDonald\JsonApi\JsonApiResourceCollection;

/**
 * A timacdonald collection adding a `prev` link of its own on request, which Laravel merges into the page
 * links `paginationInformation()` left — where there may be no `prev` of the page's to merge with.
 */
final class LinkedTimacdonaldCollection extends JsonApiResourceCollection
{
    /**
     * @param  Request  $request
     * @return array<string, mixed>
     */
    public function with($request)
    {
        if ($request->boolean('archived')) {
            return ['links' => ['prev' => 'https://example.com/archive']];
        }

        return [];
    }
}
