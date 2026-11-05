@php
    $counts = $digest['summary_counts'] ?? [];
    $hasWatchlist = ! empty($digest['has_watchlist']);
    $watchlistSurprises = $digest['watchlist_surprises'] ?? [];
    $watchlistMisses = $digest['watchlist_misses'] ?? [];
    $marketSurprises = $digest['market_surprises'] ?? [];
    $marketMisses = $digest['market_misses'] ?? [];
    $topScores = $digest['top_scores'] ?? [];
@endphp
DuoAsset — EPS Surprise Digest
Scanner completed: {{ $completedAt }}

Summary:
@if ($hasWatchlist)
- Watchlist surprises: {{ (int) ($counts['watchlist_surprises'] ?? 0) }}
- Watchlist misses: {{ (int) ($counts['watchlist_misses'] ?? 0) }}
@endif
- Market surprises: {{ (int) ($counts['market_surprises_total'] ?? 0) }} (Top {{ (int) ($counts['market_surprises_displayed'] ?? 0) }} of {{ (int) ($counts['market_surprises_total'] ?? 0) }} positive surprises)
- Market misses: {{ (int) ($counts['market_misses_total'] ?? 0) }} (Bottom {{ (int) ($counts['market_misses_displayed'] ?? 0) }} of {{ (int) ($counts['market_misses_total'] ?? 0) }} negative surprises)
- EPS scoring opportunities: {{ (int) ($counts['top_scores_displayed'] ?? 0) }} displayed

@if ($hasWatchlist)
⭐ Your Watchlist — EPS Surprises
------------------------------------------------------------
@if (count($watchlistSurprises) > 0)
@foreach ($watchlistSurprises as $item)
* {{ $item['symbol'] }} ({{ $item['company_name'] }}) | Reported: {{ number_format((float) $item['eps_actual'], 2) }} vs Est: {{ number_format((float) $item['eps_estimated'], 2) }} | Surprise: +{{ number_format((float) $item['eps_surprise_percent'], 2) }}% (Beat) | Score: {{ $item['score'] ?? '—' }} | Date: {{ $item['report_date'] }} | {{ $item['dashboard_url'] }}
@endforeach
@else
No watchlist symbols reported qualifying positive EPS surprises in this scan.
@endif

⭐ Your Watchlist — EPS Misses
------------------------------------------------------------
@if (count($watchlistMisses) > 0)
@foreach ($watchlistMisses as $item)
* {{ $item['symbol'] }} ({{ $item['company_name'] }}) | Reported: {{ number_format((float) $item['eps_actual'], 2) }} vs Est: {{ number_format((float) $item['eps_estimated'], 2) }} | Surprise: {{ number_format((float) $item['eps_surprise_percent'], 2) }}% (Miss) | Score: {{ $item['score'] ?? '—' }} | Date: {{ $item['report_date'] }} | {{ $item['dashboard_url'] }}
@endforeach
@else
No watchlist symbols reported qualifying EPS misses in this scan.
@endif

@endif
📈 Top 50 EPS Surprises — Market Wide (Top {{ count($marketSurprises) }} of {{ (int) ($counts['market_surprises_total'] ?? count($marketSurprises)) }} positive surprises)
------------------------------------------------------------
@if (count($marketSurprises) > 0)
@foreach ($marketSurprises as $item)
* {{ $item['symbol'] }} ({{ $item['company_name'] }}) | Reported: {{ number_format((float) $item['eps_actual'], 2) }} vs Est: {{ number_format((float) $item['eps_estimated'], 2) }} | Surprise: +{{ number_format((float) $item['eps_surprise_percent'], 2) }}% (Beat) | Score: {{ $item['score'] ?? '—' }} | Date: {{ $item['report_date'] }} | {{ $item['dashboard_url'] }}
@endforeach
@else
No additional market-wide positive EPS surprises in this scan.
@endif

📉 Bottom 20 EPS Misses — Market Wide (Bottom {{ count($marketMisses) }} of {{ (int) ($counts['market_misses_total'] ?? count($marketMisses)) }} EPS misses)
------------------------------------------------------------
@if (count($marketMisses) > 0)
@foreach ($marketMisses as $item)
* {{ $item['symbol'] }} ({{ $item['company_name'] }}) | Reported: {{ number_format((float) $item['eps_actual'], 2) }} vs Est: {{ number_format((float) $item['eps_estimated'], 2) }} | Surprise: {{ number_format((float) $item['eps_surprise_percent'], 2) }}% (Miss) | Score: {{ $item['score'] ?? '—' }} | Date: {{ $item['report_date'] }} | {{ $item['dashboard_url'] }}
@endforeach
@else
No additional market-wide EPS misses in this scan.
@endif

🏆 Top EPS Scoring Opportunities
------------------------------------------------------------
@if (count($topScores) > 0)
@foreach ($topScores as $item)
#{{ $item['rank'] ?? $loop->iteration }} {{ ! empty($item['is_watched']) ? '⭐ ' : '' }}{{ $item['symbol'] }} ({{ $item['company_name'] }}) | Score: {{ $item['score'] }} | Surprise: {{ ((float) $item['eps_surprise_percent'] >= 0 ? '+' : '').number_format((float) $item['eps_surprise_percent'], 2) }}% | Date: {{ $item['report_date'] }} | {{ $item['dashboard_url'] }}
@endforeach
@else
No scored EPS opportunities in this scan.
@endif

View full results in DuoAsset: {{ $dashboardUrl }}

---
Manage your email notifications: {{ $managePreferencesUrl }}
Unsubscribe from this notification type: {{ $unsubscribeCategoryUrl }}
Unsubscribe from both digests: {{ $unsubscribeBothUrl }}
