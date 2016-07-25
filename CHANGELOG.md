# CHANGELOG

## v0.5.3 (2016-07-26)

* Fixed: 当retry job时，向job body注入重试次数的变量名由`retry`改为`__retry`。

## v0.5.2 (2016-07-26)

* Fixed: 当抛出DeadlineSoonException时，休眠2秒后再reserve job。