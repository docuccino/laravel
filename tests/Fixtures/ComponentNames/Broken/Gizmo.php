<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\ComponentNames\Broken;

/**
 * The class the analyser gives nothing back for — a dynamic bag, a class behind a __get, an engine
 * that timed out. It degrades to a bare object, and that degradation is its own business: it must not
 * reach the working Gizmo beside it.
 */
final class Gizmo {}
