#!/usr/bin/env php
<?php

// TODO: Log file for persistence queries
// TODO: Log file for debugging
// TODO: Manage channels dynamically

require __DIR__.'/vendor/autoload.php';

use Symfony\Component\Console\Application;

$dotenv = new \Symfony\Component\Dotenv\Dotenv();

$dotenv->load('.env');

$command = new \App\Command\RunBot();

$application = (new Application());

$application->add($command);

$application->setDefaultCommand($command->getName(), true);

$application->run();
