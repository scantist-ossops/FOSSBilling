#!/usr/bin/env php
<?php

/**
 * The FOSSBilling CLI.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license   Apache-2.0
 *
 * Copyright FOSSBilling 2023
 *
 * This source file is subject to the Apache-2.0 License that is bundled
 * with this source code in the file LICENSE
 */

require_once __DIR__ . '/load.php';
$di = include __DIR__ . '/di.php';

use Symfony\Component\Console\Application;

$di['translate']();

$application = new Application();

// Setting the application constraints
$application->setName('FOSSBilling');
$application->setVersion($di['mod_service']('system')->getVersion());

// Dynamically load the commands
$commands = glob(__DIR__ . '/library/Command/*.php');
foreach ($commands as $command) {
    $command = basename($command, '.php');
    $class = 'Command_' . $command;
    
    $command = new $class();
    $command->setDi($di);
    $application->add($command);
}

$application->run();