<?php
/**
 * Created by PhpStorm.
 * User: yf
 * Date: 2018/5/25
 * Time: 下午3:23
 */

namespace EasySwoole\Socket\Client;


class Udp
{
    private int $server_socket = -1;
    private string $address;
    private int $port;

    public function __construct(int $serSock, string $address, int $port)
    {
        $this->server_socket = $serSock;
        $this->address = $address;
        $this->port = $port;
    }

    public function getServerSocket(): int
    {
        return $this->server_socket;
    }

    public function setServerSocket(int $server_socket): void
    {
        $this->server_socket = $server_socket;
    }

    public function getAddress(): string
    {
        return $this->address;
    }

    public function setAddress(string $address): void
    {
        $this->address = $address;
    }

    public function getPort(): int
    {
        return $this->port;
    }

    public function setPort(int $port): void
    {
        $this->port = $port;
    }
}
