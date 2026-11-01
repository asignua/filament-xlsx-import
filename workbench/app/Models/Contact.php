<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * No import rules at all: the zero-config case. Mass assignment is off, as in a repository-style app.
 */
class Contact extends Model
{
    protected $guarded = ['*'];
}
