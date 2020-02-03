<?php
class Watcher
{
    public static $instance;
    private $server;
    private $http;
    private $table;
    private $watch_list = [];
    private $filefd;
    private $localip;


    private $application;
    public $b_server_pool = array();
    public $client_pool = array();
    public $client_a;
    
    private $connectioninfo;
    private $curpath;
    private $curtmp;
    
    private $filesizes;
    private $tmpdata;
    private $tmpdatas;
    private $oldpath;
    private $client_pool_ser = array();
    private $client_pool_ser_c = array();
    private $tmpdata_flag;
    

    public function __construct()
    {
        // 开启全部协程支持
        // 参考资料: https://wiki.swoole.com/wiki/page/p-runtime.html
        Swoole\Runtime::enableCoroutine(true, SWOOLE_HOOK_ALL | SWOOLE_HOOK_CURL);

        $this->table = new swoole_table(1024);
        $this->table->column('fileserverfd', swoole_table::TYPE_INT, 8);
        $this->table->create();

        $this->server = new Swoole\Http\Server(GUARD_SERVER_IP, GUARD_SERVER_PORT, SWOOLE_PROCESS, SWOOLE_SOCK_TCP);

        $serverConfig = [
            'worker_num' => 1,
            'pid_file' => GUARD_PID_FILE,
            'dispatch_mode' => 4,
            'open_length_check' => true,
            'package_length_type' => 'N',
            'package_length_offset' => 0,
            'package_body_offset' => 4,
            'package_max_length' => MAX_PACKAGE,
            'daemonize' => true
        ];

        if (GUARD_LOG) {
            $serverConfig['log_file'] = GUARD_LOG;

            if ( ! is_dir(dirname(GUARD_LOG)))
            {
                @mkdir(dirname(GUARD_LOG), 0, true);
            }
        }

        $this->server->set($serverConfig);

        foreach (['Start', 'WorkerStart', 'Connect', 'Receive', 'Close', 'ManagerStop', 'WorkerError', 'Request'] as $cmd) {
            $this->server->on($cmd, [&$this, "on" . $cmd]);
        }

        $this->server->start();
    }

    public function onStart($serv)
    {
        echo date('[ c ]') . "Guard server started\n";

        $localinfo = swoole_get_local_ip();
        $this->localip = current($localinfo);
    }

    public function onRequest(swoole_http_request $request, swoole_http_response $response)
    {
        $action = $request->get['action'];

        if ($action == 'reload')
        {
            $this->reload();
            $response->end("<h1>Guard has been reload</h1>");
        }
        else if ($action == 'shutdown')
        {
            $this->shutdown();
            $response->end("<h1>Guard has been shutdown</h1>");
        }
        else if($action == 'add')
        {
            $filepath = $request->post['filepath'];
            $content = $request->post['content'];

            Guard::add($filepath, $content);
        }
        else if ($action == 'create')
        {
            Guard::create();
        }
        else if ($action == 'delete')
        {
            $filepath = $request->post['filepath'];

            Guard::delete($filepath);
        }
    }

