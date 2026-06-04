<?php

namespace App\Models;

use App\Enums\CurrencyType;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $currency_code
 * @property int $numeric_code
 * @property string $name
 * @property string $symbol
 * @property CurrencyType $type
 * @property int $scale
 * @property int $display_scale
 * @property bool $is_active
 */
class Currency extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'currency_code',
        'numeric_code',
        'name',
        'symbol',
        'type',
        'scale',
        'display_scale',
        'is_active',
    ];

    public $timestamps = false;

    protected $casts = [
        'type' => CurrencyType::class,
    ];

    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', 1);
    }

    #[Scope]
    protected function crypto(Builder $query): void
    {
        $query->where('type', 'crypto');
    }

    #[Scope]
    protected function fiat(Builder $query): void
    {
        $query->where('type', 'fiat');
    }

    public function isFiat(): bool
    {
        return $this->type === CurrencyType::FIAT;
    }

    public function isCrypto(): bool
    {
        return $this->type === CurrencyType::CRYPTO;
    }
}
