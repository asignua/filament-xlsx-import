<?php

declare(strict_types=1);

namespace Asignua\FilamentXlsxImport\Tests\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent's default: no $fillable, no $guarded — i.e. totally guarded.
 */
class GuardedThing extends Model
{
    protected $table = 'contacts';
}
