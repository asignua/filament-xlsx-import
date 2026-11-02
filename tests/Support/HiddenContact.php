<?php

declare(strict_types=1);

namespace Asignua\FilamentXlsxImport\Tests\Support;

use Illuminate\Database\Eloquent\Model;

class HiddenContact extends Model
{
    protected $table = 'contacts';

    protected $guarded = ['*'];

    protected $hidden = ['phone'];
}
