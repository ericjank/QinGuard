# QinGuard

Web 远程发布系统, 防篡改系统

## Installation

```
wget ff
```

## Used

* 启动
```
php bin/guard.php start
```

* 停止
```
php bin/guard.php stop

// 更为安全的停止方式是使用restful接口
http://0.0.0.0:9508/?action=shutdown
```

* 重启
```
php bin/guard.php reload

// 更为安全的重启方式是使用restful接口
http://0.0.0.0:9508/?action=reload
```

## 部署于前端服务器可实现以下功能：

* 1 远程发布
* 2 防篡改
* 3 全站静态化
* 4 自动化安全警报通知
* 5 TODO 整站备份(包括动态代码文件)

TODO

整站备份模式: 使用swoole直接作为web服务器, 实现内嵌式处理, 对每个请求进行转发、监控, 实现更高级别的防篡改, 100%防止被篡改后的内容被访客请求到, 可以使用swoole的Memory模块和Http\Server模块进行对整站进行备份, 包括静态文件和动态代码文件, 实现全站动态防篡改

逻辑完成后用C语言编辑为php扩展