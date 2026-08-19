<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Extensions;

use Docuccino\Core\Inference\ArgumentSlots;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\TraceVisitor;
use Docuccino\Core\Inference\TypeScope;
use Docuccino\Laravel\Support\BinaryRepresentation;
use Docuccino\Laravel\Support\EventStreamRepresentation;
use Docuccino\Laravel\Support\FileMediaTypes;
use Docuccino\Laravel\Support\FrameworkClasses;
use PhpParser\Node;

/**
 * Finds the calls that build a file, download, stream or event-stream response, and reads what each one
 * PROVES: the media type it serves, the `Content-Disposition` it sets, and the download name. The return
 * TYPE alone can't say — `response()->download(…)` and `response()->file(…)` are both a bare
 * `BinaryFileResponse` — so the call site is the only place the difference is written down.
 *
 * Receivers are matched by type, not by spelling, so `response()`, an injected `ResponseFactory`, the
 * `Response` facade, `Storage::` and `Storage::disk('s3')->` all land here and an application's own
 * `download()` never does.
 *
 * @phpstan-type CallSpec array{class: string, file: ?int, name: ?int, headers: ?int, sets: ?string, disposition: ?int, needsName: bool, mediaType: ?string, schema: array<string, mixed>}
 */
final class FileResponseVisitor implements TraceVisitor
{
    /** The response-factory root; `response()` is typed as the contract and Laravel's own class extends it. */
    private const RESPONSE_FACTORY = 'Illuminate\\Contracts\\Routing\\ResponseFactory';

    /** The filesystem root; `Storage::disk('s3')` is typed as the adapter, which implements it. */
    private const FILESYSTEM = 'Illuminate\\Contracts\\Filesystem\\Filesystem';

    private const RESPONSE_FACADE = 'Illuminate\\Support\\Facades\\Response';

    private const STORAGE_FACADE = 'Illuminate\\Support\\Facades\\Storage';

    /**
     * Every call that produces a file or streamed body, and exactly what it proves. Per entry: the
     * response class it hands back; `file`, `name` and `disposition` are argument positions, and
     * `headers` is the argument a `Content-Type` may be READ from — each null where the call has no such
     * argument, or where reading it would not be the truth; `sets` is the disposition the call sets and
     * `needsName` marks the calls that only set one when a name was supplied; `mediaType` is a type the
     * call fixes outright and `schema` the body under it.
     *
     * Read against the framework: `file()` passes no disposition to `BinaryFileResponse`, so it sets no
     * header at all; `eventStream()` merges its own `Content-Type` OVER the caller's, which is why its
     * headers argument is not one to read while `streamJson()`'s — which the caller can still override —
     * is; and `stream()`/`streamDownload()` set no type at all, which is why an unstated one degrades to
     * {@see BinaryRepresentation::ANY_MEDIA_TYPE} rather than to a plausible-looking one.
     *
     * @var array<string, array<string, CallSpec>>
     */
    private const CALLS = [
        'factory' => [
            'download' => ['class' => FrameworkClasses::BINARY_FILE_RESPONSE, 'file' => 0, 'name' => 1, 'headers' => 2, 'sets' => FileResponseCall::ATTACHMENT, 'disposition' => 3, 'needsName' => false, 'mediaType' => null, 'schema' => BinaryRepresentation::SCHEMA],
            'file' => ['class' => FrameworkClasses::BINARY_FILE_RESPONSE, 'file' => 0, 'name' => null, 'headers' => 1, 'sets' => null, 'disposition' => null, 'needsName' => false, 'mediaType' => null, 'schema' => BinaryRepresentation::SCHEMA],
            'stream' => ['class' => FrameworkClasses::STREAMED_RESPONSE, 'file' => null, 'name' => null, 'headers' => 2, 'sets' => null, 'disposition' => null, 'needsName' => false, 'mediaType' => null, 'schema' => BinaryRepresentation::SCHEMA],
            'streamDownload' => ['class' => FrameworkClasses::STREAMED_RESPONSE, 'file' => null, 'name' => 1, 'headers' => 2, 'sets' => FileResponseCall::ATTACHMENT, 'disposition' => 3, 'needsName' => true, 'mediaType' => null, 'schema' => BinaryRepresentation::SCHEMA],
            'streamJson' => ['class' => FrameworkClasses::STREAMED_JSON_RESPONSE, 'file' => null, 'name' => null, 'headers' => 2, 'sets' => null, 'disposition' => null, 'needsName' => false, 'mediaType' => 'application/json', 'schema' => []],
            'eventStream' => ['class' => FrameworkClasses::STREAMED_RESPONSE, 'file' => null, 'name' => null, 'headers' => null, 'sets' => null, 'disposition' => null, 'needsName' => false, 'mediaType' => EventStreamRepresentation::MEDIA_TYPE, 'schema' => EventStreamRepresentation::SCHEMA],
        ],
        'filesystem' => [
            'download' => ['class' => FrameworkClasses::STREAMED_RESPONSE, 'file' => 0, 'name' => 1, 'headers' => 2, 'sets' => FileResponseCall::ATTACHMENT, 'disposition' => null, 'needsName' => false, 'mediaType' => null, 'schema' => BinaryRepresentation::SCHEMA],
            'response' => ['class' => FrameworkClasses::STREAMED_RESPONSE, 'file' => 0, 'name' => 1, 'headers' => 2, 'sets' => FileResponseCall::INLINE, 'disposition' => 3, 'needsName' => false, 'mediaType' => null, 'schema' => BinaryRepresentation::SCHEMA],
        ],
    ];

