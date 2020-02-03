#!/usr/bin/env php
<?php

if ( ! extension_loaded('inotify')) {
    exit("Please install inotify extension.\n");
}

if ( ! extension_loaded('redis')) {
    exit("Please install redis extension.\n");
}

if ( ! extension_loaded('swoole')) {
    exit("Please install swoole extension.\n");
}

if ( php_sapi_name() !== 'cli') {
    exit("Please use php cli mode.\n");
}

ini_set('display_errors', 'on');
ini_set('display_startup_errors', 'on');

error_reporting(E_ALL);
date_default_timezone_set('Asia/Shanghai');

! defined('BASE_PATH') && define('BASE_PATH', dirname(__DIR__, 1));
! defined('SWOOLE_HOOK_FLAGS') && define('SWOOLE_HOOK_FLAGS', SWOOLE_HOOK_ALL);
define('RUNTIME_PATH', BASE_PATH . '/runtime');

// if ( ! is_file(BASE_PATH . '/vendor/autoload.php'))
// {
//     // exit("Please run composer install.\n");
// }

// // require BASE_PATH . '/vendor/autoload.php';

// Self-called anonymous function that creates its own scope and keep the global namespace clean.
(function () {
    require_once BASE_PATH . '/config/config.php';
    require_once BASE_PATH . '/service/Watcher.php';
    require_once BASE_PATH . '/service/Guard.php';
    require_once BASE_PATH . '/service/Driver/Db.php';
    require_once BASE_PATH . '/service/Driver/Db/DbInterface.php';
    require_once BASE_PATH . '/service/Driver/Db/RedisDriver.php';

    function stopGuard()
    {

        $pid = is_file(GUARD_PID_FILE) ? file_get_contents(GUARD_PID_FILE) : '';
        $command = empty($pid) ? 
                "ps -ef | grep -E 'guard.php start' |grep -v 'grep'| awk '{print $2}'|xargs kill -9 > /dev/null 2>&1" : 
                "kill -9 {$pid} > /dev/null 2>&1";
        exec($command);
        // go(function () {
        //     $pid = is_file(GUARD_PID_FILE) ? Swoole\Coroutine\System::readFile(GUARD_PID_FILE) : '';

        //     $command = empty($pid) ? 
        //         "ps -ef | grep -E 'guard.php start' |grep -v 'grep'| awk '{print $2}'|xargs kill -9 > /dev/null 2>&1" : 
        //         "kill -9 {$pid} > /dev/null 2>&1";

        //     // if ( empty($pid))
        //     // {

        //     //     exec("ps -ef | grep -E 'guard.php start' |grep -v 'grep'| awk '{print $2}'|xargs kill -9 > /dev/null 2>&1");
        //     // }
        //     // else {
        //     //     exec("kill -9 {$pid} > /dev/null 2>&1");
        //     // }
        //     echo "即将重启服务 $pid\n";
        //     Swoole\Coroutine\System::exec($command);
        // });
        // return Swoole\Coroutine\System::exec($command);
    }

    if ( ! isset($_SERVER['argv'][1])) {
        exit("No argv.\n");
    } else {
        if ( ! is_dir(BASE_PATH . '/runtime'))
        {
            mkdir(BASE_PATH . '/runtime');
        }
        
        switch ($_SERVER['argv'][1]) {
            case 'start':
                Watcher::getInstance();
                echo "Guard process start success.\n";
                break;
            case 'stop':

                stopGuard();
                echo "Guard process has stoped.\n";
                break;
            case 'restart':
                stopGuard();

                exec("sleep 1");

                Watcher::getInstance();

                echo "Restart Guard process success.\n";
                break;
            default:
                exit("Not support this argv: " . $_SERVER['argv'][1] . ".\n");
                break;
        }
    }
})();
