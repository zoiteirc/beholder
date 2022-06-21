<?php

namespace App\Modules;

interface BotModule
{
    public function prepare();

    public function boot();
}