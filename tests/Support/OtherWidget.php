<?php

declare(strict_types=1);

namespace Asignua\FilamentXlsxImport\Tests\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Workbench\App\Models\Category;

/**
 * Lives on a connection that is not the default one.
 */
class OtherWidget extends Model
{
    protected $connection = 'other';

    protected $table = 'widgets';

    protected $guarded = [];

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