    public function onWorkerStart($serv, $worker_id)
    {
        $listenpath = $listenpathex = $listenpathx = LISTEN_PATH;

        $this->filefd = inotify_init();

        $wd = inotify_add_watch($this->filefd, $listenpath, IN_CREATE | IN_MODIFY | IN_DELETE); //IN_MODIFY、IN_ALL_EVENTS、IN_CLOSE_WRITE
        $this->watch_list[$wd] = [
            'wd' => $wd,
            'path' => $listenpath,
            'pre' => '',
            'ip' => $this->localip
        ];
        echo date('[ c ]') . " add dir:" . $listenpath . "\n";
        
        $lisrdir = $this->getlistDir($listenpath);

        if ($lisrdir) 
        {
            foreach ($lisrdir as $dir) {
                $wd = inotify_add_watch($this->filefd, $dir, IN_CREATE | IN_MODIFY | IN_DELETE); //IN_MODIFY、IN_ALL_EVENTS、IN_CLOSE_WRITE
                $this->watch_list[$wd] = [
                    'wd' => $wd,
                    'path' => $dir,
                    'pre' => substr($dir, strlen($listenpathex), strlen($dir)),
                    'ip' => $this->localip
                ];

                echo date('[ c ]') . " add dir:" . $dir . "\n";
            }
        }

        swoole_event_add($this->filefd, function($fd) use ($listenpath, $listenpathex)
        {
            $events = inotify_read($fd);

            if ($events) 
            {
                foreach ($events as  $event) 
                {
                    if (isset($event['name'])) 
                    {
                        if (substr($event['name'], -1) == '~' || strpos($event['name'], '.') === 0) 
                        {
                            continue;
                        }

                        // 新增目录
                        if ($event['mask'] == 1073742080) 
                        {
                            if (in_array($event['wd'], array_keys($this->watch_list))) 
                            {
                                $listenpath = substr($this->watch_list[$event['wd']]['path'], 0, strripos($this->watch_list[$event['wd']]['path'] . '/', "/") + 1);
                            }

                            $listenpath .= '/' . $event['name'];

                            $wd = inotify_add_watch($this->filefd, $listenpath, IN_CREATE | IN_MODIFY | IN_DELETE);
                            $this->watch_list[$wd] = [
                                'wd' => $wd,
                                'path' => $listenpath,
                                'pre' => substr($listenpath, strlen($listenpathex), strlen($listenpath)),
                                'ip' => $this->localip
                            ];

                            echo date('[ c ]') . " add dir:" . $listenpath . "\n";
                        } 
                        // 目录被删除
                        elseif ($event['mask'] == 1073742336)
                        {
                            $dir = $this->watch_list[$event['wd']]['path'] . '/' . $event['name'];
                            $dir = substr($dir, strlen(LISTEN_PATH));
                            echo "delete dir: $dir\n";

                            Guard::create($dir);


                            // 检查目录下是否有文件, 若有文件则创建目录和文件
                            // Guard::checkDir($data['data']['path'], $data['data']['pre'], $data['data']['fileex']);
                        }
                        // 新增、修改、删除文件
                        elseif($event['mask'] == 2 || $event['mask'] == 256 || $event['mask'] == 512) {
                            $file = $this->watch_list[$event['wd']]['path'] . '/' . $event['name'];
                            
                            $extends = explode("/", $file);
                            $vas     = count($extends) - 1;

                            $data = [
                                'type' => 'fileclient',
                                'data' => [
                                    'path' => str_replace("_", "@", $file),
                                    'fileex' => str_replace("_", "@", $extends[$vas]),
                                    'pre' => empty($this->watch_list[$event['wd']]['pre']) ? '' : str_replace("_", "@", $this->watch_list[$event['wd']]['pre']),
                                    'ip' => $this->localip
                                ]
                            ];
                            
                            // 验证文件是否被篡改或被删除
                            Guard::checkFile($event['mask'], $data['data']['path'], $data['data']['pre'], $data['data']['fileex']);

                        }
                    }
                }
                
            }
        });

        echo date('[ c ]') . ": worker started\n";


        // $localinfo     = swoole_get_local_ip();
        // $this->localip = current($localinfo);
        // $serverlist    = FileDistributedClient::getInstance()->getserlist(file_arg);
        // $result_fd     = json_decode($serverlist, true);
        // if (!empty($result_fd)) {
        //     foreach ($result_fd as $id => $fd) {
        //         if ($fd != $this->localip) {
        //             $client = FileDistributedClient::getInstance()->addServerClient($fd);
        //             $this->table->set(ip2long($fd), array(
        //                 'fileserverfd' => ip2long($fd)
        //             ));
        //             $this->b_server_pool[ip2long($fd)] = array(
        //                 'fd' => $fd,
        //                 'client' => $client
        //             );
        //         }
        //     }
        // }
        // FileDistributedClient::getInstance()->appendserlist($this->localip, ip2long($this->localip), file_arg);
        
        
    }
    
