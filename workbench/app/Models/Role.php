<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Stands in for spatie's Role: a privilege model nothing should be able to attach to from a spreadsheet.
 */
class Role extends Model
{
    protected $guarded = ['*'];
}
