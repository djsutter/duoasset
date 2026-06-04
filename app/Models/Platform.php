<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Platform extends Model
{
    /** @use HasFactory<\Database\Factories\PlatformFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'type',
        'notes',
        'is_active',
    ];

    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', 1);
    }

    public function wallets(): hasMany
    {
        return $this->hasMany(Wallet::class);
    }
}
