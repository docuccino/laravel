<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\RouteBindings;

use Docuccino\Attributes\PathParameter;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Blank;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Daybook;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Merchant;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Post;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Vault;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Waterclock;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Widget;
use Workbench\App\Enums\WidgetPriority;
use Workbench\App\Enums\WidgetStatus;

/**
 * Actions whose route-model bindings the `{param:column}` routes bind against. Each parameter is
 * type-hinted the way implicit binding requires, so the recovery under test is the ROUTE's — which
 * column the template named — and never a guess about the signature.
 */
final class BindingController
{
    public function merchant(Merchant $merchant): array
    {
        return [];
    }

    public function widget(Widget $widget): array
    {
        return [];
    }

    public function vault(Vault $vault): array
    {
        return [];
    }

    public function blank(Blank $blank): array
    {
        return [];
    }

    public function daybook(Daybook $daybook): array
    {
        return [];
    }

    public function waterclock(Waterclock $waterclock): array
    {
        return [];
    }

    /** The same untypable column, with the segment's type declared on the action instead. */
    #[PathParameter('blank', type: 'string', format: 'uuid')]
    public function pinnedBlank(Blank $blank): array
    {
        return [];
    }

    /** The same weakened date column, with the segment's format declared on the action instead. */
    #[PathParameter('daybook', type: 'string', format: 'date-time')]
    public function pinnedDaybook(Daybook $daybook): array
    {
        return [];
    }

    public function post(Post $post): array
    {
        return [];
    }

    /** A binding on something that is not an Eloquent model at all. */
    public function ticket(Ticket $ticket): array
    {
        return [];
    }

    public function status(WidgetStatus $status): array
    {
        return [];
    }

    public function priority(WidgetPriority $priority): array
    {
        return [];
    }

    public function channel(Channel $channel): array
    {
        return [];
    }
}
