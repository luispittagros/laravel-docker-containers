<?php
/**
 * @author Luís Pitta Grós <luis@idris.pt>
 */
namespace luisgros\docker;

use Exception;
use Illuminate\Support\Facades\Log;
use luisgros\docker\containers\Container;

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
    protected $containers = [];
    /**
     * @var array
     */
    protected $container;
    /**
     * @var Docker
     */
    protected $docker;
    /**
     * @var array
     */
    protected $network = [];
    /**
     * @var array
     */
    private $instances = [];
    /**
     * @var array
     */
    private $instance = [];

    /**
     * @param Docker $docker
     */
    public function __construct(Docker $docker)
    {
        $this->docker = $docker;
    }

    /**
     * @param string      $command
     * @param string|null $name
     */
    public function init($command, $name = null)
    {
        collect($this->containers)
            ->reject(function ($current) use ($name) {
                if ($name === null) {
                    return false;
                }

                if (strtolower(class_basename($current)) === strtolower($name)) {
                    return false;
                }

                return true;
            })->each(function ($container) use ($command) {

                $this->prepare(new $container());

                switch ($command) {
                    case 'start':
                        $this->start();
                        break;
                    case 'stop':
                        return $this->stop();
                        break;
                    case 'restart':
                        $this->restart();
                        break;
                    default:
                        throw new Exception('Container init method command not found or not defined');
                }
            });
    }

    /**
     * Start docker container
     */
    public function start()
    {
        $this->pullImage();

        foreach ($this->instances as $instance) {
            $this->instance = $instance;

            if ($this->docker->isNamedContainerRunning($instance['container'])) {
                if ($this->confirm("{$instance['container']} is already running, do you want restart?")) {
                    $this->restart();
                }
                continue;
            }

            $this->runContainer();
        }
    }

    /**
     * Stop docker container
     */
    public function stop()
    {
        collect($this->instances)
            ->each(function ($instance) {
                $this->info("Stopping {$instance['service']} #{$instance['instance']}");

                if (!$this->docker->isNamedContainerRunning($instance['container'])) {
                    if ($this->confirm("{$instance['service']} is not running, do you want start?")) {
                        $this->start();
                    }

                    return;
                }

                try {
                    $this->docker->stopNamedContainer($instance['container']);
                    $this->docker->removeNamedContainer($instance['container']);
                } catch (Exception $e) {
                    Log::warning($e->getMessage());
                }
            });
    }

    /**
     * Restart docker container
     */
    public function restart()
    {
        $this->stop();
        $this->start();
    }

    /**
     * Prepare container to be used
     *
     * @param \luisgros\docker\containers\Container $container
     */
    public function prepare(Container $container)
    {
        $this->container = $container;
        $basename = class_basename($container);
        $instances = [];

        for ($i = 1; $i <= $container->instances; $i++) {
            $name = strtolower("laravel-$basename-$i");

            $instances[] = [
                'service'   => $basename,
                'container' => $name,
                'instance'  => $i,
            ];

            putenv(strtoupper($basename)."$i=$name");
        }

        $this->instances = $instances;
    }

    /**
     * Run docker container
     */
    private function runContainer()
    {
        $this->info("Starting {$this->instance['service']} #{$this->instance['instance']}", false);

        putenv('CURRENT_INSTANCE='.$this->instance['instance']);

        $this->preCommand();
        $this->runCommand();
        $this->postCommand();
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
    private function setNetwork()
    {
        $host = $this->docker->getNamedContainerIp($this->instance['container'], $this->container->network);
        $port = $this->docker->getNamedContainerPorts($this->instance['container']);

        $this->network[] = [
            'container' => ucfirst($this->instance['service']).' #'.$this->instance['instance'],
            'host'      => $host,
            'port'      => implode(", ", $port),
        ];
    }

    /**
     * Pull image from docker hub
     */
    private function pullImage()
    {
        $image = $this->container->repo.':'.$this->container->tag;
        if (!$this->docker->imageExists($image)) {
            $this->docker->pull($image);
        }
    }

    /**
     * Get normalized docker run command
     *
     * @return string
     * @throws \Exception
     */
    private function getCommand()
    {
        if (is_callable([$this->container, 'runCommand'], false, $runCommand)) {
            $command = $runCommand();
        } else {
            if (is_callable([$this->container, 'runCommands'], false, $runCommands)) {
                $command = $runCommands()[$this->instance['instance']];
            } else {
                throw new Exception(
                    "Container {$this->instance['service']} runCommand or runCommands method must be defined"
                );
            }
        }

        return '--name '.$this->instance['container'].' '.$this->parseEnvVars($command);
    }

    /**
     * Run pre execution commands
     */
    private function preCommand()
    {
        if (is_callable([$this->container, 'preCommand'], false, $preCommand)) {
            $preCommand();
        }
    }

    /**
     * Run post execution commands
     */
    private function postCommand()
    {
        if (is_callable([$this->container, 'postCommand'], false, $postCommand)) {
            $postCommand();
        }
    }

    /**
     * Perform docker run
     */
    private function runCommand()
    {
        $this->docker->run($this->getCommand(), $this->container->verbose);
        $this->setNetwork();
    }

    /**
     * @return array
     */
    public function getNetwork()
    {
        return $this->network;
    }

    /**
     * @param array $containers
     */
    public function loadContainers(array $containers)
    {
        $this->containers = array_merge($containers, $this->containers);
    }
}
