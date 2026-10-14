<?php
/**
 * Created by PhpStorm.
 * User: yf
 * Date: 2018/5/25
 * Time: 下午3:23
 */

namespace EasySwoole\Socket\Client;


use Swoole\Websocket\Frame;

class WebSocket extends Tcp
{
    private ?string $data = null;
    private ?int $opCode = null;
    private ?bool $isFinish = null;

    public function __construct(?Frame $frame = null)
    {
        parent::__construct($frame?->fd);

        if($frame){
            $this->data = $frame->data;
            $this->opCode = $frame->opcode;
            $this->isFinish = $frame->finish;
        }
    }

    public function getData(): ?string
    {
        return $this->data;
    }

    public function setData(?string $data): void
    {
        $this->data = $data;
    }

    public function getOpCode(): ?int
    {
        return $this->opCode;
    }

    public function setOpCode(?int $opCode): void
    {
        $this->opCode = $opCode;
    }

    public function getIsFinish(): ?bool
    {
        return $this->isFinish;
    }

    public function setIsFinish(?bool $isFinish): void
    {
        $this->isFinish = $isFinish;
    }
}
