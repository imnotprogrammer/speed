# Speed

 `speed`是一个用于多进程消费rabbitmq队列消息的`composer`包，其架构为主+从模式。主进程主要
负责消息的派发和统计信息，子进程主要用于处理相应的任务消息。其中子进程会根据当前任务量进行动态配发。
目前还在测试中，切勿用于实际生产中。

## Getting Started

   在使用之前，你需要你的php版本>=5.6，改版本理论上可以满足5.4+,但具体没有测试。目前开发环境为php5.6上。

### 依赖

 - php版本高于>=5.6
 - 扩展需要安装`pcntl`, `sockets`, `posix`'
 - 建议安装`event`,`libevent`扩展。这件使其性能达到最佳

### Installing

A step by step series of examples that tell you how to get a development env running

Say what the step will be

```
Give the example
```

And repeat

```
until finished
```

End with an example of getting some data out of the system or using it for a little demo

## Running the tests

Explain how to run the automated tests for this system

### Break down into end to end tests

Explain what these tests test and why

```
Give an example
```

## Deployment

Add additional notes about how to deploy this on a live system

## Built With

* [Dropwizard](http://www.dropwizard.io/1.0.2/docs/) - The web framework used
* [Maven](https://maven.apache.org/) - Dependency Management
* [ROME](https://rometools.github.io/rome/) - Used to generate RSS Feeds

## Contributing

Please read [CONTRIBUTING.md](https://gist.github.com/PurpleBooth/b24679402957c63ec426) for details on our code of conduct, and the process for submitting pull requests to us.

## Versioning

We use [SemVer](http://semver.org/) for versioning. For the versions available, see the [tags on this repository](https://github.com/your/project/tags).

## Authors

* **Billie Thompson** - *Initial work* - [PurpleBooth](https://github.com/PurpleBooth)

See also the list of [contributors](https://github.com/your/project/contributors) who participated in this project.

## License

This project is licensed under the MIT License - see the [LICENSE.md](LICENSE.md) file for details

## Acknowledgments

* Hat tip to anyone whose code was used
* Inspiration
* etc