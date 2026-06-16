<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $sector_id
 * @property string $name
 * @property string $slug
 * @property int $sort_order
 */
class Industry extends Model
{
    /** @use HasFactory<\Database\Factories\IndustryFactory> */
    use HasFactory;

    protected $fillable = [
        'sector_id',
        'name',
        'slug',
        'sort_order',
    ];

    public function sector(): BelongsTo
    {
        return $this->belongsTo(Sector::class);
    }

    public function subIndustries(): HasMany
    {
        return $this->hasMany(SubIndustry::class);
    }
}