    /**
     * The path helpers whose argument is the file name. Each prepends a directory the engine cannot
     * fold, and none of them touches the trailing segment — which is all the extension is read from.
     *
     * @var list<string>
     */
    private const PATH_HELPERS = ['app_path', 'base_path', 'config_path', 'database_path', 'lang_path', 'public_path', 'resource_path', 'storage_path'];

    /** @var list<FileResponseCall> */
    public array $calls = [];

    public function enterNode(Node $node, TypeScope $scope): bool
    {
        if ($node instanceof Node\Expr\MethodCall && $node->name instanceof Node\Identifier && ! $node->isFirstClassCallable()) {
            $this->record($this->receiverKind($scope->typeOf($node->var)), $node->name->toString(), $node->getArgs(), $scope);
        }

        if ($node instanceof Node\Expr\StaticCall && $node->name instanceof Node\Identifier && $node->class instanceof Node\Name && ! $node->isFirstClassCallable()) {
            $this->record($this->facadeKind($node->class->toString()), $node->name->toString(), $node->getArgs(), $scope);
        }

        return true;
    }

    /**
     * The calls that produce one response class, in source order.
     *
     * @return list<FileResponseCall>
     */
    public function forClass(string $fqcn): array
    {
        return array_values(array_filter($this->calls, static fn (FileResponseCall $call): bool => $call->responseClass === $fqcn));
    }

    /**
     * @param  array<array-key, Node\Arg>  $args
     */
    private function record(?string $kind, string $method, array $args, TypeScope $scope): void
    {
        $spec = $kind === null ? null : (self::CALLS[$kind][$method] ?? null);
        if ($spec === null) {
            return;
        }

        // Everything below is read off a POSITION, and two argument forms may not occupy one: a named
        // argument puts its value under a name instead, and a spread holds a sequence that fills its own
        // index and every later one. `ArgumentSlots` places what it can — a spread the call site
        // wrote out IS its arguments — and nothing is read off what is left, which costs the media type
        // and never mis-states it.
        $slots = ArgumentSlots::of($args);
        if (! $slots->isIndexable()) {
            return;
        }
        $positional = $slots->positional();

        $path = $spec['file'] === null ? null : $this->literalPath($positional[$spec['file']] ?? null, $scope);
        $headers = $spec['headers'] === null ? null : ($positional[$spec['headers']] ?? null);
        $name = $this->suppliedName($spec['name'] === null ? null : ($positional[$spec['name']] ?? null), $scope);

        // Stated first, then whatever the call fixes, then the file's own extension. A call whose type
        // the framework overrides has no headers argument to read, so its own answer still wins.
        $mediaType = $this->statedMediaType($headers, $scope) ?? $spec['mediaType'] ?? FileMediaTypes::forPath($path);

        $this->calls[] = new FileResponseCall(
            responseClass: $spec['class'],
            mediaType: $mediaType ?? ($spec['file'] === null ? BinaryRepresentation::ANY_MEDIA_TYPE : BinaryRepresentation::OCTET_STREAM),
            schema: $spec['schema'],
            disposition: $this->disposition($spec, $name, $scope, $positional),
            filename: $this->literalString($name, $scope) ?? ($path === null ? null : basename($path)),
        );
    }

