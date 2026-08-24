<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Support;

use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\ClassMetadata;
use Docuccino\Core\Inference\DType\ArrayShapeField;
use Docuccino\Core\Inference\DType\ArrayShapeT;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\DType\DType;
use Docuccino\Core\Inference\DType\EnumT;
use Docuccino\Core\Inference\DType\ListT;
use Docuccino\Core\Inference\DType\LiteralT;
use Docuccino\Core\Inference\DType\MapT;
use Docuccino\Core\Inference\DType\NullT;
use Docuccino\Core\Inference\DType\ScalarT;
use Docuccino\Core\Inference\DType\UnionT;
use Docuccino\Core\Inference\DType\UnknownT;
use Docuccino\Core\Inference\DType\VoidT;
use Docuccino\Core\Inference\PropertyMetadata;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\ThrowConfidence;
use Docuccino\Core\Inference\ThrowDisposition;
use Docuccino\Core\Inference\ThrownException;
use Docuccino\Core\Inference\TraceVisitor;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Tests\Support\StubTypeEngine;

/**
 * Builds the deterministic stub {@see TypeEngine} the feature tests bind for the workbench: canned
 * return types and thrown exceptions standing in for what the real PHPStan engine would recover.
 * JsonResponse-payload unwrapping happens in an integration, so the stub supplies already-unwrapped
 * shapes.
 */
