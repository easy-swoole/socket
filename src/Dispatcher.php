<?php
/**
 * Created by PhpStorm.
 * User: yf
 * Date: 2018/5/25
 * Time: 下午3:16
 */

namespace EasySwoole\Socket;


use EasySwoole\Component\Invoker;
use EasySwoole\Socket\AbstractInterface\ParserInterface;
use EasySwoole\Socket\Client\Tcp;
use EasySwoole\Socket\Client\Udp;
use EasySwoole\Socket\Client\WebSocket;
use EasySwoole\Spl\SplStream;
use EasySwoole\Trigger\Trigger;

class Dispatcher
{
    const TCP = 1;
    const WEB_SOCK = 2;
    const UDP = 3;

    private $exceptionHandler = null;
    private $parserClass = null;

    function setExceptionHandler(callable $handler)
    {
        $this->exceptionHandler = $handler;
    }

    function __construct(string $parserInterface)
    {
        try{
            $ref = new \ReflectionClass($parserInterface);
            if($ref->implementsInterface(ParserInterface::class)){
                $this->parserClass = $parserInterface;
            }else{
                throw new \Exception("class {$parserInterface} not a implement ".'EasySwoole\Core\Socket\AbstractInterface\ParserInterface');
            }
        }catch (\Throwable $throwable){
            //此处不做异常拦截
            throw $throwable;
        }
    }

    /*
     * $args:
     *  Tcp  $fd，$reactorId
     *  Web Socket swoole_websocket_frame $frame
     *  Udp array $client_info;
     */
    function dispatch(\swoole_server $server,$type ,string $data, ...$args):void
    {
        switch ($type){
            case self::TCP:{
                $client = new Tcp( ...$args);
                break;
            }
            case self::WEB_SOCK:{
                $client = new WebSocket( ...$args);
                break;
            }
            case self::UDP:{
                $client = new Udp( ...$args);
                break;
            }
            default:{
                throw new \Exception('dispatcher type error : '.$type);
            }
        }
        $command = null;
        try{
            $command = $this->parserClass::decode($data,$client);
        }catch (\Throwable $throwable){
            Trigger::throwable($throwable);
        }
        //若解析器返回null，则调用errorHandler，且状态为包解析错误
       if($command instanceof CommandBean){
            //解包正确
            $controller = $command->getControllerClass();
           try{
               $response = new SplStream();
               (new $controller($client,$command,$response));
               $res = $this->parserClass::encode($response->__toString(),$client);
               if($res !== null){
                   $this->response($server,$client,$res);
               }
           }catch (\Throwable $throwable){
               $res = $this->hookException($server,$throwable,$data,$client);
               if($res){
                   $res = $this->parserClass::encode($response->__toString(),$client);
                   if($res !== null){
                       $this->response($server,$client,$res);
                   }
               }
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

    private function hookException(\swoole_server $server,\Throwable $throwable,string $raw,$client)
    {
        if(is_callable($this->exceptionHandler)){
            return Invoker::callUserFunc($this->exceptionHandler,$throwable,$raw,$client);
        }else{
            if(!$client instanceof Udp){
                $server->close($client->getFd());
            }
        }
    }
}