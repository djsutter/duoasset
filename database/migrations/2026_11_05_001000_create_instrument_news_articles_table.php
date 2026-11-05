<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('instrument_news_articles')) {
            Schema::create('instrument_news_articles', function (Blueprint $table) {
                $table->id();
                $table->string('symbol', 32);
                $table->string('article_uid', 64);
                $table->string('headline', 512);
                $table->text('summary')->nullable();
                $table->string('publisher', 191)->nullable();
                $table->string('site', 191)->nullable();
                $table->string('canonical_url', 512)->nullable()->index();
                $table->text('url')->nullable();
                $table->string('source_provider', 64)->default('fmp');
                $table->timestamp('published_at');
                $table->timestamp('event_at')->nullable();
                $table->timestamp('retrieved_at')->nullable();
                $table->unsignedSmallInteger('relevance_score')->default(0);
                $table->string('primary_category_key', 64)->nullable();
                $table->string('primary_category_label', 128)->nullable();
                $table->json('categories')->nullable();
                $table->json('symbols')->nullable();
                $table->json('relevance_metadata')->nullable();
                $table->json('ai_enrichment')->nullable();
                $table->timestamps();

                $table->unique(['symbol', 'article_uid'], 'instrument_news_symbol_article_uniq');
                $table->index(['symbol', 'published_at'], 'instrument_news_symbol_pub_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('instrument_news_articles') && \Illuminate\Support\Facades\DB::table('instrument_news_articles')->exists()) {
            if (! env('ALLOW_DROP_MARKET_DATA', false) && ! config('market_data.allow_drop_tables', false)) {
                throw new \RuntimeException(
                    'Migration rollback halted: permanent market data table [instrument_news_articles] contains accumulated historical data. Set ALLOW_DROP_MARKET_DATA=true to bypass.'
                );
            }
        }

        Schema::dropIfExists('instrument_news_articles');
    }
};
