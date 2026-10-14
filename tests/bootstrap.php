<?php

declare(strict_types=1);

use Swoole\Server;

require dirname(__DIR__).'/vendor/autoload.php';

function testServer(): Server
{
    static $server;

    return $server ??= new Server('127.0.0.1', 0);
}
