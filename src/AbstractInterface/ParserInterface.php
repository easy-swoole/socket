<?php
/**
 * Created by PhpStorm.
 * User: yf
 * Date: 2018/5/25
 * Time: 下午4:12
 */

namespace EasySwoole\Socket\AbstractInterface;


use EasySwoole\Socket\Bean\Caller;
use EasySwoole\Socket\Bean\Response;
use EasySwoole\Socket\Client\Tcp;
use EasySwoole\Socket\Client\Udp;

interface ParserInterface
{
    public function decode(string $raw, Tcp|Udp $client): ?Caller;

    public function encode(Response $response, Tcp|Udp $client): ?string;
}
