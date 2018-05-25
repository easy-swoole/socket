<?php
/**
 * Created by PhpStorm.
 * User: yf
 * Date: 2018/5/25
 * Time: 下午4:12
 */

namespace EasySwoole\Socket\AbstractInterface;


interface ParserInterface
{
    /*
     * 若返回EasySwoole\Socket\Common\CommandBean，则为解析成功，
     * 若返回NULL，则调用parser error 回调
     */
    public static function decode($raw,$client);

    /*
     * $raw为控制器中响应的明文
     */
    public static function encode(string $raw,$client):?string ;
}