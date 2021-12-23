# Speed

 `speed`是一个用于多进程消费rabbitmq队列消息的`composer`包，其架构为主+从模式。主进程主要
负责消息的派发和统计信息，子进程主要用于处理相应的任务消息。其中子进程会根据当前任务量进行动态配发。
目前还在测试中，若要用于生产中，请做好测试。

## Getting Started

   在使用之前，你需要你的php版本>=5.6，改版本理论上可以满足5.4+,但具体没有测试。目前开发环境为php5.6上。

### 依赖

 - php版本高于>=5.6
 - 扩展需要安装`pcntl`, `sockets`, `posix`'
 - 建议安装`event`,`libevent`扩展。这件使其性能达到最佳

### 安装

推荐使用`composer`安装, 包还未上架，敬请期待


## Built With

* [bunny/bunny](http://www.dropwizard.io/1.0.2/docs/) - rabbitmq  异步客户端
* [react/event-loop](http://www.dropwizard.io/1.0.2/docs/) - event loop
* [react/stream](http://www.dropwizard.io/1.0.2/docs/) - stream 

## License

This project is licensed under the MIT License - see the [LICENSE.md](LICENSE.md) file for details