    public function onConnect($serv, $fd)
    {
        // $this->connectioninfo = $serv->connection_info($fd);
        // $localinfo            = swoole_get_local_ip();
        // $this->localip        = current($localinfo);
        // if ($this->localip != $this->connectioninfo['remote_ip']) {
        //     $this->client_pool[ip2long($this->connectioninfo['remote_ip'])] = array(
        //         'fd' => $fd,
        //         'remote_ip' => $this->connectioninfo['remote_ip']
        //     );
        // }
        
    }


    public function onReceive($serv, $fd, $from_id, $data)
    {   
        // 控制监控开启或关闭 $this->shutdown $this->reload

        echo "asdfasfd";

        // $remote_info = FileDistributedClient::getInstance()->unpackmes($data);
        // //判断是否为二进制图片流
        // if (!is_array($remote_info)) {
        //     if (!$this->tmpdata_flag) {
        //         $tdf                   = array_shift($this->client_pool_ser_c);
        //         $this->curpath['path'] = LISTEN_PATH . str_replace("@", "_", rawurldecode($tdf['data']['path']));
        //         $this->filesizes       = $tdf['data']['filesize'];
        //         $this->tmpdata_flag    = 1;
        //     }
        //     if (isset($this->curpath['path']) && $this->curpath['path'] != LISTEN_PATH) {
        //         if (is_dir(dirname($this->curpath['path'])) && is_readable(dirname($this->curpath['path']))) {
        //         } else {
        //             FileDistributedClient::getInstance()->mklistDir(dirname($this->curpath['path']));
        //         }
        //         if ($this->oldpath != $this->curpath['path']) {
        //             $this->tmpdata .= $remote_info;
                    
        //             if (strlen($this->tmpdata) > $this->filesizes) {
        //                 $this->tmpdatas = substr($this->tmpdata, $this->filesizes, strlen($this->tmpdata));
        //                 $this->tmpdata  = substr($this->tmpdata, 0, $this->filesizes);
        //             }
        //         }
        //         if (strlen($this->tmpdata) == $this->filesizes) {
                    
        //             if (file_put_contents($this->curpath['path'], $this->tmpdata)) {
        //                 $this->tmpdata = '';
        //                 $this->oldpath = $this->curpath['path'];
                        
        //                 if (strlen($this->tmpdatas) > 0) {
        //                     $this->tmpdata  = $this->tmpdatas;
        //                     $this->tmpdatas = '';
        //                 }
        //                 $this->tmpdata_flag = 0;
        //             }
                    
        //         }
        //     }
        // } else {
        //     if ($remote_info['type'] == 'system' && $remote_info['data']['code'] == 10001) {
        //         if ($this->client_a != $remote_info['data']['fd']) {
        //             if (!$this->table->get(ip2long($remote_info['data']['fd']))) {
        //                 $client                                                   = FileDistributedClient::getInstance()->addServerClient($remote_info['data']['fd']);
        //                 $this->b_server_pool[ip2long($remote_info['data']['fd'])] = array(
        //                     'fd' => $remote_info['data']['fd'],
        //                     'client' => $client
        //                 );
        //                 $this->client_a                                           = $remote_info['data']['fd'];
        //             } else {
        //                 if (FileDistributedClient::getInstance()->getkey(file_arg . 'errserfile')) {
        //                     $client                                                   = FileDistributedClient::getInstance()->addServerClient($remote_info['data']['fd']);
        //                     $this->b_server_pool[ip2long($remote_info['data']['fd'])] = array(
        //                         'fd' => $remote_info['data']['fd'],
        //                         'client' => $client
        //                     );
        //                     $this->client_a                                           = $remote_info['data']['fd'];
        //                     if ($this->localip == FileDistributedClient::getInstance()->getkey(file_arg . 'errserfile')) {
        //                         FileDistributedClient::getInstance()->delkey(file_arg . 'errserfile');
        //                     }
        //                 }
        //             }
                    
        //         }
        //         if ($this->localip != $this->connectioninfo['remote_ip']) {
        //             if (allsysnc) {
        //                 if (!in_array($this->connectioninfo['remote_ip'], $this->client_pool_ser)) {
        //                     $serv->send($fd, FileDistributedClient::getInstance()->packmes(array(
        //                         'type' => 'system',
        //                         'data' => array(
        //                             'code' => 10002,
        //                             'fd' => $this->localip
        //                         )
        //                     )));
        //                     array_push($this->client_pool_ser, $this->connectioninfo['remote_ip']);
        //                 }
        //             }
        //         }
        //         if (GUARD_LOG) {
        //             file_put_contents(GUARD_LOG, date('[ c ]') . str_replace("\n", "", var_export($remote_info, true)) . '\r\n', FILE_APPEND);
        //         } else {
        //             echo date('[ c ]') . str_replace("\n", "", var_export($remote_info, true)) . '\r\n';
        //         }
        //     } else {
        //         switch ($remote_info['type']) {
        //             case 'filesize':
        //                 if (isset($remote_info['data']['path'])) {
        //                     $data_s = array(
        //                         'type' => 'filesizemes',
        //                         'data' => array(
        //                             'path' => $remote_info['data']['path'],
        //                             'filesize' => $remote_info['data']['filesize']
        //                         )
        //                     );
        //                     array_push($this->client_pool_ser_c, $remote_info);
        //                     $serv->send($fd, FileDistributedClient::getInstance()->packmes($data_s));
        //                 }
        //                 break;
        //             case 'file':
        //                 if (isset($remote_info['data']['path'])) {
        //                     if (!file_exists(LISTEN_PATH . str_replace("@", "_", rawurldecode($remote_info['data']['path'])))) {
        //                         if (substr(LISTEN_PATH . str_replace("@", "_", rawurldecode($remote_info['data']['path'])), -1) != '~') {
        //                             $data_s = array(
        //                                 'type' => 'filemes',
        //                                 'data' => array(
        //                                     'path' => $remote_info['data']['path']
        //                                 )
        //                             );
        //                             $serv->send($fd, FileDistributedClient::getInstance()->packmes($data_s));
        //                         }
                                
        //                     } 
        //                 }
        //                 break;
        //             case 'asyncfileclient':
        //                 if (isset($remote_info['data']['path'])) {
        //                     if (empty($remote_info['data']['pre'])) {
        //                         $dataas = array(
        //                             'type' => 'asyncfile',
        //                             'data' => array(
        //                                 'path' => rawurlencode('/') . $remote_info['data']['fileex']
        //                             )
        //                         );
        //                     } else {
        //                         $dataas = array(
        //                             'type' => 'asyncfile',
        //                             'data' => array(
        //                                 'path' => $remote_info['data']['pre']
        //                             )
        //                         );
        //                     }
                            
        //                     $serv->send($fd, FileDistributedClient::getInstance()->packmes($dataas));
        //                 }
        //                 break;
        //             case 'fileclient':
        //                 if (empty($remote_info['data']['pre'])) {
        //                     $datas = array(
        //                         'type' => 'file',
        //                         'data' => array(
        //                             'path' => rawurlencode('/') . $remote_info['data']['fileex']
        //                         )
        //                     );
        //                 } else {
        //                     $datas = array(
        //                         'type' => 'file',
        //                         'data' => array(
        //                             'path' => rawurlencode(rawurldecode($remote_info['data']['pre']) . '/' . rawurldecode($remote_info['data']['fileex']))
        //                         )
        //                     );
        //                 }
                       
        //                 foreach ($this->b_server_pool as $k => $v) {
        //                     if ($v['fd'] != $this->localip)
        //                         $v['client']->send(FileDistributedClient::getInstance()->packmes($datas));
        //                 }
        //                 break;
        //             default:
        //                 break;
        //         }
        //         if (GUARD_LOG) {
        //             file_put_contents(GUARD_LOG, date('[ c ]') . str_replace("\n", "", var_export($remote_info, true)) . '\r\n',FILE_APPEND);
        //         } else {
        //             echo date('[ c ]') . str_replace("\n", "", var_export($remote_info, true)) . '\r\n';
        //         }
        //     }
        
        // }
        
    }
    
