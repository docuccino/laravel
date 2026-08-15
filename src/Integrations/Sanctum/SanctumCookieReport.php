<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Integrations\Sanctum;

use Docuccino\Core\Extensions\Context\DocumentContext;
use Docuccino\Core\Extensions\Contracts\DocumentTransformer;
use Docuccino\Core\Extensions\Document\UirDocumentDraft;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Says once, per document, that the stateful scheme's cookie name came from the build environment.
 *
 * It is a document transformer and not part of {@see SanctumSecurityExtension} because the fact is a
 * document fact: one cookie name reaches every stateful operation, so raising it per route gives a
 * 200-route app 200 identical warnings and makes `--fail-on=warning` a thing teams switch off. Reading
 * it off the FINISHED document is also what keeps a warm build's diagnostics equal to a cold one's —
 * no route has to re-run for the report to be there. Whether to report at all is
 * {@see SanctumCookie::report()}'s decision.
 *
 * Never mutates the document.
 */
final class SanctumCookieReport implements DocumentTransformer
{
    public function __construct(private readonly ?ConfigRepository $config = null) {}

    public function transform(UirDocumentDraft $document, DocumentContext $context): void
    {
        $pinned = $context->config->integration('sanctum')['cookie'] ?? null;
        $sessionCookie = $this->config?->get('session.cookie');

        if (! self::publishesStatefulScheme($document->toArray(), SanctumCookie::resolve($pinned, $sessionCookie))) {
            return;
        }

        $report = SanctumCookie::report($pinned, $sessionCookie, $this->config?->get('app.name'));
        if ($report !== null) {
            $context->report($report);
        }
    }

    /**
     * Whether the build actually published the stateful scheme this cookie name belongs to. Matched on
     * the definition rather than on its key, because the key a scheme is published under is settled at
     * assembly, from the whole set of definitions contesting it.
     *
     * @param  array<string, mixed>  $document
     */
    private static function publishesStatefulScheme(array $document, string $cookie): bool
    {
        $components = $document['components'] ?? null;
        $schemes = is_array($components) ? ($components['securitySchemes'] ?? null) : null;
        if (! is_array($schemes)) {
            return false;
        }

        return in_array(SanctumScheme::stateful($cookie), $schemes, true);
    }
}
