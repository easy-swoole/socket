<?php

declare(strict_types=1);

use EasySwoole\Socket\AbstractInterface\Controller;
use EasySwoole\Socket\AbstractInterface\ParserInterface;
use EasySwoole\Socket\Bean\Caller;
use EasySwoole\Socket\Bean\Response;
use EasySwoole\Socket\Client\Tcp;
use EasySwoole\Socket\Client\Udp;
use EasySwoole\Socket\Client\WebSocket;
use EasySwoole\Socket\Config;
use EasySwoole\Socket\Dispatcher;
use EasySwoole\Socket\Enum\SocketType;
use PHPUnit\Framework\TestCase;
use Swoole\Server;
use Swoole\WebSocket\Frame;

final class PublicActionController extends Controller
{
    public ?string $calledAction = null;

    public function allowed(): void
    {
        $this->calledAction = 'allowed';
    }

    public function helper(): void
    {
        $this->calledAction = 'helper';
    }

    protected function protectedHelper(): void
    {
        $this->calledAction = 'protected-helper';
    }

    protected function actionNotFound(?string $actionName): void
    {
        $this->calledAction = 'not-found';
    }
}

final class ControllerClientParser implements ParserInterface
{
    public function decode(string $raw, Tcp|Udp $client): ?Caller
    {
        $caller = new Caller();
        $caller->setControllerClass(ClientInspectionController::class);
        $caller->setAction('inspect');

        return $caller;
    }

    public function encode(Response $response, Tcp|Udp $client): ?string
    {
        return null;
    }
}

final class ClientInspectionController extends Controller
{
    public static array $connection = [];

    public function inspect(): void
    {
        $client = $this->caller()->getClient();

        if ($client instanceof WebSocket) {
            self::$connection = [
                'type' => 'websocket',
                'fd' => $client->getFd(),
                'data' => $client->getData(),
                'opcode' => $client->getOpCode(),
                'finish' => $client->getIsFinish(),
            ];
        } elseif ($client instanceof Tcp) {
            self::$connection = [
                'type' => 'tcp',
                'fd' => $client->getFd(),
                'reactorId' => $client->getReactorId(),
            ];
        } else {
            self::$connection = [
                'type' => 'udp',
                'serverSocket' => $client->getServerSocket(),
                'address' => $client->getAddress(),
                'port' => $client->getPort(),
            ];
        }
    }
}

final class ImmediateResponseController extends Controller
{
    public ?bool $sendResult = null;

    public function sendNow(): void
    {
        $this->sendResult = $this->responseImmediately('immediate-response');
    }
}

final class FailingLifecycleController extends Controller
{
    public function __construct(private readonly bool $failAction, private readonly bool $failAfterAction)
    {
        parent::__construct();
    }

    public function handle(): void
    {
        if ($this->failAction) {
            throw new RuntimeException('action failed');
        }
    }

    protected function afterAction(?string $actionName): void
    {
        if ($this->failAfterAction) {
            throw new RuntimeException('after action failed');
        }
    }
}

final class ImmediateResponseServer extends \Swoole\WebSocket\Server
{
    public int $existCalls = 0;
    public array $sent = [];
    public array $sentTo = [];
    public array $pushed = [];

    public function __construct(private readonly bool $result)
    {
    }

    public function exist(int $fd): bool
    {
        ++$this->existCalls;

        return true;
    }

    public function send(string|int $fd, string $sendData, int $serverSocket = -1): bool
    {
        $this->sent[] = [$fd, $sendData, $serverSocket];

        return $this->result;
    }

    public function sendto(string $ip, int $port, string $sendData, int $serverSocket = -1): bool
    {
        $this->sentTo[] = [$ip, $port, $sendData, $serverSocket];

        return $this->result;
    }

    public function push(
        int $fd,
        Frame|string $data,
        int $opcode = WEBSOCKET_OPCODE_TEXT,
        int $flags = SWOOLE_WEBSOCKET_FLAG_FIN
    ): bool {
        $this->pushed[] = [$fd, $data, $opcode, $flags];

        return $this->result;
    }
}

final class ControllerTest extends TestCase
{
    public function testCallsPublicAction(): void
    {
        $controller = $this->dispatchAction('allowed');

        self::assertSame('allowed', $controller->calledAction);
    }

    public function testCallsEveryPublicAction(): void
    {
        $controller = $this->dispatchAction('helper');

        self::assertSame('helper', $controller->calledAction);
    }

    public function testRejectsControllerMethodAsAction(): void
    {
        $controller = $this->dispatchAction('__hook');

        self::assertSame('not-found', $controller->calledAction);
    }

