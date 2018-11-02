<?php
/**
 * Created by PhpStorm.
 * User: yf
 * Date: 2018/5/25
 * Time: 下午4:10
 */

namespace EasySwoole\Socket\AbstractInterface;


use EasySwoole\Socket\Bean\Caller;
use EasySwoole\Socket\Bean\Response;
use EasySwoole\Socket\Client\Tcp;
use EasySwoole\Socket\Client\Udp;
use EasySwoole\Socket\Client\WebSocket;
use EasySwoole\Socket\Config;

abstract class Controller
{
    private $response;
    private $caller;
    private $config;
    private $server;

    function __construct(\swoole_server $server,Config $config,Caller $request,Response $response)
    {
        $this->caller = $request;
        $this->response = $response;
        $this->config = $config;
        $this->server = $server;
        $this->__hook();
    }

    protected function actionNotFound(?string $actionName)
    {

    }

    protected function afterAction(?string $actionName)
    {

    }

    protected function onException(\Throwable $throwable):void
    {
        throw $throwable;
    }

    /*
     * 返回false的时候为拦截
     */
    protected function onRequest(?string $actionName):bool
    {
        return true;
    }

    protected function response():Response
    {
        return $this->response;
    }

    protected function responseImmediately(string $string)
    {
        $client = $this->caller->getClient();
        if($client instanceof WebSocket){
            $this->server->push($client->getFd(),$string);
        }else if($client instanceof Tcp){
            $this->server->send($client->getFd(),$string);
        }else if($client instanceof Udp){
            $this->server->sendto($client->getAddress(),$client->getPort(),$string,$client->getServerSocket());
        }
    }

    protected function caller():Caller
    {
        return $this->caller;
    }


    private function __hook()
    {
        try{
            if($this->onRequest($this->caller->getAction()) === false){
                return;
            }else{
                $list = [];
                $ref = new \ReflectionClass(static::class);
                $public = $ref->getMethods(\ReflectionMethod::IS_PUBLIC);
                foreach ($public as $item){
                    array_push($list,$item->getName());
                }
                $all = array_diff($list,
                    [
                        'gc','__hook','__destruct',
                        '__clone','__construct','__call',
                        '__callStatic','__get','__set',
                        '__isset','__unset','__sleep',
                        '__wakeup','__toString','__invoke',
                        '__set_state','__clone','__debugInfo',
                        'responseImmediately'
                    ]
                );
                $action = $this->caller->getAction();
                if(in_array($action,$all)){
                    $this->$action();
                }else{
                    $this->actionNotFound($action);
                }
            }
        }catch (\Throwable $throwable){
            //若没有重构onException，直接抛出给上层
            $this->onException($throwable);
        }finally{
            $this->afterAction($this->caller->getAction());
        }
    }
}