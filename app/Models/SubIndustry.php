<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $industry_id
 * @property string $name
 * @property string $slug
 * @property int $sort_order
 */
class SubIndustry extends Model
{
    /** @use HasFactory<\Database\Factories\SubIndustryFactory> */
    use HasFactory;

    protected $table = 'sub_industries';

    protected $fillable = [
        'industry_id',
        'name',
        'slug',
        'sort_order',
    ];

    public function industry(): BelongsTo
    {
        return $this->belongsTo(Industry::class);
    }
}
