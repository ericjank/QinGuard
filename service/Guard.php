<?php

use QinGuard\Driver\Db;
use Swoole\Timer;

class Guard
{
    /**
     * TODO 检查全部文件, 对不合法的数据删除或更新
     * 
     * @return void
     */
    public static function checkAll()
    {

    }

    /**
     * 从库里读取全部文件信息, 并创建文件
     * 
     * @return void
     */
    public static function create($path = '', $page = 1)
    {
        self::createHandle($path, $page);
    }

    /**
     * 从库里读取全部文件信息, 并创建文件
     *
     * @param int $page 分页
     * @return void
     */
    private static function createHandle($path = '', $page = 1)
    {
        go(function() use ($path, $page) {
            Db::container(function($handle) use ($path, $page) {
                $datas = $handle->page($page, 1);

                if ( ! empty($datas))
                {
                    foreach ($datas as $key => $content) {
                        if (empty($content['p']))
                        {
                            continue;
                        }

                        // 只生成某目录下的文件, 忽略其他目录
                        if ( ! empty($path))
                        {
                            if (strpos($content['p'], $path) !== 0)
                            {
                                echo "忽略这个目录: {$content['p']} \n";
                                continue;
                            }
                        }

                        $file = LISTEN_PATH . $content['p'];

                        

                        self::timerHandle(function($time) use ($content, $file) {
                            if ( ! is_file($file))
                            {
                                // 检查目录是否存在, 若目录不存在优先创建上级目录
                                if ( ! is_dir(dirname($file)))
                                {
                                    @mkdir(dirname($file), LISTEN_PATH_CHMOD, true);
                                }
                            }

                            if (false === file_put_contents($file, $content['c'], LOCK_EX)) 
                            {
                                return false;
                            }

                            return true;
                        }, function() use ($content, $file) {
                            // 发送通知
                            self::sendMessage(" Warning: 文件 $file 无法创建, QinGuard防篡改系统无法自动完成操作, 请登录服务器排查原因", $file, 302);
                        });
                    }

                    echo date('[ c ]') . " Notice: Make all files at page $page!\n";

                    // 下一页 $page + 1
                    self::createHandle($path, $page + 1);
                }
                else {
                    echo date('[ c ]') . " Notice: Make all files was done!\n";
                }
            });
        });
    }

    /**
     * 向库里插入文件信息
     *
     * @param string $filename 文件相对路径
     * @param string $content 文件内容
     * @return 
     */
    public static function add($filename, $content)
    {
        go(function() use ($filename, $content) {
            self::addHandle($filename, $content);
        });
    }

    /**
     * 向库里插入文件信息
     *
     * @param string $filename 文件相对路径
     * @param string $content 文件内容
     * @return 
     */
    private static function addHandle($filename, $content, $time = 1)
    {
        Db::container(function($handle) use ($filename, $content, $time) {
            if ( ! $handle->set($filename, $content))
            {
                if ($time <= 10)
                {
                    Timer::after(10000, function() use ($filename, $content, $time) {
                        self::addHandle($filename, $content, $time + 1);
                    });
                }
                else 
                {
                    self::sendMessage("新增文件 " . LISTEN_PATH . "$filename 失败, QinGuard防篡改系统无法登记指定的文件", $filename, 601);
                }
            }
            else 
            {
                $file = LISTEN_PATH . $filename;
                if ( ! is_file($file))
                {
                    // 检查目录是否存在, 若目录不存在优先创建上级目录
                    if ( ! is_dir(dirname($file)))
                    {
                        @mkdir(dirname($file), LISTEN_PATH_CHMOD, true);
                    }
                }

                if (false === file_put_contents(LISTEN_PATH . $filename, $content)) 
                {
                    self::deleteHandle($filename);
                }
                else 
                {
                    // TODO 该文件获得临时豁免, 第一次触发事件时不进行check操作, 防止资源耗费

                    echo date('[ c ]') . " Warning: The file `$filename` was created \n";

                    self::sendMessage("新增文件 " . LISTEN_PATH . "$filename 成功, QinGuard防篡改系统已经登记指定的文件", $filename, 600);
                }
            }
        });
    }

    /**
     * 从库里移除文件信息
     *
     * @param string $filename 文件相对路径
     * @param string $content 文件内容
     * @return 
     */
    public static function delete($filename)
    {
        go(function() use ($filename) {
            self::deleteHandle($filename);
        });
    }

    /**
     * 向库里插入文件信息
     *
     * @param string $filename 文件相对路径
     * @param string $content 文件内容
     * @return 
     */
    private static function deleteHandle($filename, $time = 1)
    {
        Db::container(function($handle) use ($filename, $time) {
            if ( ! $handle->delete($filename))
            {
                if ($time <= 10)
                {
                    Timer::after(10000, function() use ($filename, $time) {
                        self::deleteHandle($filename, $time + 1);
                    });
                }
                else 
                {
                    self::sendMessage("删除文件 " . LISTEN_PATH . "$filename 失败, QinGuard防篡改系统无法删除指定的文件", $filename, 601);
                }
            }
            else 
            {
                @unlink(LISTEN_PATH . $filename);

                if (is_file(LISTEN_PATH . $filename))
                {

                }

                // TODO 该文件获得临时豁免, 第一次触发事件时不进行check操作, 防止资源耗费

                self::sendMessage("删除文件 " . LISTEN_PATH . "$filename 成功, QinGuard防篡改系统已经删除指定的文件", $filename, 600);
            }
        });
    }