final class WorkbenchEngine
{
    /**
     * @param  array<string, ActionAnalysis>  $callables  scripted CallableRef analyses (keyed by
     *                                                    CallableRef::symbol()) for the inferred-handler tier
     * @param  array<string, ClassMetadata>  $classOverrides  class metadata merged over the defaults
     *                                                        (keyed by FQCN) — e.g. to carry a temp
     *                                                        dependencyFiles for cache-invalidation tests
     * @param  array<string, ActionAnalysis>  $analysisOverrides  action analyses merged over the
     *                                                            defaults (keyed by ActionRef::symbol())
     * @param  array<string, callable(TraceVisitor): void>  $traceOverrides  scripted traces merged over the
     *                                                                       defaults (keyed by
     *                                                                       ActionRef::symbol()), for a
     *                                                                       document whose routes one test
     *                                                                       registers ad-hoc
     */
    public static function make(
        array $callables = [],
        array $classOverrides = [],
        array $analysisOverrides = [],
        array $traceOverrides = [],
    ): StubTypeEngine {
        $location = new SourceLocation('');

        $formData = new ClassMetadata('Workbench\\App\\Data\\FormData', [
            new PropertyMetadata('id', ScalarT::int()),
            new PropertyMetadata('title', ScalarT::string()),
            new PropertyMetadata('publishedAt', UnionT::of([ScalarT::string(), new NullT])),
        ]);

        $widgetData = new ClassMetadata('Workbench\\App\\Data\\WidgetData', [
            new PropertyMetadata('id', ScalarT::int()),
            new PropertyMetadata('name', ScalarT::string()),
            new PropertyMetadata('status', new EnumT('Workbench\\App\\Enums\\WidgetStatus', ['Draft', 'Published', 'Archived'])),
        ]);

        // The FormRequest's rules() as the engine recovers it — a constant array shape (never executed).
        $storeWidgetRules = new ArrayShapeT([
            new ArrayShapeField('name', new LiteralT('required|string|max:100')),
            new ArrayShapeField('quantity', new LiteralT('required|integer|min:1')),
            new ArrayShapeField('avatar', new LiteralT('nullable|image')),
            new ArrayShapeField('role', new LiteralT('required|in:admin,user')),
        ]);

        // A JsonResponse<payload, status> as the bundled PHPStan extension recovers it.
        $jsonResponse = static fn (DType $payload, int $status): ClassT => new ClassT(
            'Illuminate\\Http\\JsonResponse',
            [$payload, new LiteralT($status)],
        );

        $missing = new ClassT('Illuminate\\Http\\Resources\\MissingValue');

        return new StubTypeEngine(
            traces: [
                // Scripts the Query Builder trace so the golden exercises the QB integration
                // deterministically — the stub engine has no real trace.
                'Workbench\\App\\Http\\Controllers\\WidgetQueryController::index' => TraceScript::forChain(
                    "QueryBuilder::for(\\Workbench\\App\\Models\\Form::class)->allowedFilters(['name', AllowedFilter::exact('status')])->allowedSorts(['name', 'created_at'])->defaultSort('name')->paginate(20)",
                ),
                // The include/sparse-fieldset endpoint: the allow-list comment, the relation docblock and
                // the column summary each describe a value, so the golden locks the whole ladder.
                'Workbench\\App\\Http\\Controllers\\LedgerQueryController::index' => TraceScript::forChain(<<<'PHP'
                    QueryBuilder::for(\Workbench\App\Models\Ledger::class)->allowedIncludes([
                        // The forms filed against this ledger.
                        'entries',
                        'auditor',
                    ])->allowedFields(['reference', 'opened_at', 'entries.id'])->paginate(20)
                    PHP),
                // The flagship QB-list endpoint: a QB subclass paginated through a custom terminal
                // (`paginateList`, declared as one in config). It runs over both the QB params visitor
                // (filters/sorts + the custom terminal's page params) and the resource-envelope visitor
                // (paginator kind → {data,links,meta}).
                'Workbench\\App\\Http\\Controllers\\QbListController::index' => TraceScript::forChain(
                    "ListQueryBuilder::for(\\Workbench\\App\\Models\\Form::class)->allowedFilters(['name'])->allowedSorts(['name'])->paginateList(20)",
                    'Workbench\\App\\Support\\ListQueryBuilder',
                ),
                // A paginated resource collection: the chain reaches paginate() on a plain Eloquent
                // builder, typed as such so the Query-Builder visitor (which needs a Spatie QueryBuilder
                // receiver) ignores it. The resource pagination extension wraps the length envelope.
                self::CONTROLLER.'listPaginatedArticles' => TraceScript::forChain(
                    '$q->paginate(15)',
                    'Illuminate\\Database\\Eloquent\\Builder',
                ),
                // A jsonPaginate() collection: json-api-paginate documents its page[...] params, and on
                // the response side the paginator envelope for the configured mode.
                self::CONTROLLER.'listJsonPaginatedArticles' => TraceScript::forChain(
                    '$q->jsonPaginate()',
                    'Illuminate\\Database\\Eloquent\\Builder',
                ),
                // A resource wrapping Model::create() → the created-resource visitor sees the New_ over
                // a create() static call (it reads AST names, so FQCNs are used here) and re-homes 200→201.
                self::CONTROLLER.'storeCreatedArticle' => TraceScript::forChain(
                    'new \\'.self::ARTICLE_RESOURCE.'(\\'.self::WIDGET_MODEL.'::create([]))',
                    'Illuminate\\Database\\Eloquent\\Builder',
                ),
                ...$traceOverrides,
            ],
            analyses: [
                'Workbench\\App\\Http\\Requests\\StoreWidgetRequest::rules' => new ActionAnalysis(
                    returns: [new ReturnSite($storeWidgetRules, $location)],
                ),
                'Workbench\\App\\Http\\Controllers\\FormController::index' => new ActionAnalysis(
                    returns: [new ReturnSite(new ListT(new ClassT('Workbench\\App\\Data\\FormData')), $location)],
                ),
                'Workbench\\App\\Http\\Controllers\\FormController::show' => new ActionAnalysis(
                    returns: [new ReturnSite(new ClassT('Workbench\\App\\Data\\FormData'), $location)],
                    throws: [new ThrownException(
                        'Illuminate\\Database\\Eloquent\\ModelNotFoundException',
                        404,
                        [],
                        ThrowConfidence::Certain,
                        ThrowDisposition::Signal,
                    )],
                ),

                // Spatie Data: request body from the Data class + a Data response under a folded 201.
                self::CONTROLLER.'storeArticle' => new ActionAnalysis(
                    returns: [new ReturnSite($jsonResponse(new ClassT(self::ARTICLE_DATA), 201), $location)],
                ),
                // API Resources: an anonymous collection, and a single resource with whenLoaded fields.
                self::CONTROLLER.'listArticleResources' => new ActionAnalysis(
                    returns: [new ReturnSite(new ClassT('Illuminate\\Http\\Resources\\Json\\AnonymousResourceCollection', [new ClassT(self::ARTICLE_RESOURCE)]), $location)],
                ),
                self::CONTROLLER.'showArticleResource' => new ActionAnalysis(
                    returns: [new ReturnSite(new ClassT(self::ARTICLE_RESOURCE), $location)],
                ),
                // Paginated + jsonPaginate resource collections. The return type is the same
                // AnonymousResourceCollection<ArticleResource> for both; the paginator envelope comes
                // from the scripted trace, not the type.
                self::CONTROLLER.'listPaginatedArticles' => new ActionAnalysis(
                    returns: [new ReturnSite(new ClassT('Illuminate\\Http\\Resources\\Json\\AnonymousResourceCollection', [new ClassT(self::ARTICLE_RESOURCE)]), $location)],
                ),
                self::CONTROLLER.'listJsonPaginatedArticles' => new ActionAnalysis(
                    returns: [new ReturnSite(new ClassT('Illuminate\\Http\\Resources\\Json\\AnonymousResourceCollection', [new ClassT(self::ARTICLE_RESOURCE)]), $location)],
                ),
                // The flagship QB-list return: a resource collection whose envelope is triggered by
                // the custom terminal recovered from the scripted trace (not the type).
                'Workbench\\App\\Http\\Controllers\\QbListController::index' => new ActionAnalysis(
                    returns: [new ReturnSite(new ClassT('Illuminate\\Http\\Resources\\Json\\AnonymousResourceCollection', [new ClassT(self::ARTICLE_RESOURCE)]), $location)],
                ),
                self::CONTROLLER.'storeCreatedArticle' => new ActionAnalysis(
                    returns: [new ReturnSite(new ClassT(self::ARTICLE_RESOURCE), $location)],
                ),
                self::ARTICLE_RESOURCE.'::toArray' => new ActionAnalysis(returns: [new ReturnSite(new ArrayShapeT([
                    new ArrayShapeField('id', ScalarT::int()),
                    new ArrayShapeField('title', ScalarT::string()),
                    new ArrayShapeField('author', UnionT::of([new ClassT(self::AUTHOR_RESOURCE), $missing])),
                    new ArrayShapeField('excerpt', UnionT::of([ScalarT::string(), $missing, new NullT])),
                ]), $location)]),
                self::AUTHOR_RESOURCE.'::toArray' => new ActionAnalysis(returns: [new ReturnSite(new ArrayShapeT([
                    new ArrayShapeField('name', ScalarT::string()),
                    new ArrayShapeField('email', ScalarT::string()),
                ]), $location)]),

                // JSON:API: the resource + its to* member shapes.
                self::CONTROLLER.'showJsonApiArticle' => new ActionAnalysis(
                    returns: [new ReturnSite(new ClassT(self::JSONAPI_RESOURCE), $location)],
                ),
                self::JSONAPI_RESOURCE.'::toAttributes' => new ActionAnalysis(returns: [new ReturnSite(new ArrayShapeT([
                    new ArrayShapeField('title', ScalarT::string()),
                    new ArrayShapeField('body', ScalarT::string()),
                ]), $location)]),
                // No `toRelationships` script: closure-valued relationships analyse as CallableT and the
                // shared builder omits the member (see JsonApiDocument), so nothing reads it.
                self::JSONAPI_RESOURCE.'::toLinks' => new ActionAnalysis(returns: [new ReturnSite(new ArrayShapeT([
                    new ArrayShapeField('self', ScalarT::string()),
                ]), $location)]),

                // Eloquent model response, and a noContent() 204.
                self::CONTROLLER.'showWidget' => new ActionAnalysis(
                    returns: [new ReturnSite($jsonResponse(new ClassT(self::WIDGET_MODEL), 200), $location)],
                ),
                self::CONTROLLER.'destroyWidget' => new ActionAnalysis(
                    returns: [new ReturnSite($jsonResponse(new VoidT, 204), $location)],
                ),

                // Distinct return paths carrying distinct statuses (200 + 202).
                self::CONTROLLER.'storeReport' => new ActionAnalysis(returns: [
                    new ReturnSite($jsonResponse(new ArrayShapeT([new ArrayShapeField('id', ScalarT::int())]), 200), $location),
                    new ReturnSite($jsonResponse(new ArrayShapeT([new ArrayShapeField('status', new LiteralT('accepted'))]), 202), $location),
                ]),

                // A union of a bare spatie Data (its own inferred success status + envelope) and a
                // noContent() 204 — the merge documents both statuses from one action.
                self::CONTROLLER.'storeOrCancel' => new ActionAnalysis(returns: [
                    new ReturnSite(new ClassT(self::ARTICLE_DATA), $location),
                    new ReturnSite($jsonResponse(new VoidT, 204), $location),
                ]),

                // A polymorphic morph (Widget|Gadget) → discriminated oneOf keyed by the morph map.
                self::CONTROLLER.'showAttachment' => new ActionAnalysis(
                    returns: [new ReturnSite(UnionT::of([new ClassT(self::WIDGET_MODEL), new ClassT(self::GADGET_MODEL)]), $location)],
                ),

                // A success 200 plus a renderable exception the inferred-handler tier documents as 402.
                self::CONTROLLER.'checkout' => new ActionAnalysis(
                    returns: [new ReturnSite($jsonResponse(new ArrayShapeT([new ArrayShapeField('ok', ScalarT::bool())]), 200), $location)],
                    throws: [new ThrownException(self::PAYMENT_EXCEPTION, 402, [], ThrowConfidence::Certain, ThrowDisposition::Signal)],
                ),
                ...$analysisOverrides,
            ],
            classes: [
                'Workbench\\App\\Data\\FormData' => $formData,
                // The webhook payload class, as the engine recovers it from promoted properties.
                'Workbench\\App\\Webhooks\\FormSubmitted' => new ClassMetadata('Workbench\\App\\Webhooks\\FormSubmitted', [
                    new PropertyMetadata('formId', ScalarT::int()),
                    new PropertyMetadata('submittedAt', ScalarT::string()),
                ]),
                'Workbench\\App\\Data\\WidgetData' => $widgetData,
                self::ARTICLE_DATA => new ClassMetadata(self::ARTICLE_DATA, [
                    new PropertyMetadata('id', ScalarT::int()),
                    new PropertyMetadata('title', ScalarT::string()),
                    new PropertyMetadata('body', ScalarT::string()),
                    new PropertyMetadata('secret', ScalarT::string()),
                    new PropertyMetadata('internal', ScalarT::int()),
                    new PropertyMetadata('subtitle', UnionT::of([ScalarT::string(), new ClassT('Spatie\\LaravelData\\Optional')])),
                    new PropertyMetadata('author', UnionT::of([new ClassT(self::AUTHOR_DATA), new NullT])),
                    // A free-form map with an `@example`, mirrored as the real engine hands it over:
                    // `mixed` is UnknownT, whose schema is the EMPTY schema, so `additionalProperties`
                    // is an empty array in the draft and `{}` in the artifact. That pair — the empty
                    // schema plus an authored example — is what killed an export, and no fixture in this
                    // repo had it when the example lint shipped.
                    new PropertyMetadata(
                        'metadata',
                        new MapT(ScalarT::string(), new UnknownT('mixed')),
                        'Whatever the publishing system stored alongside the article.',
                        '{"source": "syndication", "wordCount": 1200}',
                    ),
                ]),
                self::AUTHOR_DATA => new ClassMetadata(self::AUTHOR_DATA, [
                    new PropertyMetadata('name', ScalarT::string()),
                    new PropertyMetadata('email', ScalarT::string()),
                ]),
                self::WIDGET_MODEL => new ClassMetadata(self::WIDGET_MODEL, [
                    new PropertyMetadata('id', ScalarT::int()),
                    new PropertyMetadata('name', ScalarT::string()),
                    new PropertyMetadata('password', ScalarT::string()),
                    new PropertyMetadata('token', ScalarT::string()),
                    new PropertyMetadata('created_at', UnionT::of([ScalarT::string(), new NullT])),
                    new PropertyMetadata('is_active', ScalarT::string()),
                    new PropertyMetadata('status', ScalarT::string()),
                    new PropertyMetadata('meta', ScalarT::string()),
                ]),
                self::LEDGER_MODEL => new ClassMetadata(self::LEDGER_MODEL, [
                    new PropertyMetadata('id', ScalarT::int()),
                    new PropertyMetadata('reference', ScalarT::string(), 'The ledger\'s human reference.'),
                    new PropertyMetadata('opened_at', UnionT::of([ScalarT::string(), new NullT])),
                ]),
                self::GADGET_MODEL => new ClassMetadata(self::GADGET_MODEL, [
                    new PropertyMetadata('id', ScalarT::int()),
                    new PropertyMetadata('label', ScalarT::string()),
                ]),
                ...$classOverrides,
            ],
            callables: [
                // The renderable exception's render() as the engine recovers it — the inferred-handler
                // tier analyses PaymentRequiredException::render and documents its 402 body.
                self::PAYMENT_EXCEPTION.'::render' => new ActionAnalysis(
                    // The real render() writes all three members as literals, so each is documented as a
                    // `const` plus a media-type example.
                    returns: [new ReturnSite($jsonResponse(new ArrayShapeT([
                        new ArrayShapeField('type', new LiteralT('https://example.test/problems/payment-required')),
                        new ArrayShapeField('title', new LiteralT('Payment Required')),
                        new ArrayShapeField('status', new LiteralT(402)),
                    ]), 402), $location)],
                ),
                ...$callables,
            ],
        );
    }

