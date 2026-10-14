<?php


namespace EasySwoole\Socket\Tools;
use Swoole\Coroutine\Client as SwooleClient;

class Client
{
    private SwooleClient $client;
    private int $maxPackageLength;

    public function __construct(
        string $unixSock,
        ?int $port = null,
        float $timeout = 3,
        int $maxPackageLength = Protocol::MAX_PACKAGE_LENGTH
    )
    {
        if ($maxPackageLength <= 0) {
            throw new \InvalidArgumentException('maximum package length must be greater than zero');
        }

        $this->maxPackageLength = $maxPackageLength;
        if($port === null){
            $port = 0;
        }
        if($port > 0){
            $this->client = new SwooleClient(SWOOLE_SOCK_TCP);
        }else{
            $this->client = new SwooleClient(SWOOLE_UNIX_STREAM);
        }

        $this->client->set([
            'open_length_check' => true,
            'package_length_type' => 'N',
            'package_length_offset' => 0,
            'package_body_offset' => 4,
            'package_max_length' => $this->maxPackageLength,
        ]);

        if (!$this->client->connect($unixSock, $port, $timeout)) {
            throw new \RuntimeException(sprintf(
                'connection failed: [%d] %s',
                $this->client->errCode,
                $this->client->errMsg
            ));
        }
    }

    public function client(): SwooleClient
    {
        return $this->client;
    }

    public function getMaxPackageLength(): int
    {
        return $this->maxPackageLength;
    }

    public function close(): void
    {
        if(isset($this->client) && $this->client->isConnected()){
            $this->client->close();
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    public function send(string $rawData, bool $safeSend = false, float $timeout = 3.0): int|false
    {
        if ($safeSend && (!is_finite($timeout) || $timeout <= 0)) {
            throw new \InvalidArgumentException('safe send timeout must be greater than zero');
        }

        if(!$this->client->isConnected()){
            return false;
        }

        $packet = Protocol::pack($rawData);
        if (!$safeSend) {
            return $this->client->send($packet);
        }

        $packetLength = strlen($packet);
        $sentLength = 0;
        $deadline = hrtime(true) / 1_000_000_000 + $timeout;
        while ($sentLength < $packetLength) {
            $remainingTimeout = $deadline - hrtime(true) / 1_000_000_000;
            if ($remainingTimeout <= 0) {
                return false;
            }

            $sent = $this->client->send(substr($packet, $sentLength), $remainingTimeout);
            if ($sent === false || $sent === 0) {
                return false;
            }
            $sentLength += $sent;
        }

        return $sentLength;
    }

    public function recv(float $timeout = 0.1): ?string
    {
        if($this->client->isConnected()){
            $ret = $this->client->recv($timeout);
            if(!empty($ret)){
                return Protocol::unpack($ret);
            }else{
                return null;
            }
        }else{
            return null;
        }
    }
}
