<?php

class RedisPool
{
    /**
     * @var \Swoole\Coroutine\Channel
     */
    protected $pool;

    /**
     * RedisPool constructor.
     * @param int $size 连接池的尺寸
     */
    function __construct($size = 100)
    {
        $this->pool = new Swoole\Coroutine\Channel($size);
        for ($i = 0; $i < $size; $i++)
        {
            $redis = new Swoole\Coroutine\Redis();
            $res = $redis->connect(GUARD_DB_SERVER, GUARD_DB_PORT);
            if ( defined('GUARD_DB_AUTH') && ! empty(GUARD_DB_AUTH))
            {
                $redis->auth(GUARD_DB_AUTH);
            }
            if ($res == false)
            {
                throw new RuntimeException("failed to connect redis server.");
            }
            else
            {
                $this->put($redis);
            }
        }
    }

    function put($redis)
    {
        $this->pool->push($redis);
    }

    function get()
    {
        return $this->pool->pop();
    }
}