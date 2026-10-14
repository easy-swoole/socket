<?php
/**
 * Created by PhpStorm.
 * User: yf
 * Date: 2018/5/25
 * Time: 下午3:16
 */

namespace EasySwoole\Socket;


use EasySwoole\Socket\AbstractInterface\Controller;
use EasySwoole\Socket\Bean\Caller;
use EasySwoole\Socket\Bean\Response;
use EasySwoole\Socket\Client\Tcp;
use EasySwoole\Socket\Client\Udp;
use EasySwoole\Socket\Client\WebSocket;
use EasySwoole\Socket\Enum\ResponseStatus;
use EasySwoole\Socket\Enum\SocketType;
use EasySwoole\Socket\Exception\InvalidController;
use Swoole\Server;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server as WebSocketServer;

class Dispatcher
{
    private Config $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    public function dispatchTcp(Server $server, string $data, int $fd, int $reactorId): void
    {
        $this->assertSocketType(SocketType::TCP);
        $this->dispatchClient($server, $data, new Tcp($fd, $reactorId));
    }

    public function dispatchUdp(
        Server $server,
        string $data,
        int $serverSocket,
        string $address,
        int $port
    ): void {
        $this->assertSocketType(SocketType::UDP);
        $this->dispatchClient($server, $data, new Udp($serverSocket, $address, $port));
    }

    public function dispatchWebSocket(WebSocketServer $server, Frame $frame): void
    {
        $this->assertSocketType(SocketType::WEB_SOCKET);
        $this->dispatchClient($server, $frame->data, new WebSocket($frame));
    }

    private function dispatchClient(Server $server, string $data, Tcp|Udp $client): void
    {
        $caller = null;
        $response = new Response();
        try{
            $caller = $this->config->getParser()->decode($data,$client);
        }catch (\Throwable $throwable){
            //注意，在解包出现异常的时候，则调用异常处理，默认是断开连接，服务端抛出异常
            $this->hookException($server,$throwable,$data,$client,$response);
            goto response;
        }
        //如果成功返回一个调用者，那么执行调用逻辑
        if($caller instanceof Caller){
            $caller->setClient($client);
            //解包正确
            $controllerClass = $caller->getControllerClass();
            try{
                if (!is_string($controllerClass)
                    || !is_a($controllerClass, Controller::class, true)) {
                    throw new InvalidController('invalid controller class: '.var_export($controllerClass, true));
                }
                $controller = new $controllerClass();
            }catch (\Throwable $throwable){
                $this->hookException($server,$throwable,$data,$client,$response);
                goto response;
            }
            try{
                $controller->__hook( $server,$caller, $response);
            }catch (\Throwable $throwable){
                $this->hookException($server,$throwable,$data,$client,$response);
            }
        }
        response :{
            try {
                switch ($response->getStatus()){
                    case ResponseStatus::OK:{
                        $this->response($server,$client,$response);
                        break;
                    }
                    case ResponseStatus::WITHOUT_RESPONSE:{
                        break;
                    }
                    case ResponseStatus::RESPONSE_AND_CLOSE:{
                        $this->response($server,$client,$response);
                        $this->close($server, $client, $response);
                        break;
                    }
                    case ResponseStatus::CLOSE:{
                        $this->close($server, $client, $response);
                        break;
                    }
                }
            } catch (\Throwable $throwable) {
                $this->hookException($server,$throwable,$data,$client,$response);
            }
        }
    }

    private function assertSocketType(SocketType $expectedType): void
    {
        $actualType = $this->config->getType();
        if ($actualType !== $expectedType) {
            throw new \LogicException(sprintf(
                'dispatcher socket type must be %s, %s given',
                $expectedType->value,
                $actualType->value
            ));
        }
    }


    private function response(Server $server, Tcp|Udp $client, Response $response): void
    {
        $data = $this->config->getParser()->encode($response,$client);
        if($data === null){
            return;
        }
        if($client instanceof WebSocket){
            $webSocketServer = $this->webSocketServer($server);
            if($webSocketServer->exist($client->getFd())){
                $webSocketServer->push($client->getFd(), $data, $response->getOpCode(), $response->getFlags());
            }
        }else if($client instanceof Tcp){
            if($server->exist($client->getFd())){
                $server->send($client->getFd(),$data);
            }
        }else if($client instanceof Udp){
            $server->sendto($client->getAddress(),$client->getPort(),$data,$client->getServerSocket());
        }
    }

    private function close(Server $server, Tcp|Udp $client, Response $response): void
    {

        // because websocket client extends tcp client, so first if websocket client.
        if ($client instanceof WebSocket) {
            $webSocketServer = $this->webSocketServer($server);
            if ($webSocketServer->exist($client->getFd())) {
                $webSocketServer->disconnect($client->getFd(), $response->getCode(), $response->getReason());
            }
        } else if($client instanceof Tcp && $server->exist($client->getFd())){
            $server->close($client->getFd(), $response->isReset());
        }
    }

    private function webSocketServer(Server $server): WebSocketServer
    {
        if (!$server instanceof WebSocketServer) {
            throw new \LogicException('webSocket client requires a WebSocket server');
        }

        return $server;
    }

    private function hookException(
        Server $server,
        \Throwable $throwable,
        string $raw,
        Tcp|Udp $client,
        Response $response
    ): void
    {
        $exceptionHandler = $this->config->getOnExceptionHandler();
        if($exceptionHandler !== null){
            $exceptionHandler($server,$throwable,$raw,$client,$response);
        }else{
            $this->close($server, $client, $response);
            throw $throwable;
        }
    }
}
