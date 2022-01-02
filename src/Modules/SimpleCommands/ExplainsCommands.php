<?php

namespace App\Modules\SimpleCommands;

interface ExplainsCommands
{
    /**
     * @return array<string>
     */
    public function getCommandExplanations(): array;
}