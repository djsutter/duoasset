<?php

return [
    'rule' => [
        'price_above' => 'Price Above',
        'price_below' => 'Price Below',
        'percent_change' => 'Percent Change',
        'volume_spike' => 'Volume Spike',
        'breakout_52_week' => '52 Week Breakout',
        'manual_review' => 'Manual Review',
    ],
    'severity' => [
        'info' => 'Info',
        'warning' => 'Warning',
        'critical' => 'Critical',
    ],
    'mail' => [
        'subject' => '[:severity] :symbol — :rule',
        'greeting' => 'Hello :name,',
        'intro' => 'A watchlist alert was triggered for **:symbol** (:rule).',
        'price_line' => 'Last price: :price',
        'message_line' => 'Message: :message',
        'view_action' => 'Open Watchlist',
    ],
];
