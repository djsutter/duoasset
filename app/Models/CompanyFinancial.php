<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

/**
 * Persisted structured financial statement metrics.
 *
 * @property int $id
 * @property string $symbol
 * @property string $statement_type
 * @property \Illuminate\Support\Carbon $fiscal_date
 * @property string $period
 * @property int|null $fiscal_year
 * @property string|null $revenue
 * @property string|null $net_income
 * @property string|null $eps
 * @property string|null $stockholders_equity
 * @property string|null $free_cash_flow
 * @property string|null $reported_currency
 * @property array|null $raw_metrics
 * @property \Illuminate\Support\Carbon|null $retrieved_at
 */
class CompanyFinancial extends Model
{
    public const TYPE_INCOME = 'income';

    public const TYPE_BALANCE_SHEET = 'balance_sheet';

    public const TYPE_CASH_FLOW = 'cash_flow';

    protected $fillable = [
        'symbol',
        'statement_type',
        'fiscal_date',
        'period',
        'fiscal_year',
        'revenue',
        'net_income',
        'eps',
        'stockholders_equity',
        'free_cash_flow',
        'reported_currency',
        'raw_metrics',
        'retrieved_at',
    ];

    protected $casts = [
        'fiscal_year' => 'integer',
        'revenue' => 'decimal:2',
        'net_income' => 'decimal:2',
        'eps' => 'decimal:4',
        'stockholders_equity' => 'decimal:2',
        'free_cash_flow' => 'decimal:2',
        'raw_metrics' => 'array',
        'retrieved_at' => 'datetime',
    ];

    /**
     * Canonical Y-m-d date string representation for driver-agnostic consistency (SQLite & MySQL).
     */
    protected function fiscalDate(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $value !== null ? Carbon::parse($value) : null,
            set: fn ($value) => $value !== null ? Carbon::parse($value)->toDateString() : null,
        );
    }
}
