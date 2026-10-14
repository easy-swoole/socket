# EasySwoole Socket

基于 Swoole Server 的 TCP、UDP 和 WebSocket 请求分发组件。它负责把原始数据解析为控制器调用、执行控制器 action，并按照响应状态完成编码、发送或关闭连接。

## 环境要求

- PHP 8.1 或更高版本
- Swoole 扩展
- Composer

安装：

```bash
composer require easyswoole/socket
```

## 核心流程

一次请求依次经过：

1. Dispatcher 根据强类型入口创建 TCP、UDP 或 WebSocket 客户端信息对象。
2. Parser 的 `decode()` 把原始数据转换为 `Caller`。
3. Dispatcher 实例化 `Caller` 指定的 Controller。
4. Controller 校验 action 白名单并执行 action。
5. Parser 的 `encode()` 把 `Response` 转换为字符串。
6. Dispatcher 根据 `ResponseStatus` 决定是否发送和关闭连接。

## Parser

Parser 负责协议解析和响应编码：

```php
<?php

use EasySwoole\Socket\AbstractInterface\ParserInterface;
use EasySwoole\Socket\Bean\Caller;
use EasySwoole\Socket\Bean\Response;
use EasySwoole\Socket\Client\Tcp;
use EasySwoole\Socket\Client\Udp;

final class JsonParser implements ParserInterface
{
    public function decode(string $raw, Tcp|Udp $client): ?Caller
    {
        $payload = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);

        $caller = new Caller();
        $caller->setControllerClass(EchoController::class);
        $caller->setAction($payload['action'] ?? null);
        $caller->setArgs($payload['args'] ?? []);

        return $caller;
    }

    public function encode(Response $response, Tcp|Udp $client): ?string
    {
        return json_encode(
            ['message' => $response->getMessage()],
            JSON_THROW_ON_ERROR
        );
    }
}
```

`decode()` 返回 `null` 时不会执行控制器。`encode()` 返回 `null` 时，本次响应不会发送数据。

WebSocket 客户端类继承自 `Tcp`，因此接口统一使用 `Tcp|Udp`。需要读取 WebSocket 帧信息时可使用 `instanceof WebSocket` 判断。

## Controller

Controller 会自动把业务控制器的 `public` 方法识别为可访问 action。需要禁止外部调用的方法应声明为 `protected` 或 `private`；`Controller` 基类方法和魔术方法不会作为 action：

```php
<?php

use EasySwoole\Socket\AbstractInterface\Controller;
use EasySwoole\Socket\Client\Tcp;
use EasySwoole\Socket\Client\WebSocket;
use EasySwoole\Socket\Enum\ResponseStatus;

final class EchoController extends Controller
{
    protected function onRequest(?string $actionName): bool
    {
        return true;
    }

    public function echo(): void
    {
        $this->response()->setMessage([
            'args' => $this->caller()->getArgs(),
            'client' => $this->clientInformation(),
        ]);
    }

    public function notify(): void
    {
        $sent = $this->responseImmediately('notification');
        if (!$sent) {
            // 由业务层决定记录、重试或忽略。
        }

        $this->response()->setStatus(ResponseStatus::WITHOUT_RESPONSE);
    }

    protected function actionNotFound(?string $actionName): void
    {
        $this->response()->setMessage(['error' => 'action not found']);
    }

    protected function afterAction(?string $actionName): void
    {
        // action 成功、失败或被拦截后都会执行。
    }

    private function clientInformation(): array
    {
        $client = $this->caller()->getClient();

        if ($client instanceof WebSocket) {
            return [
                'type' => 'websocket',
                'fd' => $client->getFd(),
                'data' => $client->getData(),
                'opcode' => $client->getOpCode(),
                'finish' => $client->getIsFinish(),
            ];
        }

        if ($client instanceof Tcp) {
            return [
                'type' => 'tcp',
                'fd' => $client->getFd(),
                'reactorId' => $client->getReactorId(),
            ];
        }

        return [
            'type' => 'udp',
            'serverSocket' => $client->getServerSocket(),
            'address' => $client->getAddress(),
            'port' => $client->getPort(),
        ];
    }
}
```

`responseImmediately()` 直接返回 Swoole `send()`、`sendto()` 或 `push()` 的布尔结果。它不会检查连接是否存在；连接状态、失败处理和重试策略由控制器负责。

## Config

Config 创建时必须同时提供 Socket 类型和 Parser：

```php
use EasySwoole\Socket\Config;
use EasySwoole\Socket\Enum\SocketType;

$config = new Config(SocketType::TCP, new JsonParser());
```

### 异常处理器

异常处理器必须是 `callable`。未配置时，Dispatcher 会关闭 TCP/WebSocket 连接并重新抛出异常；UDP 没有连接关闭操作。

