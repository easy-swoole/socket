<?php


namespace EasySwoole\Socket\Tools;


use Swoole\Coroutine\Socket;

class Protocol
{
    const SETTING = [
        'open_length_check' => true,
        'package_length_type'   => 'N',
        'package_length_offset' => 0,
        'package_body_offset'   => 4,
    ];
    public static function pack(string $data): string
    {
        return pack('N', strlen($data)).$data;
    }

    public static function packDataLength(string $head): int
    {
        return unpack('N', $head)[1];
    }

    public static function unpack(string $data):string
    {
        return substr($data,4);
    }

    public static function socketReader(Socket $socket,float $timeout = 3.0,bool $autoClose = true):?string
    {
        $ret = null;
        $header = $socket->recvAll(4,$timeout);
        if(strlen($header) == 4){
            $allLength = self::packDataLength($header);
            $data = $socket->recvAll($allLength,$timeout);
            if(strlen($data) == $allLength){
                $ret = $data;
            }
        }
        if($autoClose){
            $socket->close();
        }
        return $ret;
    }

    public static function unixSocketSendAndRecv(string $socketFile,$data, float $timeout = 3.0)
    {
        $client = new Client($socketFile);
        $client->send(serialize($data));
        $ret = $client->recv($timeout);
        $client->close();
        if (empty($ret)) {
            return null;
        }
        $data = unserialize($ret);
        return $data;
    }
}
