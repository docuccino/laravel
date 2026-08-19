<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\Context\ViewerContext;
use Docuccino\Core\Extensions\Contracts\Viewer;
use Docuccino\Core\Extensions\Contracts\ViewerAssets;
use Docuccino\Laravel\Registry\ExtensionRegistry;
use Docuccino\Laravel\Viewer\DefaultViewers;

/**
 * The shipped driver table. Every entry is walked, because a driver that is listed but does not
 * resolve — or whose bundle is not in the package — is a 404 nobody finds until a reader hits it.
 */
it('ships a driver that resolves, names itself and serves its own bundle', function (string $class): void {
    $viewer = app($class);

    expect($viewer)->toBeInstanceOf(Viewer::class)
        // A driver id goes in config by hand, so it stays lowercase and hyphenated.
        ->and($viewer->name())->toMatch('/^[a-z0-9]+(-[a-z0-9]+)*$/');

    // Both shipped drivers serve their script from the package, which is what keeps choosing one from
    // quietly making the docs page depend on the network.
    expect($viewer)->toBeInstanceOf(ViewerAssets::class);

    foreach ($viewer->assets() as $name => $path) {
        expect($name)->toMatch('/^[A-Za-z0-9_-]+$/')
            ->and(is_file($path))->toBeTrue();
    }
})->with(DefaultViewers::all());

it('offers each shipped driver under exactly one name, the default among them', function (): void {
    $names = array_keys(app(ExtensionRegistry::class)->viewers(app(), DefaultViewers::all(), []));

    expect($names)->toHaveCount(count(DefaultViewers::all()))
        ->and($names)->toContain(DefaultViewers::DEFAULT);
});

it('merges a driver from config extensions alongside the built-ins', function (): void {
    $viewers = app(ExtensionRegistry::class)->viewers(app(), DefaultViewers::all(), [new class implements Viewer
    {
        public function name(): string
        {
            return 'from-config';
        }

        public function render(ViewerContext $context): string
        {
            return '';
        }
    }]);

    expect(array_keys($viewers))->toContain('from-config', DefaultViewers::DEFAULT);
});
