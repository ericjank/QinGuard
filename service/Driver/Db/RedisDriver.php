<?php
namespace QinGuard\Driver\Db;

class RedisDriver implements DbInterface
{
    /**
     * @var \Redis
     */
    protected $redis;


    public function __construct()
    {
        $this->redis = new \Redis();
        $this->connect();
    }

    public function ping()
    {
        try {
            if ( ! is_object($this->redis) || ! method_exists($this->redis, 'ping')) {
                throw new \RedisException('Redis connection is down!');
            }
        
            $response = $this->redis->ping();

            if ($response != '+PONG') {
                throw new \RedisException('This connection is down');
            }
     
        } catch (\RedisException $e) {
            // 重新连接
            $this->connect();
        }
    }

    public function connect()
    {
        try {
            $this->redis->connect(GUARD_DB_SERVER, GUARD_DB_PORT);

            if ( defined('GUARD_DB_AUTH') && ! empty(GUARD_DB_AUTH))
            {
                $this->redis->auth(GUARD_DB_AUTH);
            }

            // $this->redis->setDefer();
        } catch (\RedisException $e) {
            throw new \RedisException('Redis server is down');
        }
    }

    public function get($filename, $default = null, $usekey = false)
    {
        $this->ping();

        $key = $usekey ? $filename : md5($filename);
        $res = $this->redis->get($key);
        
        if ($res === false) {
            return $default;
        }

        return json_decode($res, true);
    }

    public function set($filename, $value, $ttl = null)
    {
        $this->ping();

        $key = md5($filename);
        $seconds = $ttl;
        $res = json_encode([ 'p' => $filename, 'c' => $value]);
        
        $exist = $this->redis->exists($key);

        $result = $seconds > 0 ? $this->redis->set($key, $res, $seconds) : $this->redis->set($key, $res);

        if ( $result)
        {
            if ( ! $exist)
            {
                if ( ! $this->redis->zAdd('guard:keys:lists', time(), $key))
                {
                    $this->redis->zDelete('guard:keys:lists', $key);
                    $this->redis->delete($key);
                }
                else 
                {
                    return true;
                }
            }
            else 
            {
                $this->redis->zAdd('guard:keys:lists', time(), $key);
                return true;
            }
        }

        return false;
    }

    public function delete($filename)
    {
        $this->ping();
        $key = md5($filename);

        if ($this->redis->delete($key))
        {
            if ( is_array($key))
            {
                foreach ($key as $keyItem) {
                    $this->redis->zDelete('guard:keys:lists', $keyItem);
                }
            }
            else {
                $this->redis->zDelete('guard:keys:lists', $key);
            }
        }

        return true;
    }

    public function has($filename)
    {
        $this->ping();
        $key = md5($filename);
        return (bool) $this->redis->exists($key);
    }

    public function page($page = 1, $size = 10)
    {
        $this->ping();

        $start = ($page - 1) * $size;
        $result = $this->redis->zRevRange('guard:keys:lists', $start, $start + $size);
        // $count = $this->redis->zCard('guard:key:list');

        $datas = [];

        if ( ! empty($result))
        {
            foreach ($result as $key) {
                $datas[$key] = $this->get($key, null, true);
            }
        }

        return $datas;
    }
}
