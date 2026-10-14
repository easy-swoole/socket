<?php
/**
 * Created by PhpStorm.
 * User: yf
 * Date: 2018/8/10
 * Time: 上午11:06
 */

namespace EasySwoole\Socket\Bean;


use EasySwoole\Socket\Enum\ResponseStatus;
use EasySwoole\Spl\SplBean;
use InvalidArgumentException;

class Response extends SplBean
{
    private const WEBSOCKET_OP_CODES = [
        WEBSOCKET_OPCODE_CONTINUATION,
        WEBSOCKET_OPCODE_TEXT,
        WEBSOCKET_OPCODE_BINARY,
        WEBSOCKET_OPCODE_CLOSE,
        WEBSOCKET_OPCODE_PING,
        WEBSOCKET_OPCODE_PONG,
    ];

    private const WEBSOCKET_FLAGS_MASK = SWOOLE_WEBSOCKET_FLAG_FIN
        | SWOOLE_WEBSOCKET_FLAG_COMPRESS
        | SWOOLE_WEBSOCKET_FLAG_RSV1
        | SWOOLE_WEBSOCKET_FLAG_RSV2
        | SWOOLE_WEBSOCKET_FLAG_RSV3
        | SWOOLE_WEBSOCKET_FLAG_MASK;

    private const INVALID_WEBSOCKET_CLOSE_CODES = [1004, 1005, 1006, 1015];

    protected ResponseStatus $status = ResponseStatus::OK;
    protected mixed $message = null;
    /*
     * 以下参数仅仅ws推送可用
     */
    protected int $opCode = WEBSOCKET_OPCODE_TEXT;
    protected int $flags = SWOOLE_WEBSOCKET_FLAG_FIN;

    /** tcp client 单独参数 */
    protected bool $reset = false;

    /**
     * ws client 单独参数
     */
    protected int $code = SWOOLE_WEBSOCKET_CLOSE_NORMAL;
    protected string $reason = '';

    public function getStatus(): ResponseStatus
    {
        return $this->status;
    }

    public function setStatus(ResponseStatus $status): void
    {
        $this->status = $status;
    }

    public function getMessage(): mixed
    {
        return $this->message;
    }

    public function setMessage(mixed $message): void
    {
        $this->message = $message;
    }

    public function getOpCode(): int
    {
        return $this->opCode;
    }

    public function setOpCode(int $opCode): void
    {
        if (!in_array($opCode, self::WEBSOCKET_OP_CODES, true)) {
            throw new InvalidArgumentException("invalid WebSocket opcode: {$opCode}");
        }

        $this->opCode = $opCode;
    }

    public function getFlags(): int
    {
        return $this->flags;
    }

    public function setFlags(int $flags): void
    {
        if ($flags < 0 || ($flags & ~self::WEBSOCKET_FLAGS_MASK) !== 0) {
            throw new InvalidArgumentException("invalid WebSocket flags: {$flags}");
        }

        $this->flags = $flags;
    }

    public function isReset(): bool
    {
        return $this->reset;
    }

    public function setReset(bool $reset): void
    {
        $this->reset = $reset;
    }

    public function getCode(): int
    {
        return $this->code;
    }

    public function setCode(int $code): void
    {
        if ($code < 1000 || $code > 4999 || in_array($code, self::INVALID_WEBSOCKET_CLOSE_CODES, true)) {
            throw new InvalidArgumentException("invalid WebSocket close code: {$code}");
        }

        $this->code = $code;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function setReason(string $reason): void
    {
        $this->reason = $reason;
    }
}
