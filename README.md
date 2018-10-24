# socket

```php

class C extends \EasySwoole\Socket\AbstractInterface\Controller{

    protected function onRequest(?string $actionName): bool
    {
        var_dump('req');
        return true;
    }

    function test()
    {
        $this->response()->setMessage('time:'.time());
    }
}

class Parser implements \EasySwoole\Socket\AbstractInterface\ParserInterface{

    public function decode($raw, $client): ?\EasySwoole\Socket\Bean\Caller
    {
        // TODO: Implement decode() method.
        $ret =  new \EasySwoole\Socket\Bean\Caller();
        $ret->setControllerClass(C::class);
        $ret->setAction('test');
        return $ret;
    }

    public function encode(\EasySwoole\Socket\Bean\Response $response, $client): ?string
    {
        // TODO: Implement encode() method.
        return $response->__toString();
    }

}

$server = new swoole_server("127.0.0.1", 9501);

$conf = new \EasySwoole\Socket\Config();
$conf->setType($conf::TCP);
$conf->setParser(new Parser());

$dispatch = new \EasySwoole\Socket\Dispatcher($conf);
$server->on('receive', function ($server, $fd, $reactor_id, $data)use($dispatch) {
    $dispatch->dispatch($server,$data,$fd,$reactor_id);
});
$server->on('close', function ($server, $fd) {
    echo "connection close: {$fd}\n";
});
$server->start();
```