<?php

declare(strict_types=1);

use EasySwoole\Socket\Tools\Client;
use EasySwoole\Socket\Tools\Protocol;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine\Socket;

final class ProtocolTest extends TestCase
{
    public function testPackAndUnpackRoundTrip(): void
    {
        $payload = 'payload';
        $packet = Protocol::pack($payload);

        self::assertSame(strlen($payload), Protocol::packDataLength(substr($packet, 0, 4)));
        self::assertSame($payload, Protocol::unpack($packet));
    }

    public function testPackDataLengthRejectsShortHeader(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('package header must be exactly 4 bytes');

        Protocol::packDataLength("\0\0\0");
    }

    public function testUnpackRejectsMissingHeader(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('package data must contain a 4-byte header');

        Protocol::unpack('abc');
    }

    public function testUnpackRejectsBodyLengthMismatch(): void
    {
        $this->expectException(LengthException::class);
        $this->expectExceptionMessage('package body length 3 does not match declared length 4');

        Protocol::unpack(pack('N', 4).'abc');
    }

    public function testUnpackAcceptsEmptyBody(): void
    {
        self::assertSame('', Protocol::unpack(Protocol::pack('')));
    }

    public function testSocketReaderRejectsInvalidMaximumLength(): void
    {
        $socket = new Socket(AF_INET, SOCK_STREAM, 0);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('maximum package length must be greater than zero');

        try {
            Protocol::socketReader($socket, 3.0, 0);
        } finally {
            $socket->close();
        }
    }

    public function testSocketReaderRejectsInvalidTimeout(): void
    {
        $socket = new Socket(AF_INET, SOCK_STREAM, 0);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('socket read timeout must be greater than zero');

        try {
            Protocol::socketReader($socket, 0);
        } finally {
            $socket->close();
        }
    }

    public function testSocketReaderUsesOneTimeoutForHeaderAndBody(): void
    {
        $result = 'not-run';
        $elapsed = 0.0;

        \Swoole\Coroutine\run(function () use (&$result, &$elapsed): void {
            $server = new Socket(AF_INET, SOCK_STREAM, 0);
            self::assertTrue($server->bind('127.0.0.1', 0));
            self::assertTrue($server->listen());
            $address = $server->getsockname();
            self::assertIsArray($address);

            \Swoole\Coroutine::create(static function () use ($server): void {
                $connection = $server->accept(1.0);
                self::assertInstanceOf(Socket::class, $connection);
                \Swoole\Coroutine::sleep(0.04);
                $connection->sendAll(pack('N', 4));
                \Swoole\Coroutine::sleep(0.04);
                $connection->sendAll('body');
                $connection->close();
            });

            $client = new Socket(AF_INET, SOCK_STREAM, 0);
            try {
                self::assertTrue($client->connect('127.0.0.1', $address['port'], 1.0));
                $startedAt = hrtime(true);
                $result = Protocol::socketReader($client, 0.06);
                $elapsed = (hrtime(true) - $startedAt) / 1_000_000_000;
            } finally {
                $client->close();
                $server->close();
            }
        });

        self::assertNull($result);
        self::assertLessThan(0.075, $elapsed);
    }

    public function testSocketReaderReturnsNullWhenReceiveFails(): void
    {
        $result = 'not-run';

        \Swoole\Coroutine\run(function () use (&$result): void {
            $socket = new Socket(AF_INET, SOCK_STREAM, 0);
            try {
                $result = Protocol::socketReader($socket, 0.01);
            } finally {
                $socket->close();
            }
        });

        self::assertNull($result);
    }

    public function testClientRejectsInvalidMaximumLength(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('maximum package length must be greater than zero');

        new Client('/tmp/socket-test.sock', null, 3.0, 0);
    }

    public function testSafeSendIsOptionalAndDisabledByDefault(): void
    {
        $parameters = (new ReflectionMethod(Client::class, 'send'))->getParameters();
        $safeSend = $parameters[1];
        $timeout = $parameters[2];

        self::assertSame('safeSend', $safeSend->getName());
        self::assertSame('bool', (string) $safeSend->getType());
        self::assertTrue($safeSend->isDefaultValueAvailable());
        self::assertFalse($safeSend->getDefaultValue());
        self::assertSame('timeout', $timeout->getName());
        self::assertSame('float', (string) $timeout->getType());
        self::assertSame(3.0, $timeout->getDefaultValue());
    }

    public function testSafeSendRejectsInvalidTimeout(): void
    {
        $client = (new ReflectionClass(Client::class))->newInstanceWithoutConstructor();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('safe send timeout must be greater than zero');

        $client->send('payload', true, 0);
    }

    public function testClientThrowsWhenConnectionFails(): void
    {
        \Swoole\Coroutine\run(function (): void {
            $path = sprintf('/tmp/easyswoole-socket-missing-%d.sock', getmypid());

            try {
                new Client($path, null, 0.01);
                self::fail('connection failure did not throw an exception');
            } catch (\RuntimeException $exception) {
                self::assertStringStartsWith('connection failed: [', $exception->getMessage());
            }
        });
    }
}
