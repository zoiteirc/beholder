<?php

namespace App\Stats;

interface StatsTotalsInterface
{
    const TYPE_MONOLOGUE = 0;
    const TYPE_PROFANITY = 1;
    const TYPE_ACTION = 2;
    const TYPE_VIOLENCE = 3;
    const TYPE_QUESTION = 4;
    const TYPE_SHOUT = 5;
    const TYPE_CAPS = 6;
    const TYPE_SMILE = 7;
    const TYPE_FROWN = 8;
    const TYPE_KICK_VICTIM = 9;
    const TYPE_KICK_PERPETRATOR = 10;
    const TYPE_JOIN = 11;
    const TYPE_PART = 12;
    const TYPE_DONATED_OPS = 13;
    const TYPE_REVOKED_OPS = 14;
}
