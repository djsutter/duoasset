@php
    $counts = $digest['summary_counts'] ?? [];
    $hasWatchlist = ! empty($digest['has_watchlist']);
    $improvements = $digest['watchlist_improvements'] ?? [];
    $drops = $digest['watchlist_drops'] ?? [];
    $newlyScored = $digest['watchlist_newly_scored'] ?? [];
    $unchangedWatched = $digest['watchlist_unchanged'] ?? [];
    $otherNotices = $digest['other_notices'] ?? [];
@endphp
DuoAsset — Setup Scoring Digest
Scanner completed: {{ $completedAt }}

Summary:
- Watchlist improvements: {{ (int) ($counts['watchlist_improvements'] ?? 0) }}
- Watchlist drops: {{ (int) ($counts['watchlist_drops'] ?? 0) }}
- Newly scored watchlist stocks: {{ (int) ($counts['watchlist_newly_scored'] ?? 0) }}
- Other significant setup notices: {{ (int) ($counts['other_notices_displayed'] ?? 0) }} (of {{ (int) ($counts['other_notices_total'] ?? 0) }})

@if ($hasWatchlist)
🟢 Your Watchlist — Setup Score Improvements
------------------------------------------------------------
@if (count($improvements) > 0)
@foreach ($improvements as $item)
🟢 {{ $item['symbol'] }} — {{ $item['previous_setup_score'] }} → {{ $item['setup_score'] }} (+{{ $item['score_delta'] }}) | {{ $item['company_name'] }} | {{ $item['setup_label'] }} | {{ $item['dashboard_url'] }}
@endforeach
@else
No watchlist setup score improvements in this run.
@endif

🔴 Your Watchlist — Setup Score Drops
------------------------------------------------------------
@if (count($drops) > 0)
@foreach ($drops as $item)
🔴 {{ $item['symbol'] }} — {{ $item['previous_setup_score'] }} → {{ $item['setup_score'] }} ({{ $item['score_delta'] }}) | {{ $item['company_name'] }} | {{ $item['setup_label'] }} | {{ $item['dashboard_url'] }}
@endforeach
@else
No watchlist setup score drops in this run.
@endif

@if (count($newlyScored) > 0)
🆕 Newly Scored Watchlist Securities
------------------------------------------------------------
@foreach ($newlyScored as $item)
🆕 {{ $item['symbol'] }} — Score {{ $item['setup_score'] }} (Initial Score) | {{ $item['company_name'] }} | {{ $item['setup_label'] }} | {{ $item['dashboard_url'] }}
@endforeach

@endif
@if (count($unchangedWatched) > 0)
⚪ Your Watchlist — Active Setups (Score Unchanged)
------------------------------------------------------------
@foreach ($unchangedWatched as $item)
⚪ {{ $item['symbol'] }} — Score {{ $item['setup_score'] }} (Unchanged) | {{ $item['company_name'] }} | {{ $item['setup_label'] }} | {{ $item['dashboard_url'] }}
@endforeach

@endif
@endif
📊 Other Setup Scoring Notices (Showing {{ count($otherNotices) }} of {{ (int) ($counts['other_notices_total'] ?? count($otherNotices)) }})
------------------------------------------------------------
@if (count($otherNotices) > 0)
@foreach ($otherNotices as $item)
@if ($item['is_newly_scored'])
🆕 {{ $item['symbol'] }} — Score {{ $item['setup_score'] }} (New) | {{ $item['company_name'] }} | {{ $item['setup_label'] }} | {{ $item['dashboard_url'] }}
@elseif (($item['score_delta'] ?? 0) > 0)
🟢 {{ $item['symbol'] }} — {{ $item['previous_setup_score'] }} → {{ $item['setup_score'] }} (+{{ $item['score_delta'] }}) | {{ $item['company_name'] }} | {{ $item['setup_label'] }} | {{ $item['dashboard_url'] }}
@elseif (($item['score_delta'] ?? 0) < 0)
🔴 {{ $item['symbol'] }} — {{ $item['previous_setup_score'] }} → {{ $item['setup_score'] }} ({{ $item['score_delta'] }}) | {{ $item['company_name'] }} | {{ $item['setup_label'] }} | {{ $item['dashboard_url'] }}
@else
⚪ {{ $item['symbol'] }} — Score {{ $item['setup_score'] }} (Unchanged) | {{ $item['company_name'] }} | {{ $item['setup_label'] }} | {{ $item['dashboard_url'] }}
@endif
@endforeach
@else
No additional significant setup notices outside your watchlist in this run.
@endif

View complete setup scoring results in DuoAsset: {{ $dashboardUrl }}

---
Manage your email notifications: {{ $managePreferencesUrl }}
Unsubscribe from this notification type: {{ $unsubscribeCategoryUrl }}
Unsubscribe from both digests: {{ $unsubscribeBothUrl }}
