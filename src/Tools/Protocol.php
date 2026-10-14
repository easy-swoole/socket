<?php


namespace EasySwoole\Socket\Tools;


use Swoole\Coroutine\Socket;

class Protocol
{
    public const MAX_PACKAGE_LENGTH = 1024 * 1024;

    public static function pack(string $data): string
    {
        return pack('N', strlen($data)).$data;
    }

    public static function packDataLength(string $head): int
    {
        if (strlen($head) !== 4) {
            throw new \InvalidArgumentException('package header must be exactly 4 bytes');
        }

        return unpack('N', $head)[1];
    }

    public static function unpack(string $data): string
    {
        if (strlen($data) < 4) {
            throw new \InvalidArgumentException('package data must contain a 4-byte header');
        }

        $bodyLength = self::packDataLength(substr($data, 0, 4));
        $body = substr($data, 4);
        if (strlen($body) !== $bodyLength) {
            throw new \LengthException(sprintf(
                'package body length %d does not match declared length %d',
                strlen($body),
                $bodyLength
            ));
        }

        return $body;
    }

    public static function socketReader(
        Socket $socket,
        float $timeout = 3.0,
        int $maxPackageLength = self::MAX_PACKAGE_LENGTH
    ): ?string
    {
        if (!is_finite($timeout) || $timeout <= 0) {
            throw new \InvalidArgumentException('socket read timeout must be greater than zero');
        }
        if ($maxPackageLength <= 0) {
            throw new \InvalidArgumentException('maximum package length must be greater than zero');
        }

        $deadline = hrtime(true) / 1_000_000_000 + $timeout;
        $header = $socket->recvAll(4,$timeout);
        if ($header === false || $header === '') {
            return null;
        }
        if (strlen($header) !== 4) {
            throw new \UnexpectedValueException('incomplete package header');
        }

        $allLength = self::packDataLength($header);
        if ($allLength > $maxPackageLength) {
            throw new \LengthException(sprintf(
                'package length %d exceeds the configured maximum of %d bytes',
                $allLength,
                $maxPackageLength
            ));
        }
        if ($allLength === 0) {
            return '';
        }

        $remainingTimeout = $deadline - hrtime(true) / 1_000_000_000;
        if ($remainingTimeout <= 0) {
            return null;
        }

        $data = $socket->recvAll($allLength,$remainingTimeout);
        if ($data === false || $data === '') {
            return null;
        }
        if (strlen($data) !== $allLength) {
            throw new \UnexpectedValueException(sprintf(
                'incomplete package body: expected %d bytes, received %d',
                $allLength,
                strlen($data)
            ));
        }

        return $data;
    }

    public static function socketWriter(Socket $socket, string $rawData, float $timeout = 3.0): int|false
    {
        return $socket->sendAll(self::pack($rawData),$timeout);
    }
}
