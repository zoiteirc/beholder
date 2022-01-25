<?php

namespace App\Modules\Behold\Stats;

class TextStatsBuffer
{
    // This class deals with clever stuff like word and character count averages
    private $_data = [];

    function add($nick, $chan, $messages, $words, $characters)
    {
        if (isset($this->_data[$nick][$chan])) {
            $this->_data[$nick][$chan]['messages'] += $messages;
            $this->_data[$nick][$chan]['words'] += $words;
            $this->_data[$nick][$chan]['chars'] += $characters;
        } else {
            $this->_data[$nick][$chan] = [
                'messages' => $messages,
                'words' => $words,
                'chars' => $characters
            ];
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

    protected function escape($str)
    {
        return $str;
    }
}
