<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Extensions;

use Docuccino\Core\Document\IgnoredHeaders;
use Docuccino\Core\Draft\OperationDraft;
use Docuccino\Core\Extensions\Context\RouteContext;
use Docuccino\Core\Extensions\Contracts\OperationExtension;
use Docuccino\Core\Extensions\Contracts\OperationPhase;
use Docuccino\Core\Extensions\Ordering\ExtensionOrder;
use Docuccino\Core\Extensions\Ordering\Priorities;
use Docuccino\Core\Extensions\Schema\DeclarationFiles;
use Docuccino\Core\Inference\ActionRef;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Patch\Contribution;
use Docuccino\Core\Provenance\Source;
use Docuccino\Laravel\Support\HeaderNames;
use ReflectionClass;

/**
 * Publishes an optional string `in: header` parameter for every header the action reads by a literal name —
 * in its body, in anything it calls, and in the FormRequest methods the framework runs while resolving it.
 * A read proves presence and nothing more, so every declaration outranks it; {@see skipped()} is what is
 * never published this way, and why.
 */
#[ExtensionOrder(priority: Priorities::LAST)]
final class RequestHeadersExtension implements OperationExtension
{
    private const FORM_REQUEST = 'Illuminate\\Foundation\\Http\\FormRequest';

    /**
     * The methods the framework looks for on a FormRequest by name, since the base class declares none of
     * them — every `method_exists($this, …)` it resolves one with.
     *
     * @var list<string>
     */
    public const FORM_REQUEST_PROBES = ['after', 'authorize', 'rules', 'validator', 'withValidator'];

    /**
     * The FormRequest method that decides whether the request is let in at all. A header read only there is
     * an access-control input: absent it the server answers 403, so "optional" would be false, and its name
     * is often one the API never meant to publish.
     */
    private const AUTHORIZE = 'authorize';

    /**
     * Headers the transport or a proxy sets rather than an API client, lowercased: the target host, the
     * framing, the cookie jar (a cookie is an `in: cookie` parameter, never a header one) and the forwarding
     * headers a proxy writes. A client generated with one of these as a parameter is offered a knob that
     * either does nothing or impersonates the infrastructure in front of the server.
     *
     * @var list<string>
     */
    public const TRANSPORT_HEADERS = [
        'cf-connecting-ip',
        'connection',
        'content-length',
        'cookie',
        'forwarded',
        'host',
        'transfer-encoding',
        'x-forwarded-for',
        'x-forwarded-host',
        'x-forwarded-port',
        'x-forwarded-prefix',
        'x-forwarded-proto',
        'x-real-ip',
    ];

    public function phase(): OperationPhase
    {
        // Security, and last in it: the operation's own requirement names every scheme whose header this
        // must leave alone, including one an extension registered rather than config declared.
        return OperationPhase::Security;
    }

    public function handle(OperationDraft $operation, RouteContext $context): void
    {
        $reads = new RequestHeaderReads;
        $context->trace($reads);
        $this->traceFormRequestHooks($context, $reads);

        $skipped = $this->skipped($operation, $context);

        foreach ($reads->headers() as $key => $header) {
            if (in_array($key, $skipped, true) || IgnoredHeaders::parameter($key)) {
                continue;
            }

            $contribution = Contribution::integration('request', $this->firstRead($context, $header['locations']));

            $parameter = $operation->parameter('header', $header['name']);
            $parameter->setRequired(false, $contribution);
            $parameter->schema()->set('type', 'string', $contribution);
        }
    }

    private function traceFormRequestHooks(RouteContext $context, RequestHeaderReads $reads): void
    {
        $formRequest = $context->formRequestClass;
        if ($formRequest === null || ! class_exists($formRequest)) {
            return;
        }

        // Before the method-presence checks: adding a hook to a warm-cached route's FormRequest, or to a
        // base or trait it inherits one from, has to invalidate its fragment.
        $context->recordDependencyFiles(DeclarationFiles::of($formRequest));

        // What the framework runs while resolving the request for the action: a method the application's
        // class overrides, or one the framework probes for — bar the gate. The action's own calls are
        // traced already, so a header the gate reads and the action reads too is still published.
        foreach ((new ReflectionClass($formRequest))->getMethods() as $method) {
            $declaring = $method->getDeclaringClass()->getName();
            $file = $method->getFileName();
            $name = $method->getName();

            if ($file === false
                || $method->isStatic()
                || is_a(self::FORM_REQUEST, $declaring, true)
                || $name === self::AUTHORIZE
                || (! method_exists(self::FORM_REQUEST, $name) && ! in_array($name, self::FORM_REQUEST_PROBES, true))
            ) {
                continue;
            }

            $line = $method->getStartLine();
            $context->traceFrom(new ActionRef($file, $declaring, $name, $line === false ? 0 : $line), $reads);
        }
    }

    /**
     * Lookup keys never published from a read: one this operation already states under any case (a
     * declaration says more); one OpenAPI says is not a parameter ({@see IgnoredHeaders}); a transport
     * header ({@see TRANSPORT_HEADERS}); an `apiKey` scheme's, whether config declares it or this
     * operation's own requirement names one an extension registered; and the API version header, whose
     * document-wide declaration carries the versions a read cannot.
     *
     * @return list<string>
     */
    private function skipped(OperationDraft $operation, RouteContext $context): array
    {
        $skipped = self::TRANSPORT_HEADERS;
        foreach ($operation->parameterKeys() as $parameter) {
            if (str_starts_with($parameter, 'header:')) {
                $skipped[] = HeaderNames::lookupKey(substr($parameter, strlen('header:')));
            }
        }

        $schemes = $context->document->securitySchemes();
        $registered = $context->components->securitySchemes();
        $requirement = $operation->resolvedField('security');
        foreach (is_array($requirement) ? $requirement : [] as $alternative) {
            foreach (is_array($alternative) ? array_keys($alternative) : [] as $name) {
                $schemes[] = $registered[$name] ?? [];
            }
        }

        foreach ($schemes as $scheme) {
            if (($scheme['type'] ?? null) === 'apiKey' && ($scheme['in'] ?? null) === 'header' && is_string($scheme['name'] ?? null)) {
                $skipped[] = HeaderNames::lookupKey($scheme['name']);
            }
        }

        if ($context->document->declaresApiVersion()) {
            $skipped[] = HeaderNames::lookupKey($context->document->apiVersionHeader());
        }

        return $skipped;
    }

    /**
     * Where the header is first read, by path then line, so the provenance names one place however the
     * walk happened to reach the reads.
     *
     * @param  list<SourceLocation>  $locations
     */
    private function firstRead(RouteContext $context, array $locations): ?Source
    {
        $sources = array_values(array_filter(array_map(
            static fn (SourceLocation $location): ?Source => $context->sourceAt($location),
            $locations,
        )));

        usort($sources, static fn (Source $a, Source $b): int => [$a->file, $a->line ?? 0] <=> [$b->file, $b->line ?? 0]);

        return $sources[0] ?? $context->actionSource();
    }
}
