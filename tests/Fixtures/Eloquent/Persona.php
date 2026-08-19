<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\Eloquent;

use Docuccino\Attributes\Mock;
use Illuminate\Database\Eloquent\Model;

/**
 * A model whose columns are magic properties, so the class-level `#[Mock]` form is the only one that
 * can name one — including `created_at`, which the framework synthesises rather than the docblock
 * declaring it. Only ever reflected.
 *
 * @property int $id
 * @property string $email
 */
#[Mock(faker: 'safeEmail', property: 'email')]
#[Mock(faker: 'dateTimeThisYear', property: 'created_at')]
#[Mock(faker: 'word', property: 'absent')]
final class Persona extends Model {}
