<?php
/**
 * Created by PhpStorm.
 * User: yf
 * Date: 2018/8/10
 * Time: 上午11:06
 */

namespace EasySwoole\Socket\Bean;


use EasySwoole\Spl\SplBean;

class Response extends SplBean
{
    const STATUS_RESPONSE_DETACH = 'RESPONSE_DETACH';//不响应客户端，可能是在异步时返回。
    const STATUS_RESPONSE_AND_CLOSE = 'RESPONSE_AND_CLOSE';//响应后关闭
    const STATUS_CLOSE = 'CLOSE';//不响应，直接关闭连接
    const STATUS_OK = 'OK';

    protected $status = self::STATUS_OK;
    protected $args = [];
    protected $message = null;

    /**
     * @return string
     */
    public function getStatus(): string
    {
        return $this->status;
    }

    /**
     * @param string $status
     */
    public function setStatus(string $status): void
    {
        $this->status = $status;
    }

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

    /**
     * @return null
     */
    public function getMessage()
    {
        return $this->message;
    }

    /**
     * @param null $message
     */
    public function setMessage($message): void
    {
        $this->message = $message;
    }

}