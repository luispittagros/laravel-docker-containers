<?php
/**
 * @author Luís Pitta Grós <luis@idris.pt>
 */
namespace luisgros;

use Illuminate\Console\Command;
use Exception;

/**
 * Class DockerContainers
 *
 * @package luisgros
 */
class DockerContainers extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'containers {option} {--name=}';
    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Automate your docker containers';
    /**
     * The services
     *
     * @var string
     */
    protected $containers = [
        'MySQL'      => [
            'repo'    => 'mysql',
            'tag'     => 'latest',
            'command' =>
                '-d -v /mysql:/var/lib/mysql \\'.
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
                '-d -p ENV[REDIS_PORT]:ENV[REDIS_PORT] \\'.
                'redis redis-server --appendonly yes --requirepass ENV[REDIS_PASSWORD]',
        ],
        'Beanstalkd' => [
            'repo'    => 'schickling/beanstalkd',
            'tag'     => 'latest',
            'command' => '-d -p 11300:11300 schickling/beanstalkd',
        ],
        'Memcached'  => [
            'repo'    => 'memcached',
            'tag'     => 'alpine',
            'command' => '-d -p ENV[MEMCACHED_PORT]:ENV[MEMCACHED_PORT] memcached',
        ],
    ];
    /**
     * @var Docker
     */
    protected $docker;
    protected $network = [];

    /**
     * Create a new command instance.
     *
     * @param Docker $docker
     */
    public function __construct(Docker $docker)
    {
        $this->docker = $docker;
        parent::__construct();
    }

    /**
     * @throws Exception
     */
    public function handle()
    {
        $containers = getenv('DOCKER_CONTAINERS');

        if (!$containers) {
            throw new Exception("Environment variable DOCKER_CONTAINERS is not set");
        }

        collect(explode(",", $containers))
            ->mapWithKeys(function ($container) {
                //Retrieve only current container attributes
                $containers = collect($this->containers)->map(function ($attributes, $current) use (&$container) {
                    if (strtolower($current) === strtolower($container)) {
                        $container = $current;

                        return $attributes;
                    }
                })->only($container);

                return $containers;
            })
            ->each(function ($attributes, $container) {

                switch ($this->argument('option')) {
                    case "start":
                        $this->startContainer($container, $attributes);
                        break;
                    case 'stop':
                        return $this->stopContainer($container, $attributes);
                        break;
                    case 'restart':
                        $this->restartContainer($container, $attributes);
                        break;
                    default:
                }
            });

        $this->renderNetworkTable();
    }

    /**
     * @param array $containers
     */
    protected function addContainers(array $containers)
    {
        $this->containers = array_merge_recursive($containers, $this->containers);
    }

    /**
     * @param string $container
     * @param array  $attributes
     */
    private function startContainer($container, array $attributes)
    {
        $tag = $attributes['repo'].':'.$attributes['tag'];
        if (!$this->docker->imageExists($tag)) {
            $this->docker->pull($tag);
        }

        $instances = $this->getContainerInstances($container, $attributes);

        foreach ($instances as $instance) {
            if ($this->docker->isNamedContainerRunning($instance['name'])) {
                if (!$this->confirm("$container is already running, do you want restart?")) {
                    continue;
                }

                $this->restartContainer($instance, $attributes);
                break;
            }

            $this->runContainer($instance, $attributes);
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

        putenv('INSTANCE_NAME='.$instance);

        $this->info("Starting {$container['service']} $instance", false);

        if (isset($attributes['command'])) {
            $command = $attributes['command'];
        } else {
            if (isset($attributes['commands'])) {
                $command = $attributes['commands'][$instance];
            } else {
                throw new Exception("Container {$container['service']} command or commands must be set");
            }
        }

        if (isset($attributes['docker']['pre'])) {
            foreach ($attributes['docker']['pre'] as $command) {
                $this->docker->docker($command);
            }
        }

        $command = '--name '.$name." ".$this->parseDotEnvVars($command);
        $this->docker->run($command);

        $network = isset($attributes['network']) ? $attributes['network'] : 'bridge';
        $this->network[] = $this->getContainerNetwork($container, $network);

        if (isset($attributes['docker']['post'])) {
            foreach ($attributes['docker']['post'] as $command) {
                $this->docker->docker($command);
            }
        }
    }

    /**
     * @param string $container
     * @param array  $attributes
     */
    private function stopContainer($container, array $attributes)
    {
        if ($this->option('service') != "") {
            if (strtolower($this->option('service')) != strtolower($container)) {
                return;
            }
        }

        $containers = $this->getContainers($container, $attributes);

        collect($containers)->each(function ($container) use ($container) {
            $this->info("Stopping $container ".$container['instance']);
            $this->docker->stopNamedContainer($container['name']);
            $this->docker->removeNamedContainer($container['name']);
        });
    }


    /**
     * @param string $container
     * @param array  $attributes
     */
    private function restartContainer($container, array $attributes)
    {
        $this->info('Restarting '.$container);
        $this->stopContainer($container, $attributes);
        $this->startContainer($container, $attributes);
    }

    /**
     * @param string $container
     * @param array  $attributes
     *
     * @return array
     */
    private function getContainerInstances($container, array $attributes)
    {
        $name = strtolower('laravel-'.$container);
        $instances = isset($attributes['instances']) ? (int)$attributes['instances'] : 1;
        $containers = [];

        for ($i = 1; $i <= $instances; $i++) {
            $containerName = $name.'-'.$i;
            $containers[] = ['service' => $container, 'name' => $containerName, 'instance' => $i];
            $envVar = strtoupper($container)."$i=$containerName";
            putenv($envVar);
        }

        return $containers;
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
     * @param array $container
     * @param string $network
     *
     * @return array
     */
    private function getContainerNetwork($container, $network)
    {
        $host = $this->docker->getNamedContainerIp($container['name'], $network);
        $port = $this->docker->getNamedContainerPorts($container['name']);

        return [
            'service' => ucfirst($container['service']),
            'host'    => $host,
            'port'    => implode(", ", $port),
        ];
    }

    private function renderNetworkTable()
    {
        if (empty($this->network)) {
            return;
        }

        $headers = ['Service', ' Host', 'Port'];
        $this->table($headers, $this->network);
    }
}
