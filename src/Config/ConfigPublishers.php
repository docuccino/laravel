<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Config;

/**
 * The configuration files `docuccino:install` writes, in the order it writes them: the tool's own
 * `docuccino.yaml` at the project root, then the framework's `config/docuccino.php`.
 *
 * A list rather than two injected publishers so the command's timidity rule is written once and holds
 * for both — an existing file of either kind is a decision somebody made, and neither is replaced
 * without `--force`. The order is the order they are reported in, and it puts first the file an
 * author will actually edit.
 *
 * @internal
 */
final readonly class ConfigPublishers
{
    /**
     * @param  list<ConfigPublisher>  $publishers
     */
    public function __construct(private array $publishers) {}

    /**
     * @return list<ConfigPublisher>
     */
    public function all(): array
    {
        return $this->publishers;
    }
}
