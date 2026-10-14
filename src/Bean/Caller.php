<?php
/**
 * Created by PhpStorm.
 * User: yf
 * Date: 2018/8/10
 * Time: 上午10:57
 */

namespace EasySwoole\Socket\Bean;

use EasySwoole\Socket\Client\Tcp;
use EasySwoole\Socket\Client\Udp;

class Caller
{
    private array $args = [];
    private ?string $controllerClass = null;
    private ?string $action = null;
    private Tcp|Udp $client;

    public function getArgs(): array
    {
        return $this->args;
    }

    public function setArgs(array $args): void
    {
        $this->args = $args;
    }


    public function getControllerClass():?string
    {
        return $this->controllerClass;
    }

    public function setControllerClass(?string $controllerClass): void
    {
        $this->controllerClass = $controllerClass;
    }

    public function getAction():?string
    {
        return $this->action;
    }

    public function setAction(?string $action): void
    {
        $this->action = $action;
    }

    public function getClient(): Tcp|Udp
    {
        return $this->client;
    }

    public function setClient(Tcp|Udp $client): void
    {
        $this->client = $client;
    }

}
