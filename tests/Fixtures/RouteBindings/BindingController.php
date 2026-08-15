<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\RouteBindings;

use Docuccino\Laravel\Tests\Fixtures\Eloquent\Blank;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Merchant;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Post;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Vault;
use Docuccino\Laravel\Tests\Fixtures\Eloquent\Widget;

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

    public function post(Post $post): array
    {
        return [];
    }

    /** A binding on something that is not an Eloquent model at all. */
    public function ticket(Ticket $ticket): array
    {
        return [];
    }
}