    /**
     * 检查文件是否被篡改或删除
     *
     * @param int $mask 状态码
     * @param string $file 文件的系统路径
     * @param string $pre 文件的相对路径前缀
     * @param string $ext 文件名及扩展名
     * @return void
     */
    public static function checkFile($mask, $file, $pre, $ext)
    {
        // 文件相对路径作为key
        $filename = implode('/', [$pre, $ext]);

        go(function() use ($mask, $file, $filename) {
            self::fileHandle($filename, $file, function($realContent) use ($file, $mask) {
                

                // 还原文件内容
                // 延迟执行还原操作, 若多次覆盖失败则发送报警数据
                self::timerHandle(function($time) use ($file, $realContent, $mask) {

                    if ( ! is_file($file))
                    {
                        // 检查目录是否存在, 若目录不存在优先创建上级目录
                        if ( ! is_dir(dirname($file)))
                        {
                            @mkdir(dirname($file), LISTEN_PATH_CHMOD, true);
                        }
                    }
                    
                    if (false === file_put_contents($file, $realContent['c'])) 
                    {
                        return false;
                    }

                    echo date('[ c ]') . " Warning: The file `$file` was resumed in $time time, mask $mask \n";

                    if ($mask != 512)
                    {
                        self::sendMessage("文件 $file 被修改, QinGuard防篡改系统已经自动还原", $file, 401);
                    }
                    else 
                    {
                        self::sendMessage("文件 $file 被删除, QinGuard防篡改系统已经自动生成", $file, 405);
                    }

                    return true;
                }, function() use ($file) {
                    // 发送通知
                    self::sendMessage("文件 $file 可能被恶意篡改, QinGuard防篡改系统无法自动还原, 请登录服务器排查原因", $file, 302);
                });
            }, function() use ($mask, $file) {
                if ( $mask != 512)
                {
                    self::timerHandle(function($time) use ($file) {
                        if ( @unlink($file))
                        {
                            echo date('[ c ]') . " Warning: The unlawful file `$file` was deleted in $time time\n";
                            self::sendMessage("文件 $file 不在监控范围内, QinGuard防篡改系统已经将其删除", $file, 402);
                            return true;
                        }

                        return false;
                    }, function() use ($file) {
                        self::sendMessage("文件 $file 不在监控范围内, QinGuard防篡改系统无法将其删除, 请登录服务器排查原因", $file, 301);
                    });
                }
            });
        });
    }

    /**
     * 通知第三方系统
     *
     * @param string $msg 通知内容
     * @param string $file 对应的文件
     * @param int $type 通知类型
     * @return void
     */
    public static function sendMessage($msg = '', $file, $type)
    {
        // TODO 调用指定restful接口, 发送通知
        
        echo date('[ c ]') . " 发送消息通知: $msg, $file, $type\n";
    }

    /**
     * 尝试执行操作, 执行失败时执行回调
     *
     * @param callback $cb 尝试执行的操作函数, 当函数返回值为真时不再继续尝试, 否则间隔一定时间后重新尝试执行$cb
     * @param callback $fb 重试达到上限时执行的回调函数
     * @param int $afterTime 延迟执行时间间隔, 单位:ms
     * @param int $time 当前重试次数
     * @return void
     */
    private static function timerHandle(object $cb, object $fb, int $afterTime = 10000, int $time = 1)
    {
        if ($time > GUARD_TIMER_LIMIT)
        {
            $fb($time);
        }
        else {
            if ( ! $cb($time))
            {
                Timer::after($afterTime, function() use ($cb,  $fb, $afterTime, $time) {
                    self::timerHandle($cb, $fb, $afterTime, $time + 1);
                });
            }
        }
    }

    /**
     * 检查文件修改或删除
     *
     * @param string $key 标识
     * @param string $content 文件当前内容
     * @param callback $cb 文件内容与监控系统不符执行的回调函数
     * @param callback $fb 监控系统不存在该标识对应的文件执行的回调函数
     * @return void
     */
    private static function fileHandle($filename, $file, $cb, $fb)
    {
        Db::container(function($handle) use ($filename, $file, $cb, $fb) {
            $content = is_file($file) ? @file_get_contents($file) : '';

            $realContent = $handle->get($filename);

            if ( is_null($realContent) || false === $realContent)
            {
                $fb();
            }
            else if (trim($content) != trim($realContent['c']))
            {
                $cb($realContent);
            }
        });
    }
}
