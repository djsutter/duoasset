<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Persisted normalized news article associated with an instrument symbol.
 *
 * @property int $id
 * @property string $symbol
 * @property string $article_uid
 * @property string $headline
 * @property ?string $summary
 * @property ?string $publisher
 * @property ?string $site
 * @property ?string $canonical_url
 * @property ?string $url
 * @property string $source_provider
 * @property \Illuminate\Support\Carbon $published_at
 * @property ?\Illuminate\Support\Carbon $event_at
 * @property ?\Illuminate\Support\Carbon $retrieved_at
 * @property int $relevance_score
 * @property ?string $primary_category_key
 * @property ?string $primary_category_label
 * @property ?array $categories
 * @property ?array $symbols
 * @property ?array $relevance_metadata
 * @property ?array $ai_enrichment
 */
class InstrumentNewsArticle extends Model
{
    protected $fillable = [
        'symbol',
        'article_uid',
        'headline',
        'summary',
        'publisher',
        'site',
        'canonical_url',
        'url',
        'source_provider',
        'published_at',
        'event_at',
        'retrieved_at',
        'relevance_score',
        'primary_category_key',
        'primary_category_label',
        'categories',
        'symbols',
        'relevance_metadata',
        'ai_enrichment',
    ];

    protected $casts = [
        'published_at' => 'datetime',
        'event_at' => 'datetime',
        'retrieved_at' => 'datetime',
        'relevance_score' => 'integer',
        'categories' => 'array',
        'symbols' => 'array',
        'relevance_metadata' => 'array',
        'ai_enrichment' => 'array',
    ];
}
