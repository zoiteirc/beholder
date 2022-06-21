<?php

namespace App\Modules\Behold\Stats;

class QuoteBuffer
{
    private $_data = [];

    function set($nick, $chan, $quote = '')
    {
        $this->_data[$nick][$chan] = $quote;
    }

    public function data() : array
    {
        return $this->_data;
    }

    function reset()
    {
        $this->_data = []; // Just empty the _data array - leaving 0 values will generate useless queries otherwise
    }

    public function purgeChannel($channel)
    {
        foreach (array_keys($this->_data) as $nick) {
            unset($this->_data[$nick][$channel]);
        }
    }
}