```php
use EasySwoole\Socket\Bean\Response;
use EasySwoole\Socket\Client\Tcp;
use EasySwoole\Socket\Client\Udp;
use EasySwoole\Socket\Enum\ResponseStatus;
use Swoole\Server;

$config->setOnExceptionHandler(
    function (
        Server $server,
        Throwable $throwable,
        string $raw,
        Tcp|Udp $client,
        Response $response
    ): void {
        $response->setMessage(['error' => $throwable->getMessage()]);
        $response->setStatus(ResponseStatus::RESPONSE_AND_CLOSE);
    }
);
```

传入 `null` 可移除异常处理器。

## Response 状态

`ResponseStatus` 提供四种状态：

| 状态 | 编码并发送 | TCP/WebSocket 关闭 | UDP 行为 |
| --- | --- | --- | --- |
| `OK` | 是 | 否 | 发送响应 |
| `WITHOUT_RESPONSE` | 否 | 否 | 不发送 |
| `RESPONSE_AND_CLOSE` | 是 | 是 | 仅发送响应 |
| `CLOSE` | 否 | 是 | 不发送 |

```php
$response->setStatus(ResponseStatus::WITHOUT_RESPONSE);
```

TCP 重置关闭：

```php
$response->setReset(true);
$response->setStatus(ResponseStatus::CLOSE);
```

WebSocket 关闭参数：

```php
$response->setCode(SWOOLE_WEBSOCKET_CLOSE_NORMAL);
$response->setReason('normal closure');
$response->setStatus(ResponseStatus::RESPONSE_AND_CLOSE);
```

WebSocket 推送使用当前 Swoole flags 语义：

```php
$response->setOpCode(WEBSOCKET_OPCODE_BINARY);
$response->setFlags(
    SWOOLE_WEBSOCKET_FLAG_FIN | SWOOLE_WEBSOCKET_FLAG_COMPRESS
);
```

## TCP 服务

```php
<?php

use EasySwoole\Socket\Config;
use EasySwoole\Socket\Dispatcher;
use EasySwoole\Socket\Enum\SocketType;
use Swoole\Server;

$server = new Server('127.0.0.1', 9501);
$server->set(['worker_num' => 1]);

$dispatcher = new Dispatcher(
    new Config(SocketType::TCP, new JsonParser())
);

$server->on(
    'receive',
    function (Server $server, int $fd, int $reactorId, string $data) use ($dispatcher): void {
        $dispatcher->dispatchTcp($server, $data, $fd, $reactorId);
    }
);

$server->start();
```

### TCP 粘包与拆包

TCP 是字节流协议，本身没有消息边界。一次 `receive` 可能只包含半个业务包，也可能同时包含多个业务包。项目不会替业务协议自动决定如何拆包，服务端必须根据实际协议选择固定长度、结束符或长度字段等方案。

例如，可以使用“4 字节网络字节序长度头 + 包体”的规则：

```php
$server->set([
    'worker_num' => 1,
    'open_length_check' => true,
    'package_length_type' => 'N',
    'package_length_offset' => 0,
    'package_body_offset' => 4,
    'package_max_length' => 4 * 1024 * 1024,
]);
```

各配置项含义：

- `open_length_check`：启用长度协议拆包。
- `package_length_type`：使用 `N` 读取 4 字节网络字节序无符号整数。
- `package_length_offset`：长度字段从数据第 0 字节开始。
- `package_body_offset`：包体从第 4 字节开始。
- `package_max_length`：限制完整包的最大长度，防止异常长度占用过多内存。

该配置与项目的 `Protocol` 工具保持一致：

```php
use EasySwoole\Socket\Tools\Protocol;

$packet = Protocol::pack('payload');
$payload = Protocol::unpack($packet);
```

启用上述服务端配置后，传入 Parser 的 TCP 原始数据包含 4 字节长度头。Parser 应先解包，再解析业务数据：

```php
use EasySwoole\Socket\Bean\Caller;
use EasySwoole\Socket\Client\Tcp;
use EasySwoole\Socket\Client\Udp;
use EasySwoole\Socket\Client\WebSocket;
use EasySwoole\Socket\Tools\Protocol;

public function decode(string $raw, Tcp|Udp $client): ?Caller
{
    if ($client instanceof Tcp && !$client instanceof WebSocket) {
        $raw = Protocol::unpack($raw);
    }

    $payload = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
    // 根据业务协议创建 Caller。
}
```

服务端响应也必须使用相同的长度协议。对应 Parser 的 `encode()` 可以这样实现：

```php
public function encode(Response $response, Tcp|Udp $client): ?string
{
    $body = json_encode(
        ['message' => $response->getMessage()],
        JSON_THROW_ON_ERROR
    );

    return Protocol::pack($body);
}
```

客户端发送和接收示例：

