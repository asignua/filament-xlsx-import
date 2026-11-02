<?php

declare(strict_types=1);

namespace Asignua\FilamentXlsxImport\Tests\Support;

use Illuminate\Database\Eloquent\Model;

class OpenTag extends Model
{
    protected $table = 'tags';

    protected $guarded = [];
}
