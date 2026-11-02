<?php

declare(strict_types=1);

namespace Asignua\FilamentXlsxImport\Tests\Support;

use Illuminate\Database\Eloquent\Model;

class FillableContact extends Model
{
    protected $table = 'contacts';

    protected $fillable = ['name', 'email'];
}
