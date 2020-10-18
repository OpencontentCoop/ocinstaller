<?php

require_once 'autoload.php';

$cli = eZCLI::instance();
$script = eZScript::instance(array('description' => ("Install pgcrypto if needed"),
    'use-session' => false,
    'use-modules' => false,
    'use-extensions' => false));

$script->startup();

$options = $script->getOptions("[host:][port:][user:][password:][database:]",
    "",
    array(
        'host' => "Connect to host database",
        'port' => "Connect to host database port",
        'user' => "User for login to the database",
        'password' => "Password to use when connecting to the database",
        'database' => "Connecting to the database",
    )
);
$script->initialize();

$host = $options['host'];
$port = $options['port'];
$user = $options['user'];
$database = $options['database'];
$password = is_string($options['password']) ? $options['password'] : "";

$connectString = sprintf('pgsql:host=%s;port=%s;dbname=%s;user=%s;password=%s',
    $host,
    $port,
    $database,
    $user,
    $password
);

try {
    $db = new PDO($connectString, $user, $password);
    $db->exec( "CREATE EXTENSION IF NOT EXISTS pgcrypto;" );
    $script->shutdown();
} catch (PDOException $e) {
    $cli->error($e->getMessage());
    $script->shutdown(2);
}