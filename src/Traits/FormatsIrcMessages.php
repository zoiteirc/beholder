<?php

namespace App\Traits;

trait FormatsIrcMessages
{
    protected function action(string $str) {
        return "\x01" . 'ACTION ' . $str . "\x01";
    }

    protected function bold(string $str) {
        return "\x02" . $str . "\x02";
    }

    protected function italic(string $str) {
        return "\x1D" . $str . "\x1D";
    }

    protected function underline(string $str) {
        return "\x1F" . $str . "\x1F";
    }

    protected function strikethrough(string $str) {
        return "\x1E" . $str . "\x1E";
    }

    protected function monospace(string $str) {
        return "\x11" . $str . "\x11";
    }
}