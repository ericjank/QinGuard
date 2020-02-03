<?php
namespace QinGuard\Driver;

use Swoole\Coroutine\Channel;

class Db
{
    static public $drivers = [];
    static public $pool;

    public static function container($cb = null)
    {
        $handle = self::pop();

        if ( $cb && is_callable($cb))
        {
            $cb($handle);
        }

        self::push($handle);
    }

    public static function pop()
    {
        $length = 0;
        $driverClass = GUARD_DB_DRIVER ? 'QinGuard\\Driver\\Db\\' . ucfirst(GUARD_DB_DRIVER) . 'Driver' : QinGuard\Driver\Db\RedisDriver::class;

        if ( ! isset(self::$pool['co']) )
        {
            self::$pool['co'] = new Channel(GUARD_DB_POOL_LIMIT);
        }
        else 
        {
            $length = self::$pool['co']->length();
        }

        if ( $length < GUARD_DB_POOL_LIMIT)
        {
            for ($i = $length; $i < GUARD_DB_POOL_LIMIT; $i++)
            {
                self::push(new $driverClass());
            }
        }

        return self::$pool['co']->pop();
    }

    public static function push($handle)
    {
        self::$pool['co']->push($handle);
    }
}
