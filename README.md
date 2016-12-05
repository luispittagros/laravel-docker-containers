# Laravel Docker Containers

## Installation
```sh
composer require idrislab/laravel-docker-containers
```

Register DockerContainers with Artisan in **app/Console/Kernel.php**

```php
    protected $commands = [
        DockerContainers::class,
    ];
```

Set the following environment variables (inside .env file for example)

```sh
DOCKER_SOCKET=unix:///var/run/docker.sock
DOCKER_CONTAINERS=mysql,redis
```

## Usage

```
php artisan containers <start|stop|restart> [--name=]
```

### Examples
Starting all containers:
```sh
php artisan containers start
```

Stopping only one container:
```sh
php artisan containers stop --name=mysql
```

## Adding Containers
Create a new Artisan command
```sh
php artisan make:command Containers
```

Register the new command with Artisan in **app/Console/Kernel.php** and remove **DockerContainers::class** if it is registered
```php
 protected $commands = [
        Commands\Containers::class,
    ];
```

Update your command to look like:
```php
<?php
namespace App\Console\Commands;

use luisgros\DockerContainers;

/**
 * Class  Containers
 *
 * @package App\Console\Commands
 */
class Containers extends DockerContainers
{
    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {

    }
}
```

Add your container(s) inside method **handle()**, in this case we're adding a [MySQL Group Replication Container](https://hub.docker.com/r/mysql/mysql-gr/)
```php
      $services = [
            'MySQLGr' => [
                'repo'      => 'mysql/mysql-gr',
                'tag'       => 'latest',
                'instances' => 3,
                'commands'   => [
                    1 =>
                        '-d --net=group1 -e MYSQL_ROOT_PASSWORD=ENV[DB_PASSWORD] \\'.
                        '-e MYSQL_REPLICATION_USER=ENV[DB_PASSWORD] -e MYSQL_REPLICATION_PASSWORD=ENV[DB_PASSWORD] \\'.
                        'mysql/mysql-gr --group_replication_group_seeds=\'ENV[MYSQLGR2]:6606,ENV[MYSQLGR3]:6606\' \\'.
                        '--server-id=ENV[INSTANCE_NAME]',
                    2 =>
                        '-d --net=group1 -e MYSQL_ROOT_PASSWORD=ENV[DB_PASSWORD] \\'.
                        '-e MYSQL_REPLICATION_USER=ENV[DB_PASSWORD] -e MYSQL_REPLICATION_PASSWORD=ENV[DB_PASSWORD] \\'.
                        'mysql/mysql-gr --group_replication_group_seeds=\'ENV[MYSQLGR1]:6606,ENV[MYSQLGR3]:6606\' \\'.
                        '--server-id=ENV[INSTANCE_NAME]',
                    3 =>
                        '-d --net=group1 -e MYSQL_ROOT_PASSWORD=ENV[DB_PASSWORD] \\'.
                        '-e MYSQL_REPLICATION_USER=ENV[DB_PASSWORD] -e MYSQL_REPLICATION_PASSWORD=ENV[DB_PASSWORD] \\'.
                        'mysql/mysql-gr --group_replication_group_seeds=\'ENV[MYSQLGR1]:6606,ENV[MYSQLGR2]:6606\' \\'.
                        '--server-id=ENV[INSTANCE_NAME]',
                ],
                'network' => 'group1',
                'docker' => [
                    'pre' => [
                        'network create group1 &>/dev/null',
                    ]
                ]
            ],
        ];

        $this->addContainers($containers);
        
        parent::handle();
```