```php
use EasySwoole\Socket\Tools\Client;
use Swoole\Coroutine;

Coroutine\run(function (): void {
    $client = new Client(
        unixSock: '127.0.0.1',
        port: 9501,
        timeout: 3.0,
        maxPackageLength: 4 * 1024 * 1024
    );

    // send() 会自动使用 Protocol::pack() 添加 4 字节长度头。
    $sentBytes = $client->send(
        json_encode([
            'action' => 'echo',
            'args' => ['message' => 'hello'],
        ], JSON_THROW_ON_ERROR),
        safeSend: true,
        timeout: 3.0
    );

    if ($sentBytes === false) {
        throw new RuntimeException('send failed');
    }

    // recv() 会等待完整数据包并自动移除 4 字节长度头。
    $rawResponse = $client->recv(timeout: 3.0);
    if ($rawResponse !== null) {
        $response = json_decode($rawResponse, true, flags: JSON_THROW_ON_ERROR);
        var_dump($response);
    }

    $client->close();
});
```

客户端发送时也必须使用相同的封包规则。`Tools\Client::send()` 已自动调用 `Protocol::pack()`；其他客户端需要自行封包。`safeSend` 默认为 `false`，此时只调用一次底层 `send()`；设置为 `true` 时会循环发送，直到完整数据包写入、发送失败或超过整体超时时间。安全发送默认超时为 3 秒，可通过 `timeout` 参数调整；该参数在 `safeSend=false` 时不生效。大包或需要确保完整写入的场景建议启用安全发送。若安全发送在部分数据已经写入后失败或超时，不应直接重发整个业务请求，应先按业务协议确认服务端是否已经收到数据，避免重复处理。若业务使用结束符或其他自定义协议，应改用对应的 Swoole 配置和 Parser 实现，不要同时启用不匹配的拆包规则。

## UDP 服务

```php
<?php

use EasySwoole\Socket\Config;
use EasySwoole\Socket\Dispatcher;
use EasySwoole\Socket\Enum\SocketType;
use Swoole\Server;

$server = new Server('127.0.0.1', 9502, SWOOLE_PROCESS, SWOOLE_SOCK_UDP);
$dispatcher = new Dispatcher(
    new Config(SocketType::UDP, new JsonParser())
);

$server->on(
    'packet',
    function (Server $server, string $data, array $clientInfo) use ($dispatcher): void {
        $dispatcher->dispatchUdp(
            $server,
            $data,
            $clientInfo['server_socket'],
            $clientInfo['address'],
            $clientInfo['port']
        );
    }
);

$server->start();
```

## WebSocket 服务

```php
<?php

use EasySwoole\Socket\Config;
use EasySwoole\Socket\Dispatcher;
use EasySwoole\Socket\Enum\SocketType;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server;

$server = new Server('127.0.0.1', 9503);
$dispatcher = new Dispatcher(
    new Config(SocketType::WEB_SOCKET, new JsonParser())
);

$server->on(
    'message',
    function (Server $server, Frame $frame) use ($dispatcher): void {
        $dispatcher->dispatchWebSocket($server, $frame);
    }
);

$server->start();
```

## 长度协议工具

`Protocol` 使用 4 字节网络字节序无符号整数作为包体长度：

```text
+--------------------+-------------------+
| 4-byte body length | body              |
+--------------------+-------------------+
```

```php
use EasySwoole\Socket\Tools\Protocol;

$packet = Protocol::pack('payload');
$payload = Protocol::unpack($packet);
```

默认最大包体为 1 MiB。`socketReader()` 可以为单次读取指定上限：

```php
$payload = Protocol::socketReader(
    $socket,
    timeout: 3.0,
    maxPackageLength: 4 * 1024 * 1024
);
```

方法会拒绝短协议头、长度不匹配和超过上限的数据。

## 协程客户端

`Tools\Client` 同时支持 Unix Socket 和 TCP，并自动使用上述长度协议。

Unix Socket：

```php
use EasySwoole\Socket\Tools\Client;
use Swoole\Coroutine;

Coroutine\run(function (): void {
    $client = new Client('/tmp/example.sock');
    $client->send('request');
    $response = $client->recv();
    $client->close();
});
```

TCP：

```php
use EasySwoole\Socket\Tools\Client;
use Swoole\Coroutine;

Coroutine\run(function (): void {
    $client = new Client(
        unixSock: '127.0.0.1',
        port: 9501,
        timeout: 3.0,
        maxPackageLength: 4 * 1024 * 1024
    );
});
```

连接失败时构造函数会抛出 `RuntimeException`，其中包含 Swoole 错误码和错误信息。

## 测试

```bash
composer install
composer test
```

测试覆盖 TCP、UDP、WebSocket 客户端信息、请求分发、响应发送、关闭路径、异常处理、协议边界和协程客户端连接失败。
