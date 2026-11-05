<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DuoAsset — EPS Surprise Digest</title>
</head>
<body style="margin:0;padding:0;background-color:#f4f4f5;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#18181b;">
    @php
        $counts = $digest['summary_counts'] ?? [];
        $hasWatchlist = ! empty($digest['has_watchlist']);
        $watchlistSurprises = $digest['watchlist_surprises'] ?? [];
        $watchlistMisses = $digest['watchlist_misses'] ?? [];
        $marketSurprises = $digest['market_surprises'] ?? [];
        $marketMisses = $digest['market_misses'] ?? [];
        $topScores = $digest['top_scores'] ?? [];
    @endphp
    <div style="max-width:760px;margin:24px auto;background:#ffffff;border:1px solid #e4e4e7;border-radius:8px;overflow:hidden;">
        <div style="background:#18181b;color:#ffffff;padding:20px 24px;">
            <h1 style="margin:0;font-size:20px;font-weight:700;">DuoAsset — EPS Surprise Digest</h1>
            <p style="margin:6px 0 0;font-size:13px;color:#d4d4d8;">Scanner completed: {{ $completedAt }}</p>
        </div>

        <div style="padding:20px 24px;">
            <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:14px 16px;margin-bottom:24px;">
                <p style="margin:0 0 8px;font-size:14px;font-weight:600;color:#0f172a;">Summary</p>
                <ul style="margin:0;padding-left:18px;font-size:13px;line-height:1.6;color:#334155;">
                    @if ($hasWatchlist)
                        <li>Watchlist surprises: {{ (int) ($counts['watchlist_surprises'] ?? 0) }}</li>
                        <li>Watchlist misses: {{ (int) ($counts['watchlist_misses'] ?? 0) }}</li>
                    @endif
                    <li>Market surprises: {{ (int) ($counts['market_surprises_total'] ?? 0) }} (Top {{ (int) ($counts['market_surprises_displayed'] ?? 0) }} of {{ (int) ($counts['market_surprises_total'] ?? 0) }} positive surprises)</li>
                    <li>Market misses: {{ (int) ($counts['market_misses_total'] ?? 0) }} (Bottom {{ (int) ($counts['market_misses_displayed'] ?? 0) }} of {{ (int) ($counts['market_misses_total'] ?? 0) }} negative surprises)</li>
                    <li>EPS scoring opportunities: {{ (int) ($counts['top_scores_displayed'] ?? 0) }} displayed</li>
                </ul>
            </div>

            @if ($hasWatchlist)
                <h2 style="font-size:16px;font-weight:700;margin:24px 0 10px;color:#15803d;">⭐ Your Watchlist — EPS Surprises</h2>
                @if (count($watchlistSurprises) > 0)
                    <table style="width:100%;border-collapse:collapse;font-size:13px;margin-bottom:20px;">
                        <thead>
                            <tr style="background:#f4f4f5;text-align:left;border-bottom:1px solid #d4d4d8;">
                                <th style="padding:8px;">Symbol</th>
                                <th style="padding:8px;">Company</th>
                                <th style="padding:8px;text-align:right;">Reported EPS</th>
                                <th style="padding:8px;text-align:right;">Estimated EPS</th>
                                <th style="padding:8px;text-align:right;">Surprise %</th>
                                <th style="padding:8px;text-align:right;">EPS Score</th>
                                <th style="padding:8px;">Report Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($watchlistSurprises as $item)
                                <tr style="border-bottom:1px solid #e4e4e7;">
                                    <td style="padding:8px;font-weight:600;">
                                        <a href="{{ $item['dashboard_url'] }}" style="color:#2563eb;text-decoration:none;">{{ $item['symbol'] }}</a>
                                    </td>
                                    <td style="padding:8px;">{{ $item['company_name'] }}</td>
                                    <td style="padding:8px;text-align:right;">{{ number_format((float) $item['eps_actual'], 2) }}</td>
                                    <td style="padding:8px;text-align:right;">{{ number_format((float) $item['eps_estimated'], 2) }}</td>
                                    <td style="padding:8px;text-align:right;color:#15803d;font-weight:600;">+{{ number_format((float) $item['eps_surprise_percent'], 2) }}% (Beat)</td>
                                    <td style="padding:8px;text-align:right;">{{ $item['score'] !== null ? $item['score'] : '—' }}</td>
                                    <td style="padding:8px;">{{ $item['report_date'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @else
                    <p style="font-size:13px;color:#71717a;margin:0 0 20px;">No watchlist symbols reported qualifying positive EPS surprises in this scan.</p>
                @endif

                <h2 style="font-size:16px;font-weight:700;margin:24px 0 10px;color:#b91c1c;">⭐ Your Watchlist — EPS Misses</h2>
                @if (count($watchlistMisses) > 0)
                    <table style="width:100%;border-collapse:collapse;font-size:13px;margin-bottom:20px;">
                        <thead>
                            <tr style="background:#f4f4f5;text-align:left;border-bottom:1px solid #d4d4d8;">
                                <th style="padding:8px;">Symbol</th>
                                <th style="padding:8px;">Company</th>
                                <th style="padding:8px;text-align:right;">Reported EPS</th>
                                <th style="padding:8px;text-align:right;">Estimated EPS</th>
                                <th style="padding:8px;text-align:right;">Surprise %</th>
                                <th style="padding:8px;text-align:right;">EPS Score</th>
                                <th style="padding:8px;">Report Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($watchlistMisses as $item)
                                <tr style="border-bottom:1px solid #e4e4e7;">
                                    <td style="padding:8px;font-weight:600;">
                                        <a href="{{ $item['dashboard_url'] }}" style="color:#2563eb;text-decoration:none;">{{ $item['symbol'] }}</a>
                                    </td>
                                    <td style="padding:8px;">{{ $item['company_name'] }}</td>
                                    <td style="padding:8px;text-align:right;">{{ number_format((float) $item['eps_actual'], 2) }}</td>
                                    <td style="padding:8px;text-align:right;">{{ number_format((float) $item['eps_estimated'], 2) }}</td>
                                    <td style="padding:8px;text-align:right;color:#b91c1c;font-weight:600;">{{ number_format((float) $item['eps_surprise_percent'], 2) }}% (Miss)</td>
                                    <td style="padding:8px;text-align:right;">{{ $item['score'] !== null ? $item['score'] : '—' }}</td>
                                    <td style="padding:8px;">{{ $item['report_date'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @else
                    <p style="font-size:13px;color:#71717a;margin:0 0 20px;">No watchlist symbols reported qualifying EPS misses in this scan.</p>
                @endif
            @endif

            <h2 style="font-size:16px;font-weight:700;margin:24px 0 4px;color:#0f172a;">📈 Top 50 EPS Surprises — Market Wide</h2>
            <p style="margin:0 0 10px;font-size:12px;color:#52525b;">
                Top {{ count($marketSurprises) }} of {{ (int) ($counts['market_surprises_total'] ?? count($marketSurprises)) }} positive surprises
            </p>
            @if (count($marketSurprises) > 0)
                <table style="width:100%;border-collapse:collapse;font-size:13px;margin-bottom:20px;">
                    <thead>
                        <tr style="background:#f4f4f5;text-align:left;border-bottom:1px solid #d4d4d8;">
                            <th style="padding:8px;">Symbol</th>
                            <th style="padding:8px;">Company</th>
                            <th style="padding:8px;text-align:right;">Reported EPS</th>
                            <th style="padding:8px;text-align:right;">Estimated EPS</th>
                            <th style="padding:8px;text-align:right;">Surprise %</th>
                            <th style="padding:8px;text-align:right;">EPS Score</th>
                            <th style="padding:8px;">Report Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($marketSurprises as $item)
                            <tr style="border-bottom:1px solid #e4e4e7;">
                                <td style="padding:8px;font-weight:600;">
                                    <a href="{{ $item['dashboard_url'] }}" style="color:#2563eb;text-decoration:none;">{{ $item['symbol'] }}</a>
                                </td>
                                <td style="padding:8px;">{{ $item['company_name'] }}</td>
                                <td style="padding:8px;text-align:right;">{{ number_format((float) $item['eps_actual'], 2) }}</td>
                                <td style="padding:8px;text-align:right;">{{ number_format((float) $item['eps_estimated'], 2) }}</td>
                                <td style="padding:8px;text-align:right;color:#15803d;font-weight:600;">+{{ number_format((float) $item['eps_surprise_percent'], 2) }}% (Beat)</td>
                                <td style="padding:8px;text-align:right;">{{ $item['score'] !== null ? $item['score'] : '—' }}</td>
                                <td style="padding:8px;">{{ $item['report_date'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                <p style="font-size:13px;color:#71717a;margin:0 0 20px;">No additional market-wide positive EPS surprises in this scan.</p>
            @endif

            <h2 style="font-size:16px;font-weight:700;margin:24px 0 4px;color:#0f172a;">📉 Bottom 20 EPS Misses — Market Wide</h2>
            <p style="margin:0 0 10px;font-size:12px;color:#52525b;">
                Bottom {{ count($marketMisses) }} of {{ (int) ($counts['market_misses_total'] ?? count($marketMisses)) }} EPS misses
            </p>
            @if (count($marketMisses) > 0)
                <table style="width:100%;border-collapse:collapse;font-size:13px;margin-bottom:20px;">
                    <thead>
                        <tr style="background:#f4f4f5;text-align:left;border-bottom:1px solid #d4d4d8;">
                            <th style="padding:8px;">Symbol</th>
                            <th style="padding:8px;">Company</th>
                            <th style="padding:8px;text-align:right;">Reported EPS</th>
                            <th style="padding:8px;text-align:right;">Estimated EPS</th>
                            <th style="padding:8px;text-align:right;">Surprise %</th>
                            <th style="padding:8px;text-align:right;">EPS Score</th>
                            <th style="padding:8px;">Report Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($marketMisses as $item)
                            <tr style="border-bottom:1px solid #e4e4e7;">
                                <td style="padding:8px;font-weight:600;">
                                    <a href="{{ $item['dashboard_url'] }}" style="color:#2563eb;text-decoration:none;">{{ $item['symbol'] }}</a>
                                </td>
                                <td style="padding:8px;">{{ $item['company_name'] }}</td>
                                <td style="padding:8px;text-align:right;">{{ number_format((float) $item['eps_actual'], 2) }}</td>
                                <td style="padding:8px;text-align:right;">{{ number_format((float) $item['eps_estimated'], 2) }}</td>
                                <td style="padding:8px;text-align:right;color:#b91c1c;font-weight:600;">{{ number_format((float) $item['eps_surprise_percent'], 2) }}% (Miss)</td>
                                <td style="padding:8px;text-align:right;">{{ $item['score'] !== null ? $item['score'] : '—' }}</td>
                                <td style="padding:8px;">{{ $item['report_date'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                <p style="font-size:13px;color:#71717a;margin:0 0 20px;">No additional market-wide EPS misses in this scan.</p>
            @endif

            <h2 style="font-size:16px;font-weight:700;margin:24px 0 4px;color:#0f172a;">🏆 Top EPS Scoring Opportunities</h2>
            <p style="margin:0 0 10px;font-size:12px;color:#52525b;">
                Top {{ count($topScores) }} of {{ (int) ($counts['top_scores_total'] ?? count($topScores)) }} scored EPS opportunities
            </p>
            @if (count($topScores) > 0)
                <table style="width:100%;border-collapse:collapse;font-size:13px;margin-bottom:20px;">
                    <thead>
                        <tr style="background:#f4f4f5;text-align:left;border-bottom:1px solid #d4d4d8;">
                            <th style="padding:8px;">Rank</th>
                            <th style="padding:8px;">Symbol</th>
                            <th style="padding:8px;">Company</th>
                            <th style="padding:8px;text-align:right;">EPS Score</th>
                            <th style="padding:8px;text-align:right;">Surprise %</th>
                            <th style="padding:8px;">Report Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($topScores as $item)
                            <tr style="border-bottom:1px solid #e4e4e7;">
                                <td style="padding:8px;">#{{ $item['rank'] ?? $loop->iteration }}</td>
                                <td style="padding:8px;font-weight:600;">
                                    @if (! empty($item['is_watched']))
                                        <span title="In your watchlist">⭐</span>
                                    @endif
                                    <a href="{{ $item['dashboard_url'] }}" style="color:#2563eb;text-decoration:none;">{{ $item['symbol'] }}</a>
                                </td>
                                <td style="padding:8px;">{{ $item['company_name'] }}</td>
                                <td style="padding:8px;text-align:right;font-weight:700;">{{ $item['score'] }}</td>
                                <td style="padding:8px;text-align:right;">
                                    {{ ((float) $item['eps_surprise_percent'] >= 0 ? '+' : '').number_format((float) $item['eps_surprise_percent'], 2) }}%
                                </td>
                                <td style="padding:8px;">{{ $item['report_date'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                <p style="font-size:13px;color:#71717a;margin:0 0 20px;">No scored EPS opportunities in this scan.</p>
            @endif

            <div style="margin:24px 0 12px;text-align:center;">
                <a href="{{ $dashboardUrl }}" style="display:inline-block;background:#18181b;color:#ffffff;padding:10px 18px;border-radius:6px;text-decoration:none;font-size:13px;font-weight:600;">View Full EPS Results in DuoAsset</a>
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
