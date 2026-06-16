<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property int $sort_order
 */
class Sector extends Model
{
    /** @use HasFactory<\Database\Factories\SectorFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'sort_order',
    ];

    public function industries(): HasMany
    {
        return $this->hasMany(Industry::class);
    }
}
