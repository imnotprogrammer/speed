# Speed

 `speed`是一个用于多进程消费rabbitmq队列消息的`composer`包，其架构为主+从模式。主进程主要
负责消息的派发和统计信息，子进程主要用于处理相应的任务消息。其中子进程会根据当前任务量进行动态配发。
目前还在测试中，若要用于生产中，请做好测试。

### 准备

 - php版本高于>=5.6
 - 扩展需要安装`pcntl`, `sockets`, `posix`'
 - 建议安装`event`,`ev`扩展。

> PS: 目前在测试`libevent`扩展作为IO事件循环器时，会出现主进程与子进程交互消息时，消息丢失的问题，同时信号监听也会失效，子进程回收出现延迟，这些问题主要出现在子进程空闲退出的时候。所以不建议使用该扩展。

### 安装

推荐使用`composer`安装, 包还未上架，敬请期待


## Built With

* [bunny/bunny](http://www.dropwizard.io/1.0.2/docs/) - rabbitmq  异步客户端
* [react/event-loop](http://www.dropwizard.io/1.0.2/docs/) - event loop
* [react/stream](http://www.dropwizard.io/1.0.2/docs/) - stream 

## License

This project is licensed under the MIT License - see the [LICENSE.md](LICENSE.md) file for details
