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
use EasySwoole\Socket\Enum\ResponseStatus;
use EasySwoole\Socket\Enum\SocketType;
use EasySwoole\Socket\Exception\InvalidController;
use PHPUnit\Framework\TestCase;
use Swoole\Server;
use Swoole\WebSocket\Frame;

final class UnsafeControllerTarget
{
    public static bool $constructed = false;

    public function __construct()
    {
        self::$constructed = true;
    }
}

final class DispatcherTestController extends Controller
{
    protected function actionAllowList(): array
    {
        return ['handle'];
    }

    public function handle(): void
    {
    }
}

final class DispatcherTestParser implements ParserInterface
{
    public function __construct(
        private readonly Caller $caller,
        private readonly bool $failEncode = false
    ) {
    }

    public function decode(string $raw, Tcp|Udp $client): ?Caller
    {
        return $this->caller;
    }

    public function encode(Response $response, Tcp|Udp $client): ?string
    {
        if ($this->failEncode) {
            throw new \RuntimeException('encode failed');
        }

        return null;
    }
}

final class CapturingParser implements ParserInterface
{
    public Tcp|Udp|null $decodedClient = null;
    public Tcp|Udp|null $encodedClient = null;

    public function decode(string $raw, Tcp|Udp $client): ?Caller
    {
        $this->decodedClient = $client;

        return null;
    }

    public function encode(Response $response, Tcp|Udp $client): ?string
    {
        $this->encodedClient = $client;

        return null;
    }
}

final class ResponseParser implements ParserInterface
{
    public function __construct(private readonly string $encodedData)
    {
    }

    public function decode(string $raw, Tcp|Udp $client): ?Caller
    {
        return null;
    }

    public function encode(Response $response, Tcp|Udp $client): ?string
    {
        return $this->encodedData;
    }
}

final class WithoutResponseController extends Controller
{
    protected function actionAllowList(): array
    {
        return ['handle'];
    }

    public function handle(): void
    {
        $this->response()->setStatus(ResponseStatus::WITHOUT_RESPONSE);
    }
}

final class WithoutResponseParser implements ParserInterface
{
    public int $encodeCalls = 0;

    public function decode(string $raw, Tcp|Udp $client): ?Caller
    {
        $caller = new Caller();
        $caller->setControllerClass(WithoutResponseController::class);
        $caller->setAction('handle');

        return $caller;
    }

    public function encode(Response $response, Tcp|Udp $client): ?string
    {
        ++$this->encodeCalls;

        return 'unexpected-response';
    }
}

final class CloseResponseController extends Controller
{
    protected function actionAllowList(): array
    {
        return ['handle'];
    }

    public function handle(): void
    {
        $args = $this->caller()->getArgs();
        $response = $this->response();
        $response->setStatus($args['status']);
        $response->setReset($args['reset'] ?? false);
        $response->setCode($args['code'] ?? SWOOLE_WEBSOCKET_CLOSE_NORMAL);
        $response->setReason($args['reason'] ?? '');
    }
}

final class CloseResponseParser implements ParserInterface
{
    public int $encodeCalls = 0;

    public function __construct(private readonly array $responseOptions)
    {
    }

    public function decode(string $raw, Tcp|Udp $client): ?Caller
    {
        $caller = new Caller();
        $caller->setControllerClass(CloseResponseController::class);
        $caller->setAction('handle');
        $caller->setArgs($this->responseOptions);

        return $caller;
    }

    public function encode(Response $response, Tcp|Udp $client): ?string
    {
        ++$this->encodeCalls;

        return 'encoded-response';
    }
}

final class RecordingServer extends \Swoole\WebSocket\Server
{
    public array $sent = [];
    public array $sentTo = [];
    public array $pushed = [];
    public array $closed = [];
    public array $disconnected = [];

    public function __construct()
    {
    }

    public function exist(int $fd): bool
    {
        return true;
    }

    public function send(string|int $fd, string $sendData, int $serverSocket = -1): bool
    {
        $this->sent[] = [$fd, $sendData, $serverSocket];

        return true;
    }

    public function sendto(string $ip, int $port, string $sendData, int $serverSocket = -1): bool
    {
        $this->sentTo[] = [$ip, $port, $sendData, $serverSocket];

        return true;
    }

    public function push(
        int $fd,
        Frame|string $data,
        int $opcode = WEBSOCKET_OPCODE_TEXT,
        int $flags = SWOOLE_WEBSOCKET_FLAG_FIN
    ): bool {
        $this->pushed[] = [$fd, $data, $opcode, $flags];

        return true;
    }

    public function close(int $fd, bool $reset = false): bool
    {
        $this->closed[] = [$fd, $reset];

        return true;
    }

    public function disconnect(
        int $fd,
        int $code = SWOOLE_WEBSOCKET_CLOSE_NORMAL,
        string $reason = ''
    ): bool {
        $this->disconnected[] = [$fd, $code, $reason];

        return true;
    }
}

final class DispatcherTest extends TestCase
{
    protected function setUp(): void
    {
        UnsafeControllerTarget::$constructed = false;
    }

