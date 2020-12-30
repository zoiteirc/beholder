<?php

namespace App\Persistence;

use App\Stats\ActiveTimeTotals;
use App\Stats\QuoteBuffer;
use App\Stats\StatTotals;
use App\Stats\TextStatsBuffer;

interface PersistenceInterface
{
    public function __construct(array $options);

    public function persist(
        StatTotals $lineStatsBuffer,
        TextStatsBuffer $textStatsBuffer,
        ActiveTimeTotals $activeTimesBuffer,
        QuoteBuffer $latestQuotesBuffer,
        array $channelList,
        array $ignoreList
    ) : bool;

    public function getChannels() : array;

    public function getIgnoredNicks() : array;
}
