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
use Swoole\Server;
use Swoole\WebSocket\Server as WebSocketServer;

abstract class Controller
{
    private Response $response;
    private Caller $caller;
    private Server $server;

    private array $allowMethods = [];

    public function __construct()
    {
        //支持在子类控制器中以private，protected来修饰某个方法不可见
        $list = [];
        $ref = new \ReflectionClass(static::class);
        $public = $ref->getMethods(\ReflectionMethod::IS_PUBLIC);
        foreach ($public as $item){
            if(!$item->isStatic()){
                array_push($list,$item->getName());
            }
        }
        $this->allowMethods = array_diff($list,
            [
                '__hook','__destruct',
                '__clone','__construct','__call',
                '__callStatic','__get','__set',
                '__isset','__unset','__sleep',
                '__wakeup','__toString','__invoke',
                '__set_state','__clone','__debugInfo'
            ]
        );
    }

    protected function actionNotFound(?string $actionName): void
    {

    }

    protected function afterAction(?string $actionName): void
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

    protected function responseImmediately(string $string): bool
    {
        $client = $this->caller->getClient();
        if($client instanceof WebSocket){
            return $this->webSocketServer()->push($client->getFd(), $string);
        }else if($client instanceof Tcp){
            return $this->server->send($client->getFd(), $string);
        }

        return $this->server->sendto(
            $client->getAddress(),
            $client->getPort(),
            $string,
            $client->getServerSocket()
        );
    }

    protected function caller():Caller
    {
        return $this->caller;
    }

    public function __hook(Server $server, Caller $request, Response $response): void
    {
        if ($request->getClient() instanceof WebSocket && !$server instanceof WebSocketServer) {
            throw new \InvalidArgumentException('webSocket client requires a WebSocket server');
        }

        $this->caller = $request;
        $this->response = $response;
        $this->server = $server;
        $actionName = $request->getAction();
        $actionThrowable = null;
        try{
            if($this->onRequest($actionName) !== false){
                if(in_array($actionName,$this->allowMethods, true)){
                    $this->$actionName();
                }else{
                    $this->actionNotFound($actionName);
                }
            }
        }catch (\Throwable $throwable){
            //若没有重构onException，直接抛出给上层
            try {
                $this->onException($throwable);
            } catch (\Throwable $handledThrowable) {
                $actionThrowable = $handledThrowable;
            }
        }finally{
            try{
                $this->afterAction($actionName);
            }catch (\Throwable $throwable){
                if ($actionThrowable === null) {
                    $this->onException($throwable);
                }
            }
        }

        if ($actionThrowable !== null) {
            throw $actionThrowable;
        }
    }

    private function webSocketServer(): WebSocketServer
    {
        if (!$this->server instanceof WebSocketServer) {
            throw new \LogicException('webSocket client requires a WebSocket server');
        }

        return $this->server;
    }
}
