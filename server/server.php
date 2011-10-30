<?php

use WebSocket as W;
use WebSocket\Application as WA;

// autoload function
function __autoload($class)
{
    // convert namespace to full file path
    $class = '' . str_replace('\\', DIRECTORY_SEPARATOR, $class) . '.php';
    require_once($class);
}

$config = parse_ini_file('config.ini');

$server = new W\Server($config['address'], $config['port']);
$server->registerApplication('example', WA\ExampleApplication::getInstance());
// show Connection log (connect, disconnect, data receive...)
$server->setDebug($config['debug']);
$server->run();