<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Support;

use Docuccino\Core\Extensions\Context\MappedResponse;
use Docuccino\Core\Inference\ThrowDisposition;
use Docuccino\Core\Inference\ThrownException;

/**
 * Whether a throw the analysis files as an assertion ({@see ThrowDisposition::Assertion}) is sent as a
 * response at all. It is not where it renders as the 500 an exception with no status falls back to: no request
 * a correctly wired application receives gets it. Where the application's exception handling renders it at a
 * status of its own, the application has given it one, and the response stands like any other.
 */
final class AssertedThrows
{
    public static function unsent(ThrownException $throw, MappedResponse $mapped): bool
    {
        return $throw->disposition === ThrowDisposition::Assertion && $mapped->draft->status === '500';
    }
}
