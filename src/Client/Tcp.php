<?php
/**
 * Created by PhpStorm.
 * User: yf
 * Date: 2018/5/25
 * Time: 下午3:22
 */

namespace EasySwoole\Socket\Client;


class Tcp
{
    protected ?int $reactorId;
    protected ?int $fd;

    public function __construct(?int $fd = null, ?int $reactorId = null)
    {
        $this->fd = $fd;
        $this->reactorId = $reactorId;
    }

    public function getReactorId(): ?int
    {
        return $this->reactorId;
    }

    public function setReactorId(?int $reactorId): void
    {
        $this->reactorId = $reactorId;
    }

    public function getFd(): ?int
    {
        return $this->fd;
    }

    public function setFd(?int $fd): void
    {
        $this->fd = $fd;
    }
}