    /**
     * 服务器断开连接
     * @param $cli
     */
    public function onClose($server, $fd, $from_id)
    {
        // if (!empty($this->client_pool)) {
        //     foreach ($this->client_pool as $k => $v) {
        //         if ($v['fd'] == $fd) {
        //             FileDistributedClient::getInstance()->removeuser($v['remote_ip'], file_arg);
        //             if (GUARD_LOG) {
        //                 file_put_contents(GUARD_LOG, date('[ c ]') . $v['remote_ip'] . " have closed\r\n", FILE_APPEND);
        //             } else {
        //                 echo date('[ c ]') . $v['remote_ip'] . " have closed\r\n";
        //             }
        //             unset($this->client_pool[$k]);
        //         }
        //     }
        // } else {
        //     FileDistributedClient::getInstance()->removeuser($this->localip, file_arg);
        //     if (GUARD_LOG) {
        //         file_put_contents(GUARD_LOG, date('[ c ]') . $this->localip . " have closed\r\n", FILE_APPEND);
        //     } else {
        //         echo date('[ c ]') . $this->localip . " have closed\r\n";
        //     }
            
        // }
    }
    
    public function onManagerStop($serv)
    {
        // if (empty($this->client_pool)) {
        //     FileDistributedClient::getInstance()->removeuser($this->localip, file_arg);
        //     if (GUARD_LOG) {
        //         file_put_contents(GUARD_LOG, date('[ c ]') . $this->localip . " have closed\r\n", FILE_APPEND);
        //     } else {
        //         echo date('[ c ]') . $this->localip . " have closed\r\n";
        //     }
        // }
        
        if ($this->filefd)
        {
            swoole_event_del($this->filefd);
        }
    }
    
