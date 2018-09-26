Plumber
========

消息队列的Worker守护进程，目前支持beanstalkd。

## 运行环境

  * PHP >= 5.4.1
  * Swoole >= 1.7.18
  * Linux / Mac OSX

## 安装

```
composer require codeages/plumber
```

## 使用

```
Plumber 0.6.0

Usage:
  bin/plumber (run|start|restart|stop)  [--bootstrap=<file>]

Options:
  -h|--help    show this
  -b <file> --bootstrap=<file>  Load configuration file [default: plumber.php]
```

### 启动
```
bin/plumber start -b bootstrap-file-path   # `bootstrap-file-path`为启动配置文件路径
```

### 重启
```
bin/plumber restart -b bootstrap-file-path
```

### 停止
```
bin/plumber stop -b bootstrap-file-path
```

### Bootstrap启动配置文件说明

Bootstrap启动配置文件，必须返回`Pimple\Container`类型的配置对象，情参考[example/bootstrap.php](example/bootstrap.php)文件。

### Worker的写法

请参考[example/Example1Worker.php](example/Example1Worker.php)。

### Worker执行的返回值

请参考[src/IWorker.php](src/IWorker.php)。

## Docker

### 启动

```
docker-compose up
```

### Example Put Message

```
docker exec YOUR_CONTAINER_ID php example/put_message.php
```

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## License

MIT.
