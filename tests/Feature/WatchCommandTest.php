<?php

declare(strict_types=1);

use Docuccino\Laravel\Commands\WatchCommand;
use Docuccino\Laravel\Tests\Support\WatchFixture;
use Docuccino\Laravel\Watch\WatchSignal;

/**
 * `docuccino:watch`'s loop, driven by a scripted build runner instead of a subprocess: the option
 * checks it refuses to start on, what it hands each rebuild, the refresh it publishes, and the fact
 * that the refresh channel goes away with the session however it ends.
 */
beforeEach(function (): void {
    $this->fixture = WatchFixture::make();
    setBuild('cache.path', $this->fixture->path('fragments'));

    // See WatchViewerTest: the shipped signal path is shared by every worker, so each run gets one
    // of its own rather than switching a peer's reload endpoint on.
    $this->signal = new WatchSignal($this->fixture->path('watch'));
    app()->instance(WatchSignal::class, $this->signal);
});

afterEach(function (): void {
    $this->signal->clear();
    $this->fixture->remove();
});

it('refuses to run on a disabled install', function (): void {
    config()->set('docuccino.enabled', false);
    scriptWatch(1);

    $this->artisan('docuccino:watch')
        ->expectsOutputToContain('Docuccino is disabled')
        ->assertExitCode(1);
});

it('refuses to watch a document that is not configured', function (): void {
    scriptWatch(1);

    $this->artisan('docuccino:watch', ['document' => 'ghost'])
        ->expectsOutputToContain('Unknown document "ghost".')
        ->assertExitCode(1);
});

it('refuses an interval that is not a number of seconds', function (string $interval): void {
    scriptWatch(1);

    $this->artisan('docuccino:watch', ['--interval' => $interval])
        ->expectsOutputToContain('Unknown --interval')
        ->assertExitCode(1);
})->with(['a word' => ['soon'], 'zero' => ['0'], 'negative' => ['-1'], 'empty' => ['']]);

it('watches the default document when the configuration names none', function (): void {
    // There is no such thing as an installation with no documents: a configuration that names none
    // resolves to the one `default` document, so the watcher has something to watch rather than a
    // refusal. This is the guard on that invariant reaching the command — the loop used to stop here.
    setDocuments([]);
    scriptWatch(1);

    $this->artisan('docuccino:watch')
        ->expectsOutputToContain('Watching default.')
        ->assertExitCode(0);
});

it('builds, publishes a refresh, and takes the refresh channel away again on exit', function (): void {
    $runner = scriptWatch(1);

    $this->artisan('docuccino:watch')
        ->expectsOutputToContain('Pushed a refresh to any open viewer.')
        ->expectsOutputToContain('Stopped watching.')
        ->assertExitCode(0);

    expect($runner->calls)->toBe([['document' => null, 'memoryLimit' => null]])
        // The endpoint is only ever open while a session is: the loop clears it however it ends.
        ->and($this->signal->token())->toBeNull();
});

it('hands each rebuild the document and the memory limit it was given', function (): void {
    $runner = scriptWatch(1);

    $this->artisan('docuccino:watch', ['document' => 'default', '--memory-limit' => '2G'])
        ->assertExitCode(0);

    expect($runner->calls)->toBe([['document' => 'default', 'memoryLimit' => '2G']]);
});

it('says how much it is watching when the fragments name files', function (): void {
    $this->fixture->storeFragment([$this->fixture->path('app/InvoiceController.php')], 'watched');
    scriptWatch(1);

    $this->artisan('docuccino:watch')
        ->expectsOutputToContain('behind your operations')
        ->assertExitCode(0);
});

it('warns that a controller edit will not rebuild when nothing was stored, and names the settings', function (): void {
    // A store the build wrote nothing to — the fragment cache off, or its directory unwritable.
    array_map('unlink', glob($this->fixture->path('fragments/*.json')) ?: []);
    scriptWatch(1);

    $this->artisan('docuccino:watch')
        ->expectsOutputToContain('No operation fragments were stored')
        // One substring, because the two settings and the file are one printed line and Mockery
        // consumes one expectation per write.
        ->expectsOutputToContain('cache.path is writable in docuccino.yaml, or set cache.enabled to true')
        ->assertExitCode(0);
});

it('says nothing about a cached configuration, which cannot reach the fragment cache either way', function (): void {
    // `config:cache` bakes what the config repository holds, and `cache.enabled` is not in it: the
    // build reads the setting out of docuccino.yaml and the env override out of the environment the
    // rebuild is handed. So a cached configuration is not a state this session has to warn about.
    app()->instance('config_loaded_from_cache', true);
    array_map('unlink', glob($this->fixture->path('fragments/*.json')) ?: []);
    scriptWatch(1);

    $this->artisan('docuccino:watch')
        ->doesntExpectOutputToContain('Your configuration is cached')
        ->doesntExpectOutputToContain('config:clear')
        ->assertExitCode(0);
});

it('rebuilds when a watched file moves', function (): void {
    $watched = $this->fixture->path('app/InvoiceController.php');
    $this->fixture->storeFragment([$watched], 'watched');

    $phase = 0;
    pcntl_async_signals(true);
    pcntl_signal(SIGALRM, function () use (&$phase, $watched): void {
        if ($phase === 0) {
            $phase = 1;
            file_put_contents($watched, '<?php // edited, and longer than it was');
            // A second alarm, so a change the poller somehow never sees ends the run instead of
            // hanging the suite.
            pcntl_alarm(5);

            return;
        }

        (function (): void {
            $this->stopping = true;
        })->call(app(WatchCommand::class));
    });

    $runner = scriptWatch(2);
    pcntl_alarm(1);

    try {
        $this->artisan('docuccino:watch', ['--interval' => '0.05'])
            ->expectsOutputToContain('changed; rebuilding')
            ->assertExitCode(0);

        expect($runner->calls)->toHaveCount(2);
    } finally {
        pcntl_alarm(0);
        pcntl_signal(SIGALRM, SIG_DFL);
    }
})->skip(fn (): bool => ! extension_loaded('pcntl'), 'Needs ext-pcntl to move a watched file mid-poll.');

it('rebuilds when a webhook class appears where the directory had none', function (): void {
    mkdir($this->fixture->path('webhooks'), 0755, true);
    setBuild('documents.default.webhooks.dir', $this->fixture->path('webhooks'));
    $this->fixture->storeFragment([$this->fixture->path('app/InvoiceController.php')], 'watched');

    $created = $this->fixture->path('webhooks/InvoicePaid.php');

    $phase = 0;
    pcntl_async_signals(true);
    pcntl_signal(SIGALRM, function () use (&$phase, $created): void {
        if ($phase === 0) {
            $phase = 1;
            file_put_contents($created, '<?php // a webhook class the session never saw');
            pcntl_alarm(5);

            return;
        }

        (function (): void {
            $this->stopping = true;
        })->call(app(WatchCommand::class));
    });

    $runner = scriptWatch(2);
    pcntl_alarm(1);

    try {
        $this->artisan('docuccino:watch', ['--interval' => '0.05'])
            ->expectsOutputToContain('changed; rebuilding')
            ->assertExitCode(0);

        expect($runner->calls)->toHaveCount(2);
    } finally {
        pcntl_alarm(0);
        pcntl_signal(SIGALRM, SIG_DFL);
    }
})->skip(fn (): bool => ! extension_loaded('pcntl'), 'Needs ext-pcntl to create a webhook class mid-poll.');
