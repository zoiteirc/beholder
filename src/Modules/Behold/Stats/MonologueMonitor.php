<?php

namespace App\Modules\Behold\Stats;

class MonologueMonitor
{
    private $_lastspoke_nick = [];

    private $_lastspoke_count = [];

    function spoke($channel, $nick)
    {
        if (isset($this->_lastspoke_nick[$channel]) && $this->_lastspoke_nick[$channel] == $nick) {
            $this->_lastspoke_count[$channel]++;
        } else {
            $this->_lastspoke_nick[$channel] = $nick;
            $this->_lastspoke_count[$channel] = 1;
        }
    }

    function is_becoming_monologue($channel)
    {
        return $this->_lastspoke_count[$channel] == 5;
    }

    public function purgeChannel($channel)
    {
        unset($this->_lastspoke_nick[$channel]);
        unset($this->_lastspoke_count[$channel]);
    }
}
