<?php

define('LISTEN_PATH', BASE_PATH . '/test'); // 需要监控的目录
define('LISTEN_PATH_CHMOD', 0755); // 监控目录下默认目录权限

define('GUARD_SERVER_IP', "0.0.0.0");
define('GUARD_SERVER_PORT', "9508");
define('GUARD_LOG', RUNTIME_PATH . '/log/guard.log');
define('GUARD_PID_FILE', BASE_PATH . '/runtime/guard.pid');
define('allsysnc', true);
define('MAX_PACKAGE', 1024 * 1024 * 200);
define('file_arg', 'dfs');
define('PHPPATH', 'php');

define('GUARD_DB_DRIVER', 'redis');
define('GUARD_DB_SERVER', '192.168.200.10');
define('GUARD_DB_PORT', '6379');
define('GUARD_DB_AUTH', '');
define('GUARD_DB_POOL_LIMIT', 10); // 连接池上限

define('GUARD_TIMER_LIMIT', 10); // 文件被篡改后还原重试次数, 超过将发送通知