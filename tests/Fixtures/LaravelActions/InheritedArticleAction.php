<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\LaravelActions;

/**
 * An action that inherits the AsAction trait from {@see BaseArticleAction} rather than using it
 * directly, so trait detection must walk class_parents() to recognise it (G4).
 */
final class InheritedArticleAction extends BaseArticleAction
{
    public function handle(): bool
    {
        return true;
    }
}
