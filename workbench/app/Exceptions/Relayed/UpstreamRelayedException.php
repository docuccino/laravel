<?php

declare(strict_types=1);

namespace Workbench\App\Exceptions\Relayed;

/** Two levels below the framework, relaying whatever response it is handed. */
final class UpstreamRelayedException extends RelayedResponseException {}