    public function testRejectsInvalidControllerBeforeConstruction(): void
    {
        $caller = new Caller();
        $caller->setControllerClass(UnsafeControllerTarget::class);
        $handled = [];

        $this->dispatcher($caller, false, $handled)->dispatchTcp(testServer(), 'request', 1, 0);

        self::assertFalse(UnsafeControllerTarget::$constructed);
        self::assertCount(1, $handled);
        self::assertInstanceOf(InvalidController::class, $handled[0]);
    }

    public function testRoutesEncodeExceptionToExceptionHandler(): void
    {
        $caller = new Caller();
        $caller->setControllerClass(DispatcherTestController::class);
        $caller->setAction('handle');
        $handled = [];

        $this->dispatcher($caller, true, $handled)->dispatchTcp(testServer(), 'request', 1, 0);

        self::assertCount(1, $handled);
        self::assertSame('encode failed', $handled[0]->getMessage());
    }

    public function testDispatchCreatesTcpClient(): void
    {
        $parser = new CapturingParser();

        $this->transportDispatcher(SocketType::TCP, $parser)->dispatchTcp(testServer(), 'request', 12, 3);

        self::assertInstanceOf(Tcp::class, $parser->decodedClient);
        self::assertNotInstanceOf(WebSocket::class, $parser->decodedClient);
        self::assertSame(12, $parser->decodedClient->getFd());
        self::assertSame(3, $parser->decodedClient->getReactorId());
        self::assertSame($parser->decodedClient, $parser->encodedClient);
    }

    public function testDispatchCreatesUdpClient(): void
    {
        $parser = new CapturingParser();

        $this->transportDispatcher(SocketType::UDP, $parser)->dispatchUdp(
            testServer(),
            'request',
            7,
            '127.0.0.1',
            9501
        );

        self::assertInstanceOf(Udp::class, $parser->decodedClient);
        self::assertSame(7, $parser->decodedClient->getServerSocket());
        self::assertSame('127.0.0.1', $parser->decodedClient->getAddress());
        self::assertSame(9501, $parser->decodedClient->getPort());
        self::assertSame($parser->decodedClient, $parser->encodedClient);
    }

    public function testDispatchCreatesWebSocketClient(): void
    {
        $frame = new Frame();
        $frame->fd = 15;
        $frame->data = 'message';
        $frame->opcode = WEBSOCKET_OPCODE_TEXT;
        $frame->finish = true;
        $parser = new CapturingParser();

        $this->transportDispatcher(SocketType::WEB_SOCKET, $parser)
            ->dispatchWebSocket(new RecordingServer(), $frame);

        self::assertInstanceOf(WebSocket::class, $parser->decodedClient);
        self::assertSame(15, $parser->decodedClient->getFd());
        self::assertSame('message', $parser->decodedClient->getData());
        self::assertSame(WEBSOCKET_OPCODE_TEXT, $parser->decodedClient->getOpCode());
        self::assertTrue($parser->decodedClient->getIsFinish());
        self::assertSame($parser->decodedClient, $parser->encodedClient);
    }

    public function testDispatchRejectsSocketTypeMismatch(): void
    {
        $dispatcher = $this->transportDispatcher(SocketType::UDP, new CapturingParser());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('dispatcher socket type must be TCP, UDP given');

        $dispatcher->dispatchTcp(testServer(), 'request', 12, 3);
    }

    public function testWebSocketDispatchRejectsPlainServer(): void
    {
        $frame = new Frame();
        $frame->fd = 16;
        $frame->data = 'message';
        $dispatcher = $this->transportDispatcher(SocketType::WEB_SOCKET, new CapturingParser());

        $this->expectException(TypeError::class);

        $dispatcher->dispatchWebSocket(testServer(), $frame);
    }

    public function testSendsEncodedTcpResponse(): void
    {
        $server = new RecordingServer();

        $this->transportDispatcher(SocketType::TCP, new ResponseParser('tcp-response'))
            ->dispatchTcp($server, 'request', 21, 4);

        self::assertSame([[21, 'tcp-response', -1]], $server->sent);
        self::assertSame([], $server->sentTo);
        self::assertSame([], $server->pushed);
    }

    public function testSendsEncodedUdpResponse(): void
    {
        $server = new RecordingServer();

        $this->transportDispatcher(SocketType::UDP, new ResponseParser('udp-response'))
            ->dispatchUdp($server, 'request', 9, '127.0.0.1', 9601);

        self::assertSame([['127.0.0.1', 9601, 'udp-response', 9]], $server->sentTo);
        self::assertSame([], $server->sent);
        self::assertSame([], $server->pushed);
    }

    public function testPushesEncodedWebSocketResponse(): void
    {
        $frame = new Frame();
        $frame->fd = 22;
        $frame->data = 'request';
        $frame->opcode = WEBSOCKET_OPCODE_TEXT;
        $frame->finish = true;
        $server = new RecordingServer();

        $this->transportDispatcher(SocketType::WEB_SOCKET, new ResponseParser('ws-response'))
            ->dispatchWebSocket($server, $frame);

        self::assertSame([
            [22, 'ws-response', WEBSOCKET_OPCODE_TEXT, SWOOLE_WEBSOCKET_FLAG_FIN],
        ], $server->pushed);
        self::assertSame([], $server->sent);
        self::assertSame([], $server->sentTo);
    }

