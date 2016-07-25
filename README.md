Plumber
========

消息队列的Worker守护进程，目前支持beanstalkd。

## 运行环境

  * PHP >= 5.4.1
  * Swoole >= 1.7.18
  * Linux / Mac OSX

## 安装

```
composer require footstones/plumber
```

## 使用

### 启动
```
vendor/bin/plumber start /config-path # config-path为配置文件的路径
``` 

### 重启
```
vendor/bin/plumber restart /config-path
``` 

### 停止
```
vendor/bin/plumber stop /config-path
``` 

### 配置说明

请参考[example/config.php](example/config.php)文件。

### Worker的写法

请参考[example/Example1Worker.php](example/Example1Worker.php)。

### Worker执行的返回值

请参考[src/IWorker.php](src/IWorker.php)。

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## License

MIT.
