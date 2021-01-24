#!/usr/bin/env php
<?php

// TODO: Log file for persistence queries
// TODO: Log file for debugging
// TODO: Manage channels dynamically

require __DIR__.'/vendor/autoload.php';

use Symfony\Component\Console\Application;

$container = require('container.php');

/** @var \App\Command\RunBotCommand $runBotCommand */
$runBotCommand = $container->get('run_bot_command');

$application = (new Application());

$application->add($runBotCommand);

$application->setDefaultCommand($runBotCommand->getName(), true);

$application->run();
