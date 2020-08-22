<?php

$config = [
    'nickname' => 'beholder',
    'username' => 'beholder',
    'realname' => 'Beholder - IRC Channel Stats Aggregator',
    'usermode' => 8,
    'server_host' => '',
    'server_port' => 6667,
    'mysql' => [
        'host' => '',
        'user' => '',
        'pass' => '',
        'dbname' => '',
    ],
    'write_freq' => 60, // how often, in seconds, to write to the db
];

define('EOL',"\n\r");
define('VIOLENT_WORDS','smacks|beats|punches|hits|slaps');
define('PROFANITIES','fuck|shit|bitch|cunt|pussy'); // these might match partial words. eg: "ass" would match "pass"
define('DEBUG',FALSE);
define('LOG_FILE_STANDARD','standard.log');
define('LOG_FILE_ERRORS','errors.log');
define('LOG_FILE_QUERIES','mysql.log');
