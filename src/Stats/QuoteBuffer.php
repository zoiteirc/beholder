<?php

namespace App\Stats;

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
}
