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
    /**
     * @var array
     */
    protected $attributes;
    /**
     * @var array
     */
    protected $network = [];
    /**
     * @var array
     */
    protected $instances;
    /**
     * @var array
     */
    protected $container;

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
                $containers = collect($this->containers)
                    ->map(function ($attributes, $current) use (&$container) {
                        if ($this->option('name') === null) {
                            if (strtolower($current) === strtolower($container)) {
                                $container = $current;

                                return $attributes;
                            }
                        } elseif (strtolower($this->option('name')) === strtolower($current)) {
                            $container = $current;

                            return $attributes;
                        }
                    })
                    ->only($container);

                return $containers;
            })
            ->each(function ($attributes, $container) {

                var_dump($container);

                $this->prepare($container, $attributes);

                switch ($this->argument('option')) {
                    case "start":
                        $this->start();
                        break;
                    case 'stop':
                        return $this->stop();
                        break;
                    case 'restart':
                        $this->restart();
                        break;
                    default:
                }
            });

        $this->renderNetworkTable();
    }

    /**
     * Add containers
     *
     * @param array $containers
     */
    protected function addContainers(array $containers)
    {
        $this->containers = array_merge_recursive($containers, $this->containers);
    }

    /**
     * Start docker container
     */
    private function start()
    {
        $this->pullImage();

        foreach ($this->instances as $container) {
            $this->container = $container;

            if ($this->docker->isNamedContainerRunning($container['name'])) {
                if ($this->confirm("{$container['name']} is already running, do you want restart?")) {
                    $this->restart();
                }
                continue;
            }

            $this->runContainer();
        }
    }

    /**
     * Run docker container
     */
    private function runContainer()
    {
        putenv('CURRENT_INSTANCE='.$this->container['instance']);

        $this->info("Starting {$this->container['service']} #{$this->container['instance']}", false);

        $this->preCommand();
        $this->runCommand($this->getContainerCommand());
        $this->postCommand();

        $this->setContainerNetwork();
    }

    /**
     * Stop docker container
     */
    private function stop()
    {
        collect($this->instances)->each(function ($container) {
            $this->info("Stopping {$container['service']} #{$container['instance']}");

            if (!$this->docker->isNamedContainerRunning($container['name'])) {
                if ($this->confirm("{$container['service']} is not running, do you want start?")) {
                    $this->start();
                }

                return;
            }

            $this->docker->stopNamedContainer($container['name']);
            $this->docker->removeNamedContainer($container['name']);
        });
    }

    /**
     * Restart docker containers
     */
    private function restart()
    {
        $this->stop();
        $this->start();
    }

    /**
     * Prepare environment
     *
     * @param string $container
     * @param array  $attributes
     *
     * @return array
     */
    private function prepare($container, array $attributes)
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

        $this->instances = $containers;
        $this->attributes = $attributes;
    }

    /**
     * Parse environment variables
     *
     * @param $string
     *
     * @throws Exception
     * @return string
     */
    public function parseEnvVars($string)
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
     * Set container network information
     *
     * @return array
     */
    private function setContainerNetwork()
    {
        $network = isset($this->attributes['network']) ? $this->attributes['network'] : 'bridge';

        $host = $this->docker->getNamedContainerIp($this->container['name'], $network);
        $port = $this->docker->getNamedContainerPorts($this->container['name']);

        $this->network[] = [
            'container' => ucfirst($this->container['service']).' #'.$this->container['instance'],
            'host'      => $host,
            'port'      => implode(", ", $port),
        ];
    }

    /**
     * Render a console table displaying network information
     * for each docker container running
     */
    private function renderNetworkTable()
    {
        if (empty($this->network)) {
            return;
        }

        $headers = ['Container', 'Host', 'Port'];
        $this->table($headers, $this->network);
    }

    /**
     * Pull image from docker hub
     */
    private function pullImage()
    {
        $tag = $this->attributes['repo'].':'.$this->attributes['tag'];
        if (!$this->docker->imageExists($tag)) {
            $this->docker->pull($tag);
        }
    }

    /**
     * Get normalized docker run command
     *
     * @return string
     * @throws \Exception
     */
    private function getContainerCommand()
    {
        $attributes = $this->attributes;

        if (isset($attributes['command'])) {
            $command = $attributes['command'];
        } else {
            if (isset($attributes['commands'])) {
                $command = $attributes['commands'][$this->container['instance']];
            } else {
                throw new Exception("Container {$this->container['service']} command or commands must be set");
            }
        }

        return '--name '.$this->container['name'].' '.$this->parseEnvVars($command);
    }

    /**
     * Run pre execution commands
     */
    private function preCommand()
    {
        if (isset($attributes['docker']['pre'])) {
            foreach ($attributes['docker']['pre'] as $command) {
                $this->docker->docker($this->parseEnvVars($command));
            }
        }
    }

    /**
     * Run post execution commands
     */
    private function postCommand()
    {
        if (isset($attributes['docker']['post'])) {
            foreach ($attributes['docker']['post'] as $command) {
                $this->docker->docker($this->parseEnvVars($command));
            }
        }
    }

    /**
     * Perform docker run
     *
     * @param string $command
     */
    private function runCommand($command)
    {
        $this->docker->run($command);
    }
}
