# CHANGELOG

## HEAD (Unreleased)
_(none)_

## 0.8.4 (2017-09-08)

* 修复 PHP 7 下，不会捕获 `Throwable` 异常的问题。

## 0.8.3 (2017-09-04)

* Worker 启动的时候，加入随机的秒数，以防止当大量worker同时并发启动的时候，benstalkd连接不上。

## 0.8.2 (2017-08-21)

* 增加 `app_name` 配置项，配置进程的名称。

## 0.8.1 (2017-08-15)

* 修复 `ForwardWorker` 日志错误的问题。

## 0.8.0 (2017-08-13)

* 限制Worker进程异常退出后，10分钟内最多10次重新拉起，超过限制后不再重新拉起。
* Master进程名显示当前Master的启动文件名。

## 0.7.5 (2017-08-11)
* 修复 ForawrdWorker 未设 Logger 报错的问题。

## 0.7.4 (2017-08-08)

* 修复 ForwardWorker 长时间运行后，报 Socket 错误的问题。

## 0.7.3 (2017-08-07)

* 修复通过SSH远程启动服务后，一直卡住的问题。

## 0.7.2 (2017-08-02)

* 修复 ForwardWorker 队列名错误的问题。

## 0.7.0 (2017-02-04)

* Refactor: 简化逻辑。
* Feature: worker进程异常退出后，会自动重新拉起。
* Fixed: plumber被强制kill后，再次启动报"plumber is running"的问题。

## 0.6.1 (2017-01-19)

* Fixed: fix for 0.6.0

## 0.6.0 (2017-01-19)

* Feature: 整体的使用方式变更，见README。

## 0.5.4 (2016-12-28)

* Feature: 去除了Plumber\Logger类，使用Monolog类替代。
* Feature: 使用Monolog\ErrorHandler捕获程序错误，并记录日志。
* Feature: 去除了`output_path`的配置，output输出的内容，合并到`log_path`所在文件。

## v0.5.3 (2016-07-26)

* Fixed: 当retry job时，向job body注入重试次数的变量名由`retry`改为`__retry`。

## v0.5.2 (2016-07-26)

* Fixed: 当抛出DeadlineSoonException时，休眠2秒后再reserve job。
