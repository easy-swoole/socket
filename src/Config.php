<?php
/**
 * Created by PhpStorm.
 * User: yf
 * Date: 2018/8/8
 * Time: 下午12:13
 */

namespace EasySwoole\Socket;


use EasySwoole\Socket\AbstractInterface\ParserInterface;
use EasySwoole\Socket\Enum\SocketType;

class Config
{
    protected SocketType $type;
    /** @var callable|null */
    protected mixed $onExceptionHandler = null;
    protected ParserInterface $parser;

    public function __construct(SocketType $type, ParserInterface $parser)
    {
        $this->type = $type;
        $this->setParser($parser);
    }

    public function getType(): SocketType
    {
        return $this->type;
    }

    public function setType(SocketType $type): void
    {
        $this->type = $type;
    }

    public function getOnExceptionHandler(): ?callable
    {
        return $this->onExceptionHandler;
    }

    public function setOnExceptionHandler(?callable $onExceptionHandler): void
    {
        $this->onExceptionHandler = $onExceptionHandler;
    }

    public function getParser(): ParserInterface
    {
        return $this->parser;
    }

    public function setParser(ParserInterface $parser): void
    {
        $this->parser = $parser;
    }
}
