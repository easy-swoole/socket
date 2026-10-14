<?php

declare(strict_types=1);

namespace EasySwoole\Socket\Enum;

enum ResponseStatus: string
{
    case OK = 'OK';
    case WITHOUT_RESPONSE = 'WITHOUT_RESPONSE';
    case RESPONSE_AND_CLOSE = 'RESPONSE_AND_CLOSE';
    case CLOSE = 'CLOSE';
}