    private const CONTROLLER = 'Workbench\\App\\Http\\Controllers\\IntegrationsController::';

    private const ARTICLE_DATA = 'Docuccino\\Laravel\\Tests\\Fixtures\\SpatieData\\ArticleData';

    private const AUTHOR_DATA = 'Docuccino\\Laravel\\Tests\\Fixtures\\SpatieData\\AuthorData';

    private const ARTICLE_RESOURCE = 'Docuccino\\Laravel\\Tests\\Fixtures\\ApiResources\\ArticleResource';

    private const AUTHOR_RESOURCE = 'Docuccino\\Laravel\\Tests\\Fixtures\\ApiResources\\AuthorResource';

    private const JSONAPI_RESOURCE = 'Docuccino\\Laravel\\Tests\\Fixtures\\ApiResources\\ArticleJsonApiResource';

    private const WIDGET_MODEL = 'Docuccino\\Laravel\\Tests\\Fixtures\\Eloquent\\Widget';

    private const GADGET_MODEL = 'Docuccino\\Laravel\\Tests\\Fixtures\\Eloquent\\Gadget';

    private const LEDGER_MODEL = 'Workbench\\App\\Models\\Ledger';

    private const PAYMENT_EXCEPTION = 'Workbench\\App\\Exceptions\\PaymentRequiredException';
}
