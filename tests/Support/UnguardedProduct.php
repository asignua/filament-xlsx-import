<?php

declare(strict_types=1);

namespace Asignua\FilamentXlsxImport\Tests\Support;

use Workbench\App\Models\Product;

/**
 * The workbench Product with mass assignment declared open — the zero-config reading of its columns.
 */
class UnguardedProduct extends Product
{
    protected $table = 'products';

    protected $guarded = [];

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany<OpenTag, $this>
     */
    public function tags(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(OpenTag::class, 'product_tag', 'product_id', 'tag_id');
    }
}
