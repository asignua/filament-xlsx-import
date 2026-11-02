<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * No import rules at all: the zero-config case (`$guarded = []`, so every column that is not a key/secret is offered).
 */
class Contact extends Model
{
    protected $guarded = [];
}