    public function onWorkerError($serv, $worker_id, $worker_pid, $exit_code)
    {
        // if (empty($this->client_pool)) {
        //     FileDistributedClient::getInstance()->removeuser($this->localip, file_arg);
        //     if (GUARD_LOG) {
        //         file_put_contents(GUARD_LOG, date('[ c ]') . $this->localip . " have closed\r\n", FILE_APPEND);
        //     } else {
        //         echo date('[ c ]') . $this->localip . " have closed\r\n";
        //     }
        // }
        
        if ($this->filefd)
        {
            swoole_event_del($this->filefd);
        }
    }

    /**
     * 重启监控
     * @return void
     */
    public function reload()
    {
        $this->server->reload();
    }

    /**
     * 关闭监控
     * @return void
     */
    public function shutdown()
    {
        $this->server->shutdown();
    }

    /**
     * 获取目录
     * @return void
     */
    public function getlistDir($dir)
    {
        $dir .= substr($dir, -1) == '/' ? '' : '/';
        $dirInfo = [];
        foreach (glob($dir . '*', GLOB_ONLYDIR) as $v) {
            $dirInfo[] = $v;
            if (is_dir($v)) {
                $dirInfo = array_merge($dirInfo, $this->getlistDir($v));
            }
        }
        return $dirInfo;
    }
    
    public static function getInstance()
    {
        if (!(self::$instance instanceof Watcher)) {
            self::$instance = new Watcher;
        }

        return self::$instance;
    }
}

