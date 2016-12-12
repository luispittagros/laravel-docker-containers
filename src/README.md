# Verticals Athena Tests

## Prerequisites

### Install athena

##### Linux

Using a *debian package* from athena [releases](https://github.com/athena-oss/athena/releases)
 
```bash
$ sudo dpkg -i <downloaded_debian_package>
```
   
Using `apt-get`
  
```bash
$ sudo add-apt-repository ppa:athena-oss/athena
$ sudo apt-get install athena
```
 
##### macOS

Using [Homebrew](http://brew.sh/):
```bash
$ brew tap athena-oss/tap
$ brew install athena
```

[More information](https://github.com/athena-oss/athena)

#### Install athena's proxy, selenium and php plugins

```sh
athena plugins install proxy https://github.com/athena-oss/plugin-proxy
athena plugins install selenium https://github.com/athena-oss/plugin-selenium
athena plugins install php https://github.com/athena-oss/plugin-php
```

## Usage

Initialize all plugins (this step must be performed everytime athena is stopped)
```sh
athena proxy start
athena selenium start hub latest
athena selenium start firefox-debug latest -p 6002:5900 -e no_proxy=localhost -e HUB_ENV_no_proxy=localhost
```
Running browser functional tests

```sh
athena php browser firefox ./ ./athena.json --testsuite=browser
```

Running api acceptance tests

```sh
athena php api ./ ./athena.json --testsuite=api
```
