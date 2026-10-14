<?php

declare(strict_types=1);

namespace EasySwoole\Socket\Enum;

enum SocketType: string
{
    case UDP = 'UDP';
    case TCP = 'TCP';
    case WEB_SOCKET = 'WEB_SOCKET';
}
