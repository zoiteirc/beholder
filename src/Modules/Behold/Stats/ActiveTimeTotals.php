<?php

namespace App\Modules\Behold\Stats;

class ActiveTimeTotals
{
    private $_data = [];

    function add($nick, $chan, $hour, $quantity = 1)
    {
        if (isset($this->_data[$nick][$chan][$hour])) {
            $this->_data[$nick][$chan][$hour] += $quantity;
        } else {
            $this->_data[$nick][$chan][$hour] = $quantity;
        }
    }

    public function data() : array
    {
        return $this->_data;
    }

    function reset()
    {
        $this->_data = []; // Just empty the _data array - leaving 0 values will generate useless queries otherwise
    }
}