    public function testWithoutResponseSkipsEncodingAndSending(): void
    {
        $parser = new WithoutResponseParser();
        $server = new RecordingServer();

        $this->transportDispatcher(SocketType::TCP, $parser)
            ->dispatchTcp($server, 'request', 23, 5);

        self::assertSame(0, $parser->encodeCalls);
        self::assertSame([], $server->sent);
        self::assertSame([], $server->sentTo);
        self::assertSame([], $server->pushed);
    }

    public function testTcpCloseWithoutResponse(): void
    {
        $parser = new CloseResponseParser([
            'status' => ResponseStatus::CLOSE,
            'reset' => true,
        ]);
        $server = new RecordingServer();

        $this->transportDispatcher(SocketType::TCP, $parser)
            ->dispatchTcp($server, 'request', 24, 5);

        self::assertSame(0, $parser->encodeCalls);
        self::assertSame([], $server->sent);
        self::assertSame([[24, true]], $server->closed);
    }

    public function testTcpResponseAndClose(): void
    {
        $parser = new CloseResponseParser([
            'status' => ResponseStatus::RESPONSE_AND_CLOSE,
        ]);
        $server = new RecordingServer();

        $this->transportDispatcher(SocketType::TCP, $parser)
            ->dispatchTcp($server, 'request', 25, 5);

        self::assertSame(1, $parser->encodeCalls);
        self::assertSame([[25, 'encoded-response', -1]], $server->sent);
        self::assertSame([[25, false]], $server->closed);
    }

    public function testWebSocketCloseWithoutResponse(): void
    {
        $parser = new CloseResponseParser([
            'status' => ResponseStatus::CLOSE,
            'code' => SWOOLE_WEBSOCKET_CLOSE_GOING_AWAY,
            'reason' => 'server shutdown',
        ]);
        $server = new RecordingServer();
        $frame = new Frame();
        $frame->fd = 26;
        $frame->data = 'request';

        $this->transportDispatcher(SocketType::WEB_SOCKET, $parser)
            ->dispatchWebSocket($server, $frame);

        self::assertSame(0, $parser->encodeCalls);
        self::assertSame([], $server->pushed);
        self::assertSame([
            [26, SWOOLE_WEBSOCKET_CLOSE_GOING_AWAY, 'server shutdown'],
        ], $server->disconnected);
    }

    public function testWebSocketResponseAndClose(): void
    {
        $parser = new CloseResponseParser([
            'status' => ResponseStatus::RESPONSE_AND_CLOSE,
            'reason' => 'complete',
        ]);
        $server = new RecordingServer();
        $frame = new Frame();
        $frame->fd = 27;
        $frame->data = 'request';

        $this->transportDispatcher(SocketType::WEB_SOCKET, $parser)
            ->dispatchWebSocket($server, $frame);

        self::assertSame(1, $parser->encodeCalls);
        self::assertSame([
            [27, 'encoded-response', WEBSOCKET_OPCODE_TEXT, SWOOLE_WEBSOCKET_FLAG_FIN],
        ], $server->pushed);
        self::assertSame([
            [27, SWOOLE_WEBSOCKET_CLOSE_NORMAL, 'complete'],
        ], $server->disconnected);
    }

    public function testUdpCloseIsNoOp(): void
    {
        $parser = new CloseResponseParser([
            'status' => ResponseStatus::CLOSE,
        ]);
        $server = new RecordingServer();

        $this->transportDispatcher(SocketType::UDP, $parser)
            ->dispatchUdp($server, 'request', 14, '127.0.0.1', 9901);

        self::assertSame(0, $parser->encodeCalls);
        self::assertSame([], $server->sentTo);
        self::assertSame([], $server->closed);
        self::assertSame([], $server->disconnected);
    }

    public function testUdpResponseAndCloseOnlySendsResponse(): void
    {
        $parser = new CloseResponseParser([
            'status' => ResponseStatus::RESPONSE_AND_CLOSE,
        ]);
        $server = new RecordingServer();

        $this->transportDispatcher(SocketType::UDP, $parser)
            ->dispatchUdp($server, 'request', 15, '127.0.0.1', 9902);

        self::assertSame(1, $parser->encodeCalls);
        self::assertSame([
            ['127.0.0.1', 9902, 'encoded-response', 15],
        ], $server->sentTo);
        self::assertSame([], $server->closed);
        self::assertSame([], $server->disconnected);
    }

    private function dispatcher(Caller $caller, bool $failEncode, array &$handled): Dispatcher
    {
        $config = new Config(SocketType::TCP, new DispatcherTestParser($caller, $failEncode));
        $config->setOnExceptionHandler(
            static function (Server $server, \Throwable $throwable) use (&$handled): void {
                $handled[] = $throwable;
            }
        );

        return new Dispatcher($config);
    }

    private function transportDispatcher(SocketType $type, ParserInterface $parser): Dispatcher
    {
        $config = new Config($type, $parser);

        return new Dispatcher($config);
    }
}
