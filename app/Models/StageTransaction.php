<?php

namespace App\Models;

use App\Enums\TransactionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StageTransaction extends Model
{
    public const STATUS_UNMATCHED = 'unmatched';

    public const STATUS_AUTOMATCHED = 'automatched';

    public const STATUS_MATCHED = 'matched';

    public const STATUS_EXTERNAL = 'external';

    public const STATUS_MANUAL = 'manual';

    public const STATUS_IGNORED = 'ignored';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_ERROR = 'error';

    protected $fillable = [
        'num',
        'match_tx_id',
        'tx_at',
        'tx_type',
        'description',
        'address',
        'status',
        'match_basis',
        'source',
    ];

    protected $casts = [
        'tx_at' => 'datetime',
        'tx_type' => TransactionType::class,
    ];

    public function entries(): HasMany
    {
        return $this->hasMany(StageEntry::class);
    }

    public function entriesInOrOut(): HasMany
    {
        return $this->hasMany(StageEntry::class)
            ->whereIn('atomic_type', ['in', 'out']);
    }

    public function matchedTransaction(): BelongsTo
    {
        return $this->belongsTo(StageTransaction::class, 'match_tx_id');
    }

    public function isMatched(): bool
    {
        return in_array($this->status, [
            self::STATUS_MATCHED,
            self::STATUS_MANUAL,
            self::STATUS_AUTOMATCHED,
            self::STATUS_CONFIRMED,
        ]);
    }
}
