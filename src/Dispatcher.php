<?php
/**
 * Created by PhpStorm.
 * User: yf
 * Date: 2018/5/25
 * Time: 下午3:16
 */

namespace EasySwoole\Socket;


use EasySwoole\Socket\Bean\Caller;
use EasySwoole\Socket\Bean\Response;
use EasySwoole\Socket\Client\Tcp;
use EasySwoole\Socket\Client\Udp;
use EasySwoole\Socket\Client\WebSocket;

class Dispatcher
{
    private $config;

    function __construct(Config $config)
    {
        $this->config = $config;
        if($config->getParser() == null){
            throw new \Exception('Package parser is required');
        }
    }

    /*
     * $args:
     *  Tcp  $fd，$reactorId
     *  Web Socket swoole_websocket_frame $frame
     *  Udp array $client_info;
     */
    function dispatch(\swoole_server $server ,string $data, ...$args):void
    {
        $clientIp = null;
        $type = $this->config->getType();
        switch ($type){
            case Config::TCP:{
                $client = new Tcp( ...$args);
                break;
            }
            case Config::WEB_SOCKET:{
                $client = new WebSocket( ...$args);
                break;
            }
            case Config::UDP:{
                $client = new Udp( ...$args);
                break;
            }
            default:{
                throw new \Exception('dispatcher type error : '.$type);
            }
        }
        $caller = null;
        $response = new Response();
        try{
            $caller = $this->config->getParser()->decode($data,$client);
        }catch (\Throwable $throwable){
            //注意，在解包出现异常的时候，则调用异常处理，默认是断开连接，服务端抛出异常
            $this->hookException($server,$throwable,$data,$client,$response);
        }
        //如果成功返回一个调用者，那么执行调用逻辑
        if($caller instanceof Caller){
            $caller->setClient($client);
            //解包正确
            $controller = $caller->getControllerClass();
            try{
                (new $controller($server,$this->config,$caller,$response));
            }catch (\Throwable $throwable){
                //若控制器中没有重写异常处理，默认是断开连接，服务端抛出异常
                $this->hookException($server,$throwable,$data,$client,$response);
            }
        }
        switch ($response->getStatus()){
            case Response::STATUS_OK:{
                $this->response($server,$client,$response);
                break;
            }
            case Response::STATUS_RESPONSE_AND_CLOSE:{
                $this->response($server,$client,$response);
                $this->close($server,$client);
                break;
            }
            case Response::STATUS_CLOSE:{
                $this->close($server,$client);
                break;
            }
        }
    }


    private function response(\swoole_server $server,$client,Response $response)
    {
        $data = $this->config->getParser()->encode($response,$client);
        if($data === null){
            return;
        }
        if($client instanceof WebSocket){
            if($server->exist($client->getFd())){
                $server->push($client->getFd(),$data);
            }
        }else if($client instanceof Tcp){
            if($server->exist($client->getFd())){
                $server->send($client->getFd(),$data);
            }
        }else if($client instanceof Udp){
            $server->sendto($client->getAddress(),$client->getPort(),$data,$client->getServerSocket());
        }
    }

    private function close(\swoole_server $server,$client)
    {
        if($client instanceof Tcp){
            if($server->exist($client->getFd())){
                $server->close($client->getFd());
            }
        }
    }

    private function hookException(\swoole_server $server,\Throwable $throwable,string $raw,$client,Response $response)
    {
        if(is_callable($this->config->getOnExceptionHandler())){
            call_user_func($this->config->getOnExceptionHandler(),$server,$throwable,$raw,$client,$response);
        }else{
            $this->close($server,$client);
            throw $throwable;
        }
    }
}
