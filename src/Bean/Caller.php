<?php
/**
 * Created by PhpStorm.
 * User: yf
 * Date: 2018/8/10
 * Time: 上午10:57
 */

namespace EasySwoole\Socket\Bean;


class Caller
{
    private $args = [];
    private $controllerClass = null;
    private $action = null;

    /**
     * @return array
     */
    public function getArgs(): array
    {
        return $this->args;
    }

    /**
     * @param array $args
     */
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
}