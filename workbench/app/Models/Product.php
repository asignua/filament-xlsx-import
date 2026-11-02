<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Asignua\FilamentXlsxImport\Contracts\DefinesImport;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Workbench\App\Enums\ProductStatus;

/**
 * Mass assignment is OFF (`$guarded = ['*']`): the import assigns attributes explicitly.
 * The model also predefines how a file maps into it.
 */
class Product extends Model implements DefinesImport
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'status' => ProductStatus::class,
            'is_featured' => 'boolean',
            'released_at' => 'date',
            'meta' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return BelongsToMany<Tag, $this>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }

    public static function importRules(): array
    {
        return [
            'update_by' => 'sku',
            'relations' => ['tags'],
            'except' => ['team_id', 'category_id'],
            'fields' => [
                'sku' => ['aliases' => ['article', 'артикул', 'код'], 'casts' => ['trim', 'upper'], 'rules' => ['required', 'max:40'], 'required' => true],
                'name' => ['aliases' => ['назва', 'product'], 'rules' => ['required']],
                'price' => ['aliases' => ['ціна'], 'casts' => ['trim', ['type' => 'decimal', 'arg' => ',']], 'rules' => ['numeric', 'min:0']],
                'stock' => ['aliases' => ['кількість', 'qty']],
                'is_featured' => ['aliases' => ['featured', 'рекомендований']],
                'released_at' => ['aliases' => ['дата виходу', 'release date'], 'casts' => ['trim', ['type' => 'date', 'arg' => 'd.m.Y']]],
                'category.name' => ['aliases' => ['category', 'категорія'], 'mode' => 'create'],
                'tags.name' => ['aliases' => ['tags', 'теги'], 'casts' => ['trim', ['type' => 'split', 'arg' => ';']], 'many' => 'add', 'mode' => 'create'],
            ],
        ];
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
