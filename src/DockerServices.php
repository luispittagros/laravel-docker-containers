<?php

namespace luisgros;

use Illuminate\Console\Command;
use Exception;

/**
 * Class DockerServices
 *
 * @package luisgros
 */
class DockerServices extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'services {option} {--service=}';
    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Setup docker environment';
    /**
     * The services
     *
     * @var string
     */
    protected $services = [
        'MySQL'      => [
            'repo'    => 'mysql',
            'tag'     => 'latest',
            'command' =>
                '--name=laravel-mysql -d -v /mysql:/var/lib/mysql \\'.
                '-e MYSQL_USER=ENV[DB_USERNAME] \\'.
                '-e MYSQL_PASSWORD=ENV[DB_PASSWORD] \\'.
                '-e MYSQL_DATABASE=ENV[DB_DATABASE] \\'.
                '-e MYSQL_ROOT_PASSWORD=ENV[DB_PASSWORD] mysql \\'.
                '--character-set-server=utf8mb4 --collation-server=utf8mb4_unicode_ci',
        ],
        'Redis'      => [
            'repo'    => 'redis',
            'tag'     => 'alpine',
            'command' =>
                '-d --name=laravel-redis -p ENV[REDIS_PORT]:ENV[REDIS_PORT] \\'.
                'redis redis-server --appendonly yes --requirepass ENV[REDIS_PASSWORD]',
        ],
        'Beanstalkd' => [
            'repo'    => 'schickling/beanstalkd',
            'tag'     => 'latest',
            'command' => '-d --name=laravel-beanstalkd -p 11300:11300 schickling/beanstalkd',
        ],
        'Memcached'  => [
            'repo'    => 'memcached',
            'tag'     => 'alpine',
            'command' => '-d --name=laravel-memcached  -p ENV[MEMCACHED_PORT]:ENV[MEMCACHED_PORT] memcached',
        ],
    ];
    /**
     * @var Docker
     */
    protected $docker;

    /**
     * Create a new command instance.
     *
     * @param Docker $docker
     */
    public function __construct(Docker $docker)
    {
        parent::__construct();

        $this->docker = $docker;
    }

    /**
     * @throws Exception
     */
    public function handle()
    {
        $services = getenv('DOCKER_SERVICES');
        if (!$services) {
            throw new Exception("DOCKER_SERVICES is not defined");
        }

        $network = [];

        collect(explode(",", $services))
            ->mapWithKeys(function ($service) {
                //Retrieve only current service definition
                $services = collect($this->services)->map(function ($attributes, $current) use (&$service) {
                    if (strtolower($current) === strtolower($service)) {
                        $service = $current;

                        return $attributes;
                    }
                })->only($service);

                return $services;
            })
            ->each(function ($attributes, $service) use (&$network) {

                $containerName = strtolower('laravel-'.$service);

                switch ($this->argument('option')) {
                    case "start":
                        $this->startContainer($containerName, $service, $attributes);
                        break;
                    case 'stop':
                        return $this->stopContainer($containerName, $service);
                        break;
                    case 'restart':
                        $this->restartContainer($containerName, $service, $attributes);
                        break;
                    case 'info':
                        break;
                }

                $network[] = $this->getServiceContainerNetwork($service, $containerName);
            });

        $this->renderNetworkTable($network);
    }

    /**
     * @param $string
     *
     * @throws Exception
     * @return string
     */
    public function parseDotEnvVars($string)
    {
        $found = preg_match_all('/((.*?)ENV\[([^|]+?)(\|(.+))?\](.*?))/', $string, $matches);

        if ($found) {
            foreach ($matches[3] as $variable) {
                $value = getenv($variable);

                if ($value === false) {
                    $this->error(sprintf("Environment variable '%s' is not set", $variable));
                    continue;
                }

                $string = preg_replace("/ENV\\[$variable\\]/su", $value, $string);
            }
        }

        return $string;
    }

    /**
     * @param $containerName
     * @param $service
     * @param $attribute
     */
    private function startContainer($containerName, $service, $attribute)
    {
        $tag = $attribute['repo'].':'.$attribute['tag'];

        if (!$this->docker->imageExists($tag)) {
            $this->docker->pull($tag);
        }

        if ($this->docker->isNamedContainerRunning($containerName)) {
            if (!$this->confirm("$service is already running, do you want restart?")) {
                return;
            }
            $this->stopContainer($containerName, $service);
        }

        $instances = isset($attribute['instances']) ? (int)$attribute['instances'] : 1;
        $containers = [];

        for ($i = 1; $i <= $instances; $i++) {
            $name = $containerName.'-'.$i;
            $containers[] = ['service' => $service, 'name' => $name, 'instance' => $i];
            $envVar = strtoupper($service)."$i=$name";
            putenv($envVar);
        }

        foreach ($containers as $container) {
            $this->runContainer($container, $attribute);
        }
    }

    /**
     * @param array $container
     * @param array $attributes
     *
     * @throws Exception
     */
    private function runContainer(array $container, array $attributes)
    {
        $name = $container['name'];
        $instance = $container['instance'];
        $service = $container['service'];

        putenv('INSTANCE_NAME='.$instance);

        $this->info('Starting '.$service.' '.$instance, false);

        if (isset($attributes['command'])) {
            $command = $attributes['command'];
        } else {
            if (isset($attributes['commands'])) {
                $command = $attributes['commands'][$instance];
            } else {
                throw new Exception("Service {$service} command or commands must be set");
            }
        }

        $command = '--name '.$name." ".$this->parseDotEnvVars($command);

        $this->docker->run($command);
    }

    /**
     * @param $containerName
     * @param $service
     */
    private function stopContainer($containerName, $service)
    {
        if ($this->option('service') != "" && strtolower($this->option('service')) != strtolower($service)) {
            return;
        }

        $this->info("Stopping $service");
        $this->docker->stopNamedContainer($containerName);
        $this->docker->removeNamedContainer($containerName);
    }

    /**
     * @param $containerName
     * @param $service
     * @param $attributes
     */
    private function restartContainer($containerName, $service, $attributes)
    {
        $this->info('Restarting '.$service);
        $this->stopContainer($containerName, $service);
        $this->runContainer($containerName, $service, $attributes);
    }

    /**
     * @param $service
     * @param $containerName
     *
     * @return array
     */
    private function getServiceContainerNetwork($service, $containerName)
    {
        $host = $this->docker->getNamedContainerIp($containerName);
        $port = $this->docker->getNamedContainerPorts($containerName);

        return [
            'service' => ucfirst($service),
            'host'    => $host,
            'port'    => implode(", ", $port),
        ];
    }

    /**
     * @param $network
     */
    private function renderNetworkTable($network)
    {
        if (empty($network)) {
            return;
        }

        $headers = ['Service', ' Host', 'Port'];
        $this->table($headers, $network);
    }

    /**
     * @param array $services
     */
    protected function addServices(array $services)
    {
        $this->services = array_merge_recursive($services, $this->services);
    }
}
