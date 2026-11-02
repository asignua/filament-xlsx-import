<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Employee extends Model
{
    protected $guarded = [];

    /**
     * The login account of this employee: a hasOne into the app's user model.
     *
     * @return HasOne<User, $this>
     */
    public function account(): HasOne
    {
        return $this->hasOne(User::class, 'employee_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }
}
