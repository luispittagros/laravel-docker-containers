<?php
/**
 * @author Luís Pitta Grós <luis@idris.pt>
 */
namespace luisgros\docker;

use Exception;
use Illuminate\Support\Facades\Log;

/**
 * Class Containers
 *
 * @package luisgros\docker
 */
class Containers
{
    /**
     * @var array
     */
    protected $attributes;
    /**
     * @var array
     */
    protected $containers;
    /**
     * @var array
     */
    protected $container;
    /**
     * @var Docker
     */
    protected $docker;
    /**
     * @var string $host
     */
    protected $host;
    /**
     * @var array
     */
    protected $instances;
    /**
     * @var array
     */
    public $network = [];
    /**
     * @var boolean
     */
    protected $verbose;

    /**
     * @param Docker $docker
     */
    public function __construct(Docker $docker)
    {
        $this->docker = $docker;
    }

    /**
     * @param array $containers
     */
    public function init(array $containers)
    {
        collect($this->containers)
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
     * Start docker container
     */
    public function start()
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
        $this->setContainerNetwork();
        $this->postCommand();
    }

    /**
     * Stop docker container
     */
    public function stop()
    {
        collect($this->instances)->each(function ($container) {
            $this->info("Stopping {$container['service']} #{$container['instance']}");

            if (!$this->docker->isNamedContainerRunning($container['name'])) {
                if ($this->confirm("{$container['service']} is not running, do you want start?")) {
                    $this->start();
                }

                return;
            }

            try {
                $this->docker->stopNamedContainer($container['name']);
                $this->docker->removeNamedContainer($container['name']);
            } catch (Exception $e) {
                Log::warning($e->getMessage());
            }
        });
    }

    /**
     * Restart docker containers
     */
    public function restart()
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
    public function prepare($containers, $container = null)
    {
        $name = strtolower('laravel-'.$container);
        $verbose = isset($attributes['verbose']) ? $attributes['verbose'] : false;
        $instances = isset($attributes['instances']) ? (int)$attributes['instances'] : 1;
        $containers = [];

        for ($i = 1; $i <= $instances; $i++) {
            $containerName = $name.'-'.$i;
            $containers[] = ['service' => $container, 'name' => $containerName, 'instance' => $i];
            $envVar = strtoupper($container)."$i=$containerName";
            putenv($envVar);
        }

        $this->instances = $containers;
        $this->verbose = $verbose;
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

        $this->host = $host;

        $this->network[] = [
            'container' => ucfirst($this->container['service']).' #'.$this->container['instance'],
            'host'      => $host,
            'port'      => implode(", ", $port),
        ];
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

        if (isset($attributes['run-command'])) {
            $command = $attributes['run-command'];
        } else {
            if (isset($attributes['run-commands'])) {
                $command = $attributes['run-commands'][$this->container['instance']];
            } else {
                throw new Exception("Container {$this->container['service']} run command or commands must be set");
            }
        }

        return '--name '.$this->container['name'].' '.$this->parseEnvVars($command);
    }

    /**
     * Run pre execution commands
     */
    private function preCommand()
    {
        if (isset($this->attributes['pre-command'])) {
            foreach ($this->attributes['pre-command'] as $command) {
                if (is_callable($command)) {
                    $command();
                } else {
                    $this->docker->docker($this->parseEnvVars($command));
                }
            }
        }
    }

    /**
     * Run post execution commands
     */
    private function postCommand()
    {
        if (isset($this->attributes['post-command'])) {
            foreach ($this->attributes['post-command'] as $command) {
                if (is_callable($command)) {
                    $command();
                } else {
                    $this->docker->docker($this->parseEnvVars($command));
                }
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
        $this->docker->run($command, $this->verbose);
    }

    /**
     * @return string
     */
    protected function getCurrentHost()
    {
        return $this->host;
    }
}
