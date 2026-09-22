<?php

declare(strict_types=1);

namespace Workbench\App\Enums;

/**
 * A PURE enum — no backing value — which is the shape `json_encode` refuses. Idiomatic PHP to write in
 * an attribute argument, and the reason a workflow step's encode has to degrade rather than abort.
 */
enum FormChannel
{
    case Email;

    case Post;
}
