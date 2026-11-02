<?php

declare(strict_types=1);

namespace Asignua\FilamentXlsxImport\Tests\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * A login model whose $fillable (as in the Laravel skeleton) names the password.
 */
class FillableAccount extends Model
{
    protected $table = 'users';

    protected $fillable = ['name', 'email', 'password', 'is_active'];
}
