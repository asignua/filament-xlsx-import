<?php

declare(strict_types=1);

namespace Asignua\FilamentXlsxImport\Tests\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An open model whose foreign key is a STRING column that does not look like one (boss_ref), a ULID-style key, and JSON kept as text.
 */
class RelatedContact extends Model
{
    protected $table = 'related_contacts';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }

    /**
     * @return BelongsTo<RelatedContact, $this>
     */
    public function boss(): BelongsTo
    {
        return $this->belongsTo(self::class, 'boss_ref');
    }
}
