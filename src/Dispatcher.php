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
use EasySwoole\Trigger\Trigger;

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
                $clientIp = $server->getClientInfo($client->getFd())['remote_ip'];
                break;
            }
            case Config::WEB_SOCKET:{
                $client = new WebSocket( ...$args);
                $clientIp = $server->getClientInfo($client->getFd())['remote_ip'];
                break;
            }
            case Config::UDP:{
                $client = new Udp( ...$args);
                $clientIp = $client->getAddress();
                break;
            }
            default:{
                throw new \Exception('dispatcher type error : '.$type);
            }
        }
        if($this->config->getIpWhiteList() && (!$this->config->getIpWhiteList()->check($clientIp))){
            $this->close($server,$client);
            return;
        }
        $caller = null;
        $response = new Response();
        try{
            $caller = $this->config->getParser()->decode($data,$client);
        }catch (\Throwable $throwable){
            $this->hookException($server,$throwable,$data,$client,$response);
        }
        //若解析器返回null，则调用errorHandler，且状态为包解析错误
       if($caller instanceof Caller){
            //解包正确
           $controller = $caller->getControllerClass();
           try{
               (new $controller($caller,$response));
           }catch (\Throwable $throwable){
               $this->hookException($server,$throwable,$data,$client,$response);
           }
       }

       switch ($response->getStatus()){
           case Response::STATUS_OK:{
               $res = $this->config->getParser()->encode($response,$client);
               $this->response($server,$client,$res);
               break;
           }
           case Response::STATUS_RESPONSE_AND_CLOSE:{
               $res = $this->config->getParser()->encode($response,$client);
               $this->response($server,$client,$res);
               $this->close($server,$client);
               break;
           }
           case Response::STATUS_RESPONSE_DETACH:{
               break;
           }
           case Response::STATUS_CLOSE:{
               $this->close($server,$client);
               break;
           }
       }
    }


    private function response(\swoole_server $server,$client,$data)
    {
        if($client instanceof Tcp){
            $server->send($client->getFd(),$data,$client->getReactorId());
        }else if($client instanceof WebSocket){
            $server->push($client->getFd(),$data);
        }else if($client instanceof Udp){
            $server->sendto($client->getAddress(),$client->getPort(),$data,$client->getServerSocket());
        }
    }

    private function close(\swoole_server $server,$client)
    {
        if($client instanceof Tcp){
            $server->close($client->getFd());
        }
    }

    private function hookException(\swoole_server $server,\Throwable $throwable,string $raw,$client,Response $response)
    {
        if(is_callable($this->config->getOnExceptionHandler())){
            call_user_func($this->config->getOnExceptionHandler(),$server,$throwable,$raw,$client,$response);
        }else{
            Trigger::throwable($throwable);
        }
    }
}