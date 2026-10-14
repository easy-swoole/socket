<?php

declare(strict_types=1);

use EasySwoole\Socket\AbstractInterface\ParserInterface;
use EasySwoole\Socket\Bean\Caller;
use EasySwoole\Socket\Bean\Response;
use EasySwoole\Socket\Client\Tcp;
use EasySwoole\Socket\Client\Udp;
use EasySwoole\Socket\Config;
use EasySwoole\Socket\Enum\SocketType;
use PHPUnit\Framework\TestCase;

final class ConfigTestParser implements ParserInterface
{
    public function decode(string $raw, Tcp|Udp $client): ?Caller
    {
        return null;
    }

    public function encode(Response $response, Tcp|Udp $client): ?string
    {
        return null;
    }
}

final class ConfigTest extends TestCase
{
    public function testParserIsRequired(): void
    {
        $this->expectException(ArgumentCountError::class);

        new Config(SocketType::TCP);
    }

    public function testStoresSocketType(): void
    {
        $config = new Config(SocketType::TCP, new ConfigTestParser());
        $config->setType(SocketType::WEB_SOCKET);

        self::assertSame(SocketType::WEB_SOCKET, $config->getType());
    }

    public function testStoresAnyCallbackRepresentationWithoutConversion(): void
    {
        $handler = [self::class, 'callbackHandler'];
        $config = new Config(SocketType::TCP, new ConfigTestParser());
        $config->setOnExceptionHandler($handler);

        self::assertSame($handler, $config->getOnExceptionHandler());
        self::assertTrue(is_callable($config->getOnExceptionHandler()));
    }

    public function testExceptionHandlerCanBeNull(): void
    {
        $config = new Config(SocketType::TCP, new ConfigTestParser());
        $config->setOnExceptionHandler(null);

        self::assertNull($config->getOnExceptionHandler());
    }

    public function testRejectsNonCallableExceptionHandler(): void
    {
        $this->expectException(TypeError::class);

        (new Config(SocketType::TCP, new ConfigTestParser()))
            ->setOnExceptionHandler('function_that_does_not_exist');
    }

    public static function callbackHandler(): void
    {
    }

    public function testStoresParserInstance(): void
    {
        $parser = new ConfigTestParser();
        $config = new Config(SocketType::TCP, $parser);

        self::assertSame($parser, $config->getParser());
    }

    public function testRejectsParserClassName(): void
    {
        $this->expectException(TypeError::class);

        new Config(SocketType::TCP, ConfigTestParser::class);
    }
}