    public function testRejectsProtectedMethodAsAction(): void
    {
        $controller = $this->dispatchAction('protectedHelper');

        self::assertSame('not-found', $controller->calledAction);
    }

    public function testControllerReadsTcpConnectionInformation(): void
    {
        $this->dispatcher(SocketType::TCP)->dispatchTcp(testServer(), 'request', 31, 6);

        self::assertSame([
            'type' => 'tcp',
            'fd' => 31,
            'reactorId' => 6,
        ], ClientInspectionController::$connection);
    }

    public function testControllerReadsUdpConnectionInformation(): void
    {
        $this->dispatcher(SocketType::UDP)
            ->dispatchUdp(testServer(), 'request', 11, '192.168.1.10', 9701);

        self::assertSame([
            'type' => 'udp',
            'serverSocket' => 11,
            'address' => '192.168.1.10',
            'port' => 9701,
        ], ClientInspectionController::$connection);
    }

    public function testControllerReadsWebSocketConnectionInformation(): void
    {
        $frame = new Frame();
        $frame->fd = 32;
        $frame->data = 'frame-data';
        $frame->opcode = WEBSOCKET_OPCODE_BINARY;
        $frame->finish = false;

        $this->dispatcher(SocketType::WEB_SOCKET)
            ->dispatchWebSocket(new ImmediateResponseServer(true), $frame);

        self::assertSame([
            'type' => 'websocket',
            'fd' => 32,
            'data' => 'frame-data',
            'opcode' => WEBSOCKET_OPCODE_BINARY,
            'finish' => false,
        ], ClientInspectionController::$connection);
    }

    public function testImmediateTcpResponseReturnsSendResultWithoutExistenceCheck(): void
    {
        $server = new ImmediateResponseServer(true);
        $controller = $this->immediateResponse(new Tcp(41, 7), $server);

        self::assertTrue($controller->sendResult);
        self::assertSame([[41, 'immediate-response', -1]], $server->sent);
        self::assertSame(0, $server->existCalls);
    }

    public function testImmediateUdpResponseReturnsSendResult(): void
    {
        $server = new ImmediateResponseServer(false);
        $controller = $this->immediateResponse(new Udp(13, '10.0.0.1', 9801), $server);

        self::assertFalse($controller->sendResult);
        self::assertSame([['10.0.0.1', 9801, 'immediate-response', 13]], $server->sentTo);
        self::assertSame(0, $server->existCalls);
    }

    public function testImmediateWebSocketResponseReturnsPushResultWithoutExistenceCheck(): void
    {
        $frame = new Frame();
        $frame->fd = 42;
        $server = new ImmediateResponseServer(true);
        $controller = $this->immediateResponse(new WebSocket($frame), $server);

        self::assertTrue($controller->sendResult);
        self::assertSame([
            [42, 'immediate-response', WEBSOCKET_OPCODE_TEXT, SWOOLE_WEBSOCKET_FLAG_FIN],
        ], $server->pushed);
        self::assertSame(0, $server->existCalls);
    }

    public function testAfterActionDoesNotOverrideActionException(): void
    {
        $controller = new FailingLifecycleController(true, true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('action failed');

        $this->invokeController($controller, 'handle');
    }

    public function testAfterActionExceptionIsThrownWhenActionSucceeds(): void
    {
        $controller = new FailingLifecycleController(false, true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('after action failed');

        $this->invokeController($controller, 'handle');
    }

    public function testControllerRejectsWebSocketClientWithPlainServer(): void
    {
        $frame = new Frame();
        $frame->fd = 1;
        $caller = new Caller();
        $caller->setClient(new WebSocket($frame));
        $caller->setAction('handle');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('webSocket client requires a WebSocket server');

        (new PublicActionController())->__hook(testServer(), $caller, new Response());
    }

    private function dispatchAction(string $action): PublicActionController
    {
        $caller = new Caller();
        $caller->setClient(new Tcp(1, 0));
        $caller->setAction($action);
        $controller = new PublicActionController();
        $controller->__hook(testServer(), $caller, new Response());

        return $controller;
    }

    private function dispatcher(SocketType $type): Dispatcher
    {
        $config = new Config($type, new ControllerClientParser());

        return new Dispatcher($config);
    }

    private function immediateResponse(Tcp|Udp $client, Server $server): ImmediateResponseController
    {
        $caller = new Caller();
        $caller->setClient($client);
        $caller->setAction('sendNow');
        $controller = new ImmediateResponseController();
        $controller->__hook($server, $caller, new Response());

        return $controller;
    }

    private function invokeController(Controller $controller, string $action): void
    {
        $caller = new Caller();
        $caller->setClient(new Tcp(1, 0));
        $caller->setAction($action);
        $controller->__hook(testServer(), $caller, new Response());
    }
}
