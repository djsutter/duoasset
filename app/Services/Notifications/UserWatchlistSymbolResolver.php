<?php

namespace App\Services\Notifications;

use Illuminate\Support\Facades\DB;

class UserWatchlistSymbolResolver
{
    /**
     * Resolve all distinct uppercase stock symbols across a user's watchlists.
     *
     * @return list<string>
     */
    public function forUser(int $userId): array
    {
        return DB::table('watchlist_items')
            ->join('watchlists', 'watchlist_items.watchlist_id', '=', 'watchlists.id')
            ->join('stocks', 'watchlist_items.stock_id', '=', 'stocks.id')
            ->where('watchlists.user_id', $userId)
            ->distinct()
            ->pluck('stocks.symbol')
            ->map(fn ($symbol) => strtoupper(trim((string) $symbol)))
            ->filter(fn (string $symbol) => $symbol !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Bulk-resolve watched symbols for multiple users in a single query.
     *
     * @param  list<int>  $userIds
     * @return array<int, list<string>>
     */
    public function forUsers(array $userIds): array
    {
        if (empty($userIds)) {
            return [];
        }

        $rows = DB::table('watchlist_items')
            ->join('watchlists', 'watchlist_items.watchlist_id', '=', 'watchlists.id')
            ->join('stocks', 'watchlist_items.stock_id', '=', 'stocks.id')
            ->whereIn('watchlists.user_id', $userIds)
            ->select(['watchlists.user_id', 'stocks.symbol'])
            ->distinct()
            ->get();

        $grouped = [];
        foreach ($userIds as $id) {
            $grouped[(int) $id] = [];
        }

        foreach ($rows as $row) {
            $uid = (int) $row->user_id;
            $sym = strtoupper(trim((string) $row->symbol));
            if ($sym !== '') {
                $grouped[$uid][$sym] = $sym;
            }
        }

        return array_map(fn (array $set) => array_values($set), $grouped);
    }
}
