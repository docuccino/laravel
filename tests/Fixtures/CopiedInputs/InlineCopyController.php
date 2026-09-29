<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\CopiedInputs;

use Docuccino\Attributes\Summary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/** Controller actions that copy another part of the request into the input they then validate inline. */
final class InlineCopyController
{
    /**
     * @return array<string, int>
     */
    public function store(Request $request): array
    {
        $request->merge(['key' => $request->header('Idempotency-Key'), 'page' => $request->query('page')]);

        $request->validate([
            'key' => 'required|uuid',
            'page' => 'nullable|integer',
            'title' => 'required|string',
        ]);

        return ['id' => 1];
    }

    /**
     * @return array<string, mixed>
     */
    public function factory(Request $incoming): array
    {
        $incoming->merge(['key' => $incoming->header('Idempotency-Key')]);

        $validator = Validator::make($incoming->all(), ['key' => 'required|uuid']);

        return $validator->validate();
    }

    /**
     * @return array<string, mixed>
     */
    #[Summary('Record a note')]
    public function attributed(Request $request): array
    {
        $request->merge(['key' => $request->header('Idempotency-Key')]);

        $validated = $request->validate(['key' => 'required|uuid']);

        return $validated;
    }

    /**
     * @return array<string, mixed>
     */
    public function chained(Request $request): array
    {
        $request->merge(['key' => $request->header('Idempotency-Key')]);

        $validated = Validator::make($request->all(), ['key' => 'required|uuid'])->validate();

        return $validated;
    }

    public function chainedStatement(Request $request): void
    {
        $request->merge(['key' => $request->header('Idempotency-Key')]);

        Validator::make($request->all(), ['key' => 'required|uuid'])->validate();
    }

    /**
     * @return array<string, mixed>
     */
    public function chainedValidated(Request $request): array
    {
        $request->merge(['key' => $request->header('Idempotency-Key')]);

        $validated = Validator::make($request->all(), ['key' => 'required|uuid'])->validated();

        return $validated;
    }

    /**
     * @return array<string, mixed>
     */
    public function dataReplaced(Request $request): array
    {
        $request->merge(['key' => $request->header('Idempotency-Key')]);

        $validator = Validator::make($request->all(), ['key' => 'required|uuid']);
        $validator->setData($request->query());

        return $validator->validate();
    }

    public function chainedOtherData(Request $request): void
    {
        $request->merge(['key' => $request->header('Idempotency-Key')]);

        Validator::make($request->query(), ['key' => 'required|uuid'])->validate();
    }

    /**
     * @return array<string, mixed>
     */
    public function assigned(Request $request): array
    {
        $request->merge(['key' => $request->header('Idempotency-Key')]);

        $validated = $request->validate(['key' => 'required|uuid']);

        return $validated;
    }

    /**
     * @return array<string, mixed>
     */
    public function mergedAfter(Request $request): array
    {
        $validated = $request->validate(['key' => 'required|uuid']);

        $request->merge(['key' => $request->header('Idempotency-Key')]);

        return $validated;
    }

    public function branched(Request $request): void
    {
        $request->merge(['key' => $request->header('Idempotency-Key')]);

        if ($request->isJson()) {
            $request->validate(['key' => 'required|uuid']);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function otherData(Request $request): array
    {
        $request->merge(['key' => $request->header('Idempotency-Key')]);

        // The query string is not where a POST's merge writes.
        $validator = Validator::make($request->query(), ['key' => 'required|uuid']);

        return $validator->validate();
    }

    public function helperFirst(Request $request): void
    {
        $request->merge(['key' => $request->header('Idempotency-Key')]);
        $this->prepare();

        $request->validate(['key' => 'required|uuid']);
    }

    public function formRequest(CertainCopiesRequest $request): void
    {
        $request->merge(['key' => $request->header('Idempotency-Key')]);

        $request->validate(['key' => 'required|uuid']);
    }

    private function prepare(): void
    {
        request()->merge(['key' => 'overwritten']);
    }
}
