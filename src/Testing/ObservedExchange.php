<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Testing;

use Docuccino\Core\Contract\CheckResult;
use Docuccino\Core\Contract\ContractOperation;
use Docuccino\Core\Contract\Exchange;
use Illuminate\Http\Request;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * One request/response pair, matched to the operation that documents it — the whole input a
 * {@see Contracts\ContractObserver} gets.
 *
 * It carries the Laravel objects AND the neutral {@see Exchange} they were reduced to, because an
 * observer may want either: a recorder wants the real response, a reporter wants the values that were
 * actually checked.
 */
final readonly class ObservedExchange
{
    /**
     * @param  TestResponse<Response>  $response
     * @param  string|null  $recordAs  what the test called this scenario, when it said — the one thing
     *                                 here the assertion did not work out for itself
     */
    public function __construct(
        public ContractOperation $operation,
        public Exchange $exchange,
        public Request $request,
        public TestResponse $response,
        public CheckResult $result,
        public ?string $recordAs = null,
    ) {}

    /** The stable operation id — null only when the artifact carries no identities. */
    public function operationId(): ?string
    {
        return $this->operation->id;
    }

    public function method(): string
    {
        return $this->operation->method;
    }

    /** The template (`/api/invoices/{invoice}`), not the concrete path the request used. */
    public function pathTemplate(): string
    {
        return $this->operation->path;
    }

    public function status(): int
    {
        return $this->exchange->status;
    }

    public function body(): string
    {
        return $this->exchange->responseBody;
    }
}