    /**
     * The disposition the call really sets. A literal argument overrides the default; a non-literal one
     * leaves it unknown, and a call that only sets a header when a name was supplied sets none without.
     *
     * @param  CallSpec  $spec
     * @param  list<Node\Expr>  $args
     */
    private function disposition(array $spec, ?Node\Expr $name, TypeScope $scope, array $args): ?string
    {
        if ($spec['sets'] === null || ($spec['needsName'] && $name === null)) {
            return null;
        }

        $override = $spec['disposition'] === null ? null : ($args[$spec['disposition']] ?? null);
        if ($override === null) {
            return $spec['sets'];
        }

        $value = $this->literalString($override, $scope);

        return $value === FileResponseCall::ATTACHMENT || $value === FileResponseCall::INLINE ? $value : null;
    }

    /**
     * The name argument as SUPPLIED — an explicit `null` is the same as leaving it out, which is what the
     * framework's own `is_null($name)` check reads, and the difference between a download that carries a
     * `Content-Disposition` and one that doesn't.
     */
    private function suppliedName(?Node\Expr $expr, TypeScope $scope): ?Node\Expr
    {
        if ($expr === null) {
            return null;
        }

        $value = $scope->constantValueOf($expr);

        return $value !== null && $value->isScalar() && $value->scalar === null ? null : $expr;
    }

    /** The media type a literal `Content-Type` entry in a headers array argument names. */
    private function statedMediaType(?Node\Expr $headers, TypeScope $scope): ?string
    {
        if (! $headers instanceof Node\Expr\Array_) {
            return null;
        }

        foreach ($headers->items as $item) {
            $key = $item->key;
            if ($key instanceof Node\Scalar\String_ && strtolower($key->value) === 'content-type') {
                $value = $this->literalString($item->value, $scope);

                return $value === null ? null : FileMediaTypes::normalize($value);
            }
        }

        return null;
    }

    /**
     * A literal file path. `constantValueOf` folds a string and a concatenation of strings; a Laravel
     * path helper is a call it cannot fold, and since none of them touches the trailing segment the
     * literal inside is as good for an extension as the whole path would be.
     */
    private function literalPath(?Node\Expr $expr, TypeScope $scope): ?string
    {
        if ($expr instanceof Node\Expr\FuncCall
            && $expr->name instanceof Node\Name
            && in_array($expr->name->toString(), self::PATH_HELPERS, true)
            && ! $expr->isFirstClassCallable()
        ) {
            return $this->literalString($expr->getArgs()[0]->value ?? null, $scope);
        }

        return $this->literalString($expr, $scope);
    }

    private function literalString(?Node\Expr $expr, TypeScope $scope): ?string
    {
        if ($expr === null) {
            return null;
        }

        $value = $scope->constantValueOf($expr);

        return $value !== null && $value->isScalar() && is_string($value->scalar) ? $value->scalar : null;
    }

    /** Which family of calls a receiver's type belongs to, or null when it is neither. */
    private function receiverKind(DType $type): ?string
    {
        if (! $type instanceof ClassT) {
            return null;
        }

        return match (true) {
            $this->isA($type->fqcn, self::RESPONSE_FACTORY) => 'factory',
            $this->isA($type->fqcn, self::FILESYSTEM) => 'filesystem',
            default => null,
        };
    }

    private function facadeKind(string $fqcn): ?string
    {
        return match (ltrim($fqcn, '\\')) {
            self::RESPONSE_FACADE => 'factory',
            self::STORAGE_FACADE => 'filesystem',
            default => null,
        };
    }

    private function isA(string $fqcn, string $root): bool
    {
        return $fqcn === $root || is_subclass_of($fqcn, $root, true);
    }
}
