<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ApiResources;

use Illuminate\Http\Request;

/**
 * A member of the {@see ListedResource} family, mentioning its collection nowhere.
 */
final class CatalogueResource extends ListedResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return ['name' => 'widget'];
    }
}
