<?php

namespace App\Modules\Behold\Stats;

class StatTotals  implements StatsTotalsInterface
{
    private array $_data = [];

    function add($chan, $nick, $type, $quantity = 1) : void
    {
        if ($quantity != 0) { // we don't want 0 values causing needless database writes
            if (isset($this->_data[$type][$chan][$nick])) {
                $this->_data[$type][$chan][$nick] += $quantity;
            } else {
                $this->_data[$type][$chan][$nick] = $quantity;
            }
        }
    }

    function getData() : array
    {
        return $this->_data;
    }

    function reset() : void
    {
        // Just empty the _data array - leaving 0 values will generate useless queries otherwise
        $this->_data = [];
    }

    public function purgeChannel($channel)
    {
        foreach (array_keys($this->_data) as $type) {
            unset($this->_data[$type][$channel]);
        }
    }
}