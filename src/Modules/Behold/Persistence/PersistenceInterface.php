<?php

namespace App\Modules\Behold\Persistence;

use App\Modules\Behold\Stats\ActiveTimeTotals;
use App\Modules\Behold\Stats\QuoteBuffer;
use App\Modules\Behold\Stats\StatTotals;
use App\Modules\Behold\Stats\TextStatsBuffer;

interface PersistenceInterface
{
    public function prepare(): void;

    public function persist(
        StatTotals $lineStatsBuffer,
        TextStatsBuffer $textStatsBuffer,
        ActiveTimeTotals $activeTimesBuffer,
        QuoteBuffer $latestQuotesBuffer,
        array $channelList,
        array $ignoreList
    ) : bool;
}
