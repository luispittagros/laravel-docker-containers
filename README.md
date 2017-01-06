# Laravel Docker Containers

Automate your docker containers inside Laravel

## Setup

#### Requirements
Docker >= 1.13

Laravel >= 5.3.*

PHP >= 5.6.4

#### Installation
```sh
composer require idrislab/laravel-docker-containers
```

Set the following environment variable(inside your .env file)

```sh
DOCKER_SOCKET=unix:///var/run/docker.sock
```

Create a new Artisan command
```sh
php artisan make:command Containers
```
Register the new command with Artisan in *app/Console/Kernel.php* 

```php
protected $commands = [
       Commands\Containers::class,
  ];
```
Update your *Containers* command to register containers shipped by default
```php
<?php

namespace App\Console\Commands;

use luisgros\docker\containers\MySQL;
use luisgros\docker\containers\Redis;
use luisgros\docker\ContainersCommand;

class Containers extends ContainersCommand
{
    protected $containers = [
        MySQL::class,
        Redis::class,
    ];
}
```
## Usage

```
php artisan containers <start|stop|restart> [container(s)]
```

### Examples
Starting all containers
```sh
php artisan containers start
```
Stopping and starting multiple containers
```sh
php artisan containers start mysql redis
```
Stopping one container 
```sh
php artisan containers stop mysql
```

### Adding custom containers 

Create a directory inside *app/* named *Containers* and create a file named *MySQLGroupReplication.php*
with the following:

``` php
<?php
namespace App\Containers;

use luisgros\docker\containers\Container;

class MySQLGroupReplication extends Container
{
    /**
     * @var string
     */
    public $repo = 'mysql/mysql-gr';
    /**
     * @var string
     */
    public $tag = 'latest';
    /**
     * @var string
     */
    public $network = 'group1';
    /**
     * @var string
     */
    public $instances = 3;

    /**
     * @return array
     */
    public function runCommand()
    {
        return [
                '-d --rm --net=group1 -e MYSQL_ROOT_PASSWORD=ENV[DB_PASSWORD] \\'.
                '-e MYSQL_REPLICATION_USER=ENV[DB_PASSWORD] -e MYSQL_REPLICATION_PASSWORD=ENV[DB_PASSWORD] \\'.
                'mysql/mysql-gr --group_replication_group_seeds=\'ENV[CONTAINER_I2]:6606,ENV[CONTAINER_I3]:6606\' \\'.
                '--server-id=ENV[INSTANCE_ID]',

                '-d --rm --net=group1 -e MYSQL_ROOT_PASSWORD=ENV[DB_PASSWORD] \\'.
                '-e MYSQL_REPLICATION_USER=ENV[DB_PASSWORD] -e MYSQL_REPLICATION_PASSWORD=ENV[DB_PASSWORD] \\'.
                'mysql/mysql-gr --group_replication_group_seeds=\'ENV[CONTAINER_I1]:6606,ENV[CONTAINER_I3]:6606\' \\'.
                '--server-id=ENV[INSTANCE_ID]',

                '-d --rm --net=group1 -e MYSQL_ROOT_PASSWORD=ENV[DB_PASSWORD] \\'.
                '-e MYSQL_REPLICATION_USER=ENV[DB_PASSWORD] -e MYSQL_REPLICATION_PASSWORD=ENV[DB_PASSWORD] \\'.
                'mysql/mysql-gr --group_replication_group_seeds=\'ENV[CONTAINER_I1]:6606,ENV[CONTAINER_I2]:6606\' \\'.
                '--server-id=ENV[INSTANCE_ID]',
        ];
    }

    public function preCommand()
    {
        $this->docker('network create group1 &>/dev/null');
    }
}
```

Update your *app/Console/Commands/Containers.php* command to register the new container 
```php
<?php

namespace App\Console\Commands;

use App\Containers\MySQLGroupReplication;
use luisgros\docker\containers\MySQL;
use luisgros\docker\containers\Redis;
use luisgros\docker\ContainersCommand;

class Containers extends ContainersCommand
{
    protected $containers = [
        MySQL::class,
        Redis::class,
        MySQLGroupReplication::class,
    ];
}
```

Start your container
```sh
php artisan containers start mysqlgroupreplication
```
