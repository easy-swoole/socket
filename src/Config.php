<?php
/**
 * Created by PhpStorm.
 * User: yf
 * Date: 2018/8/8
 * Time: 下午12:13
 */

namespace EasySwoole\Socket;


use EasySwoole\Socket\AbstractInterface\ParserInterface;

class Config
{
    const UDP = 'UDP';
    const TCP = 'TCP';
    const WEB_SOCKET = 'WEB_SOCKET';

    protected $type;
    protected $onExceptionHandler = null;
    protected $parser;
    protected $ipWhiteList = null;


    /**
     * @return mixed
     */
    public function getType()
    {
        return $this->type;
    }

    /**
     * @param mixed $type
     */
    public function setType($type): void
    {
        $this->type = $type;
    }

    /**
     * @return null
     */
    public function getOnExceptionHandler()
    {
        return $this->onExceptionHandler;
    }

    /**
     * @param null $onExceptionHandler
     */
    public function setOnExceptionHandler($onExceptionHandler): void
    {
        $this->onExceptionHandler = $onExceptionHandler;
    }

    /**
     * @return mixed
     */
    public function getParser():?ParserInterface
    {
        return $this->parser;
    }

    /**
     * @param mixed $parser
     */
    public function setParser(ParserInterface $parser): void
    {
        $this->parser = $parser;
    }
    
}