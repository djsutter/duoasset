<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DuoAsset — Setup Scoring Digest</title>
</head>
<body style="margin:0;padding:0;background-color:#f4f4f5;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#18181b;">
    @php
        $counts = $digest['summary_counts'] ?? [];
        $hasWatchlist = ! empty($digest['has_watchlist']);
        $improvements = $digest['watchlist_improvements'] ?? [];
        $drops = $digest['watchlist_drops'] ?? [];
        $newlyScored = $digest['watchlist_newly_scored'] ?? [];
        $unchangedWatched = $digest['watchlist_unchanged'] ?? [];
        $otherNotices = $digest['other_notices'] ?? [];
    @endphp
    <div style="max-width:760px;margin:24px auto;background:#ffffff;border:1px solid #e4e4e7;border-radius:8px;overflow:hidden;">
        <div style="background:#18181b;color:#ffffff;padding:20px 24px;">
            <h1 style="margin:0;font-size:20px;font-weight:700;">DuoAsset — Setup Scoring Digest</h1>
            <p style="margin:6px 0 0;font-size:13px;color:#d4d4d8;">Scanner completed: {{ $completedAt }}</p>
        </div>

        <div style="padding:20px 24px;">
            <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:14px 16px;margin-bottom:24px;">
                <p style="margin:0 0 8px;font-size:14px;font-weight:600;color:#0f172a;">Summary</p>
                <ul style="margin:0;padding-left:18px;font-size:13px;line-height:1.6;color:#334155;">
                    <li>Watchlist improvements: {{ (int) ($counts['watchlist_improvements'] ?? 0) }}</li>
                    <li>Watchlist drops: {{ (int) ($counts['watchlist_drops'] ?? 0) }}</li>
                    <li>Newly scored watchlist stocks: {{ (int) ($counts['watchlist_newly_scored'] ?? 0) }}</li>
                    <li>Other significant setup notices: {{ (int) ($counts['other_notices_displayed'] ?? 0) }}{{ ((int) ($counts['other_notices_total'] ?? 0) > (int) ($counts['other_notices_displayed'] ?? 0)) ? ' (Showing top '.(int) ($counts['other_notices_displayed'] ?? 0).' of '.(int) ($counts['other_notices_total'] ?? 0).')' : '' }}</li>
                </ul>
            </div>

            @if ($hasWatchlist)
                <h2 style="font-size:16px;font-weight:700;margin:24px 0 10px;color:#15803d;">🟢 Your Watchlist — Setup Score Improvements</h2>
                @if (count($improvements) > 0)
                    <table style="width:100%;border-collapse:collapse;font-size:13px;margin-bottom:20px;">
                        <thead>
                            <tr style="background:#f4f4f5;text-align:left;border-bottom:1px solid #d4d4d8;">
                                <th style="padding:8px;">Symbol &amp; Score Change</th>
                                <th style="padding:8px;">Company</th>
                                <th style="padding:8px;">Setup Signal</th>
                                <th style="padding:8px;">Dashboard</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($improvements as $item)
                                <tr style="border-bottom:1px solid #e4e4e7;">
                                    <td style="padding:8px;font-weight:700;color:#15803d;">
                                        🟢 {{ $item['symbol'] }} — {{ $item['previous_setup_score'] }} → {{ $item['setup_score'] }} (+{{ $item['score_delta'] }})
                                    </td>
                                    <td style="padding:8px;">{{ $item['company_name'] }}</td>
                                    <td style="padding:8px;">{{ $item['setup_label'] }}</td>
                                    <td style="padding:8px;">
                                        <a href="{{ $item['dashboard_url'] }}" style="color:#2563eb;text-decoration:none;">View {{ $item['symbol'] }}</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @else
                    <p style="font-size:13px;color:#71717a;margin:0 0 20px;">No watchlist setup score improvements in this run.</p>
                @endif

                <h2 style="font-size:16px;font-weight:700;margin:24px 0 10px;color:#b91c1c;">🔴 Your Watchlist — Setup Score Drops</h2>
                @if (count($drops) > 0)
                    <table style="width:100%;border-collapse:collapse;font-size:13px;margin-bottom:20px;">
                        <thead>
                            <tr style="background:#f4f4f5;text-align:left;border-bottom:1px solid #d4d4d8;">
                                <th style="padding:8px;">Symbol &amp; Score Change</th>
                                <th style="padding:8px;">Company</th>
                                <th style="padding:8px;">Setup Signal</th>
                                <th style="padding:8px;">Dashboard</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($drops as $item)
                                <tr style="border-bottom:1px solid #e4e4e7;">
                                    <td style="padding:8px;font-weight:700;color:#b91c1c;">
                                        🔴 {{ $item['symbol'] }} — {{ $item['previous_setup_score'] }} → {{ $item['setup_score'] }} ({{ $item['score_delta'] }})
                                    </td>
                                    <td style="padding:8px;">{{ $item['company_name'] }}</td>
                                    <td style="padding:8px;">{{ $item['setup_label'] }}</td>
                                    <td style="padding:8px;">
                                        <a href="{{ $item['dashboard_url'] }}" style="color:#2563eb;text-decoration:none;">View {{ $item['symbol'] }}</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @else
                    <p style="font-size:13px;color:#71717a;margin:0 0 20px;">No watchlist setup score drops in this run.</p>
                @endif

                @if (count($newlyScored) > 0)
                    <h2 style="font-size:16px;font-weight:700;margin:24px 0 10px;color:#1d4ed8;">🆕 Newly Scored Watchlist Securities</h2>
                    <table style="width:100%;border-collapse:collapse;font-size:13px;margin-bottom:20px;">
                        <thead>
                            <tr style="background:#f4f4f5;text-align:left;border-bottom:1px solid #d4d4d8;">
                                <th style="padding:8px;">Symbol &amp; Initial Score</th>
                                <th style="padding:8px;">Company</th>
                                <th style="padding:8px;">Setup Signal</th>
                                <th style="padding:8px;">Dashboard</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($newlyScored as $item)
                                <tr style="border-bottom:1px solid #e4e4e7;">
                                    <td style="padding:8px;font-weight:700;color:#1d4ed8;">
                                        🆕 {{ $item['symbol'] }} — Score {{ $item['setup_score'] }} (Initial Score)
                                    </td>
                                    <td style="padding:8px;">{{ $item['company_name'] }}</td>
                                    <td style="padding:8px;">{{ $item['setup_label'] }}</td>
                                    <td style="padding:8px;">
                                        <a href="{{ $item['dashboard_url'] }}" style="color:#2563eb;text-decoration:none;">View {{ $item['symbol'] }}</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif

                @if (count($unchangedWatched) > 0)
                    <h2 style="font-size:16px;font-weight:700;margin:24px 0 10px;color:#334155;">⚪ Your Watchlist — Active Setups (Score Unchanged)</h2>
                    <table style="width:100%;border-collapse:collapse;font-size:13px;margin-bottom:20px;">
                        <thead>
                            <tr style="background:#f4f4f5;text-align:left;border-bottom:1px solid #d4d4d8;">
                                <th style="padding:8px;">Symbol &amp; Score</th>
                                <th style="padding:8px;">Company</th>
                                <th style="padding:8px;">Setup Signal</th>
                                <th style="padding:8px;">Dashboard</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($unchangedWatched as $item)
                                <tr style="border-bottom:1px solid #e4e4e7;">
                                    <td style="padding:8px;font-weight:700;color:#334155;">
                                        ⚪ {{ $item['symbol'] }} — Score {{ $item['setup_score'] }} (Unchanged)
                                    </td>
                                    <td style="padding:8px;">{{ $item['company_name'] }}</td>
                                    <td style="padding:8px;">{{ $item['setup_label'] }}</td>
                                    <td style="padding:8px;">
                                        <a href="{{ $item['dashboard_url'] }}" style="color:#2563eb;text-decoration:none;">View {{ $item['symbol'] }}</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            @endif

            <h2 style="font-size:16px;font-weight:700;margin:24px 0 4px;color:#0f172a;">📊 Other Setup Scoring Notices</h2>
            <p style="margin:0 0 10px;font-size:12px;color:#52525b;">
                Showing {{ count($otherNotices) }} of {{ (int) ($counts['other_notices_total'] ?? count($otherNotices)) }} significant market-wide setup notices
            </p>
            @if (count($otherNotices) > 0)
                <table style="width:100%;border-collapse:collapse;font-size:13px;margin-bottom:20px;">
                    <thead>
                        <tr style="background:#f4f4f5;text-align:left;border-bottom:1px solid #d4d4d8;">
                            <th style="padding:8px;">Symbol &amp; Activity</th>
                            <th style="padding:8px;">Company</th>
                            <th style="padding:8px;">Setup Signal</th>
                            <th style="padding:8px;">Dashboard</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($otherNotices as $item)
                            <tr style="border-bottom:1px solid #e4e4e7;">
                                <td style="padding:8px;font-weight:600;">
                                    @if ($item['is_newly_scored'])
                                        🆕 {{ $item['symbol'] }} — Score {{ $item['setup_score'] }} (New)
                                    @elseif (($item['score_delta'] ?? 0) > 0)
                                        🟢 {{ $item['symbol'] }} — {{ $item['previous_setup_score'] }} → {{ $item['setup_score'] }} (+{{ $item['score_delta'] }})
                                    @elseif (($item['score_delta'] ?? 0) < 0)
                                        🔴 {{ $item['symbol'] }} — {{ $item['previous_setup_score'] }} → {{ $item['setup_score'] }} ({{ $item['score_delta'] }})
                                    @else
                                        ⚪ {{ $item['symbol'] }} — Score {{ $item['setup_score'] }} (Unchanged)
                                    @endif
                                </td>
                                <td style="padding:8px;">{{ $item['company_name'] }}</td>
                                <td style="padding:8px;">{{ $item['setup_label'] }}</td>
                                <td style="padding:8px;">
                                    <a href="{{ $item['dashboard_url'] }}" style="color:#2563eb;text-decoration:none;">View {{ $item['symbol'] }}</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                <p style="font-size:13px;color:#71717a;margin:0 0 20px;">No additional significant setup notices outside your watchlist in this run.</p>
            @endif

            <div style="margin:24px 0 12px;text-align:center;">
                <a href="{{ $dashboardUrl }}" style="display:inline-block;background:#18181b;color:#ffffff;padding:10px 18px;border-radius:6px;text-decoration:none;font-size:13px;font-weight:600;">View Complete Setup Scoring Results in DuoAsset</a>
            </div>
        </div>

        <div style="background:#f4f4f5;border-top:1px solid #e4e4e7;padding:16px 24px;font-size:12px;color:#52525b;text-align:center;">
            <a href="{{ $managePreferencesUrl }}" style="color:#2563eb;text-decoration:underline;">Manage your email notifications</a>
            &nbsp;|&nbsp;
            <a href="{{ $unsubscribeCategoryUrl }}" style="color:#2563eb;text-decoration:underline;">Unsubscribe from this notification type</a>
            &nbsp;|&nbsp;
            <a href="{{ $unsubscribeBothUrl }}" style="color:#2563eb;text-decoration:underline;">Unsubscribe from both digests</a>
        </div>
    </div>
</body>
</html>
