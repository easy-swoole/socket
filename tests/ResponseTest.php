<?php

declare(strict_types=1);

use EasySwoole\Socket\Bean\Response;
use EasySwoole\Socket\Enum\ResponseStatus;
use PHPUnit\Framework\TestCase;

final class ResponseTest extends TestCase
{
    public function testStatusDefaultsToOk(): void
    {
        self::assertSame(ResponseStatus::OK, (new Response())->getStatus());
    }

    public function testStatusCanBeChanged(): void
    {
        $response = new Response();
        $response->setStatus(ResponseStatus::RESPONSE_AND_CLOSE);

        self::assertSame(ResponseStatus::RESPONSE_AND_CLOSE, $response->getStatus());
    }

    public function testStatusCanDisableResponse(): void
    {
        $response = new Response();
        $response->setStatus(ResponseStatus::WITHOUT_RESPONSE);

        self::assertSame(ResponseStatus::WITHOUT_RESPONSE, $response->getStatus());
    }

    public function testWebSocketCloseReasonDefaultsToEmptyString(): void
    {
        self::assertSame('', (new Response())->getReason());
    }

    public function testWebSocketCloseReasonCanBeSet(): void
    {
        $response = new Response();
        $response->setReason('normal closure');

        self::assertSame('normal closure', $response->getReason());
    }

    public function testWebSocketFlagsDefaultToFinalFrame(): void
    {
        self::assertSame(SWOOLE_WEBSOCKET_FLAG_FIN, (new Response())->getFlags());
    }

    public function testWebSocketFlagsCanBeSet(): void
    {
        $response = new Response();
        $flags = SWOOLE_WEBSOCKET_FLAG_FIN | SWOOLE_WEBSOCKET_FLAG_COMPRESS;
        $response->setFlags($flags);

        self::assertSame($flags, $response->getFlags());
    }

    public function testWebSocketOpCodeCanBeSet(): void
    {
        $response = new Response();
        $response->setOpCode(WEBSOCKET_OPCODE_BINARY);

        self::assertSame(WEBSOCKET_OPCODE_BINARY, $response->getOpCode());
    }

    public function testWebSocketRejectsInvalidOpCode(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid WebSocket opcode: 3');

        (new Response())->setOpCode(3);
    }

    public function testWebSocketFlagsCanBeEmptyForFragmentedFrame(): void
    {
        $response = new Response();
        $response->setFlags(0);

        self::assertSame(0, $response->getFlags());
    }

    public function testWebSocketRejectsUnsupportedFlags(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid WebSocket flags: 64');

        (new Response())->setFlags(64);
    }

    public function testWebSocketCloseCodeCanBeSet(): void
    {
        $response = new Response();
        $response->setCode(4000);

        self::assertSame(4000, $response->getCode());
    }

    public function testWebSocketRejectsOutOfRangeCloseCode(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid WebSocket close code: 999');

        (new Response())->setCode(999);
    }

    public function testWebSocketRejectsReservedCloseCode(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid WebSocket close code: 1005');

        (new Response())->setCode(1005);
    }
}
