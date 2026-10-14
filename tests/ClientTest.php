<?php

declare(strict_types=1);

use EasySwoole\Socket\Client\Tcp;
use EasySwoole\Socket\Client\Udp;
use EasySwoole\Socket\Client\WebSocket;
use PHPUnit\Framework\TestCase;
use Swoole\WebSocket\Frame;

final class ClientTest extends TestCase
{
    public function testTcpStoresAndUpdatesConnectionInformation(): void
    {
        $client = new Tcp(10, 2);

        self::assertSame(10, $client->getFd());
        self::assertSame(2, $client->getReactorId());

        $client->setFd(11);
        $client->setReactorId(3);

        self::assertSame(11, $client->getFd());
        self::assertSame(3, $client->getReactorId());
    }

    public function testUdpStoresAndUpdatesPeerInformation(): void
    {
        $client = new Udp(7, '127.0.0.1', 9501);

        self::assertSame(7, $client->getServerSocket());
        self::assertSame('127.0.0.1', $client->getAddress());
        self::assertSame(9501, $client->getPort());

        $client->setServerSocket(8);
        $client->setAddress('127.0.0.2');
        $client->setPort(9502);

        self::assertSame(8, $client->getServerSocket());
        self::assertSame('127.0.0.2', $client->getAddress());
        self::assertSame(9502, $client->getPort());
    }

    public function testWebSocketReadsFrameInformation(): void
    {
        $frame = new Frame();
        $frame->fd = 12;
        $frame->data = 'message';
        $frame->opcode = WEBSOCKET_OPCODE_BINARY;
        $frame->finish = true;

        $client = new WebSocket($frame);

        self::assertSame(12, $client->getFd());
        self::assertSame('message', $client->getData());
        self::assertSame(WEBSOCKET_OPCODE_BINARY, $client->getOpCode());
        self::assertTrue($client->getIsFinish());
    }

    public function testWebSocketWithoutFrameHasInitializedNullableProperties(): void
    {
        $client = new WebSocket();

        self::assertNull($client->getFd());
        self::assertNull($client->getReactorId());
        self::assertNull($client->getData());
        self::assertNull($client->getOpCode());
        self::assertNull($client->getIsFinish());
    }
}
