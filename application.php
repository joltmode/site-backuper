#!/usr/bin/env php
<?php

require __DIR__ . '/vendor/autoload.php';

use Symfony\Component\Console\Application;

$application = new Application();

$application->add(new App\Commands\MakeBackupCommand());
$application->add(new App\Commands\RestoreBackupCommand());

$application->run();