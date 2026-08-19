<?php

declare(strict_types=1);

use Docuccino\Core\Emit\EmitOptions;
use Docuccino\Core\Emit\Formats;
use Docuccino\Core\Emit\ProvenanceLevel;
use Docuccino\Laravel\Testing\ApiContract;
use PHPUnit\Framework\AssertionFailedError;

beforeEach(function (): void {
    $this->dir = sys_get_temp_dir().'/docuccino-freshness-'.getmypid();
    $this->artifact = $this->dir.'/api.uir.json';

    if (! is_dir($this->dir)) {
        mkdir($this->dir, 0755, true);
    }

    config()->set('docuccino.documents.default.export.targets', [['format' => 'uir', 'path' => $this->artifact]]);
    bindStubEngine();
});

afterEach(function (): void {
    array_map(unlink(...), (array) glob($this->dir.'/*'));
    @rmdir($this->dir);
    ApiContract::reset();
});

it('passes when the committed artifact is exactly what the code produces', function (): void {
    $build = ApiContract::build();
    file_put_contents($this->artifact, $build->canonicalEmission($build->config()->exportTargets()[0]));

    expect(fn () => ApiContract::assertions()->assertDocumentUpToDate())->not->toThrow(AssertionFailedError::class);
});

it('accepts an artifact exported at a different provenance level, which is not staleness', function (ProvenanceLevel $level, bool $keepIds): void {
    $build = ApiContract::build();
    $emitted = Formats::emit('uir', $build->fresh(), new EmitOptions(keepIds: $keepIds, provenance: $level))->output;
    file_put_contents($this->artifact, $emitted);

    expect(fn () => ApiContract::assertions()->assertDocumentUpToDate())->not->toThrow(AssertionFailedError::class);
})->with([
    'winners' => [ProvenanceLevel::Winners, true],
    'full' => [ProvenanceLevel::Full, true],
    'none' => [ProvenanceLevel::None, true],
    'ids dropped' => [ProvenanceLevel::Winners, false],
]);

it('names the stale file and what changed in it', function (): void {
    $build = ApiContract::build();
    $document = json_decode($build->canonicalEmission($build->config()->exportTargets()[0]), true);
    $document['paths']['/api/forms']['get']['summary'] = 'A summary nobody wrote.';
    file_put_contents($this->artifact, (string) json_encode($document, JSON_PRETTY_PRINT));

    try {
        ApiContract::assertions()->assertDocumentUpToDate();
    } catch (AssertionFailedError $failure) {
        expect($failure->getMessage())
            ->toContain($this->artifact.' is out of date.')
            ->toContain('What changed since it was written:')
            ->toContain('summary')
            ->toContain('Regenerate it: php artisan docuccino:export');

        return;
    }

    throw new RuntimeException('the freshness assertion should have failed');
});

it('says the contract is unchanged when only the bytes differ', function (): void {
    $build = ApiContract::build();
    $document = json_decode($build->canonicalEmission($build->config()->exportTargets()[0]), true);
    // Re-serialised with different whitespace: the same document, different bytes.
    file_put_contents($this->artifact, (string) json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    try {
        ApiContract::assertions()->assertDocumentUpToDate();
    } catch (AssertionFailedError $failure) {
        expect($failure->getMessage())->toContain('The contract itself is unchanged — the artifact differs only in bytes');

        return;
    }

    throw new RuntimeException('the freshness assertion should have failed');
});

it('says so when the artifact has never been written at all', function (): void {
    expect(fn () => ApiContract::assertions()->assertDocumentUpToDate())
        ->toThrow(AssertionFailedError::class, 'has never been written.');
});

it('passes when the committed contract and the code agree', function (): void {
    $build = ApiContract::build();
    file_put_contents($this->artifact, $build->canonicalEmission($build->config()->exportTargets()[0]));

    expect(fn () => ApiContract::assertions()->assertNoBreakingChanges())->not->toThrow(AssertionFailedError::class);
});

it('fails on a breaking change, rendered the way the diff command renders it', function (): void {
    $build = ApiContract::build();
    $document = json_decode($build->canonicalEmission($build->config()->exportTargets()[0]), true);
    // The committed contract promises a property the current code no longer produces.
    $document['components']['schemas']['FormData']['properties']['archivedAt'] = ['type' => 'string'];
    $document['components']['schemas']['FormData']['required'][] = 'archivedAt';
    file_put_contents($this->artifact, (string) json_encode($document));

    try {
        ApiContract::assertions()->assertNoBreakingChanges();
    } catch (AssertionFailedError $failure) {
        expect($failure->getMessage())
            ->toContain('breaking change')
            ->toContain('BREAKING')
            ->toContain('php artisan docuccino:export');

        return;
    }

    throw new RuntimeException('the breaking-change assertion should have failed');
});

it('says a non-breaking change is not one', function (): void {
    $build = ApiContract::build();
    $document = json_decode($build->canonicalEmission($build->config()->exportTargets()[0]), true);
    // An added optional property is additive, so the assertion must not object to it.
    unset($document['components']['schemas']['FormData']['properties']['publishedAt']);
    file_put_contents($this->artifact, (string) json_encode($document));

    expect(fn () => ApiContract::assertions()->assertNoBreakingChanges())->not->toThrow(AssertionFailedError::class);
});

it('says there is nothing to compare against when the artifact is missing', function (): void {
    expect(fn () => ApiContract::assertions()->assertNoBreakingChanges())
        ->toThrow(AssertionFailedError::class, 'There is no committed contract to compare against');
});

it('reads the old side from a git ref, and reports git’s own words when it cannot', function (): void {
    try {
        ApiContract::assertions()->assertNoBreakingChanges('a-ref-that-does-not-exist');
    } catch (AssertionFailedError $failure) {
        expect($failure->getMessage())
            ->toContain('There is no committed contract to compare against')
            ->toContain('git show a-ref-that-does-not-exist:');

        return;
    }

    throw new RuntimeException('the breaking-change assertion should have failed');
});

it('refuses a git ref that git would read as an option', function (): void {
    expect(fn () => ApiContract::assertions()->assertNoBreakingChanges('--upload-pack=evil'))
        ->toThrow(AssertionFailedError::class, 'must not start with "-"');
});

it('holds every example in the generated document to its own schema', function (): void {
    workbenchContract();

    expect(fn () => ApiContract::assertions()->assertValidExamples())->not->toThrow(AssertionFailedError::class);
});

it('fails an example that disagrees with the schema beside it', function (): void {
    $path = workbenchContract();
    $document = json_decode((string) file_get_contents($path), true);
    $document['components']['schemas']['FormData']['properties']['id']['example'] = 'not an integer';
    file_put_contents($path, (string) json_encode($document));
    ApiContract::using($path);

    try {
        ApiContract::assertions()->assertValidExamples();
    } catch (AssertionFailedError $failure) {
        expect($failure->getMessage())
            ->toContain('does not match the schema beside it')
            ->toContain('components/schemas/FormData')
            ->toContain('must match the type: integer');

        return;
    }

    throw new RuntimeException('the example assertion should have failed');
})->after(function (): void {
    @unlink(sys_get_temp_dir().'/docuccino-contract-'.getmypid().'.uir.json');
});
