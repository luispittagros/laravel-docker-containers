<?php
/**
 * @author Luís Pitta Grós <luis@idris.pt>
 */
namespace luisgros\docker;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use luisgros\docker\containers\Container;
use Symfony\Component\Console\Exception\CommandNotFoundException;

/**
 * Class Containers
 *
 * @package luisgros\docker
 */
class Containers
{
    /**
     * @var \Illuminate\Console\Command
     */
    protected $artisan;
    /**
     * @var \luisgros\docker\containers\Container
     */
    protected $container;
    /**
     * @var array
     */
    protected $containers = [];
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
    protected $instance = [];
    /**
     * @var array
     */
    protected $instances = [];

    /**
     * @param Docker $docker
     */
    public function __construct(Docker $docker)
    {
        $this->docker = $docker;
    }

    /**
     * @param \Illuminate\Console\Command $command
     */
    public function register(Command $command)
    {
        $this->artisan = $command;
    }

    /**
     * @param array $containers
     */
    public function load(array $containers)
    {
        $this->containers = array_merge($containers, $this->containers);
    }

    /**
     * @param string      $command
     * @param string|null $name
     * @throws Exception
     */
    public function init($command, $name = null)
    {
        $containers = collect($this->containers)
            ->reject(function ($current) use ($name) {
                if ($name === null) {
                    return false;
                }

                if (strtolower(class_basename($current)) === strtolower($name)) {
                    return false;
                }

                return true;
            })
            ->each(function ($container) use ($command) {
                if (!in_array($command, ['start', 'stop', 'restart'])) {
                    throw new CommandNotFoundException(sprintf("Command '%s' not found", $command));
                }
                $this->prepare(new $container());
                $this->{$command}();
            });

        if ($containers->isEmpty()) {
            throw new Exception(sprintf("Container '%s' not found", ucwords($name)));
        }

        $this->displayNetwork();
    }

    /**
     * Start containers
     */
    public function start()
    {
        $this->pullImage();

        foreach ($this->instances as $instance) {
            $this->instance = $instance;

            if ($this->docker->isNamedContainerRunning($instance['container'])) {
                if ($this->artisan->confirm("{$instance['container']} is already running, do you want restart?")) {
                    $this->restart();
                }
                continue;
            }
            $this->runContainer();
        }
    }

    /**
     * Stop containers
     */
    public function stop()
    {
        foreach ($this->instances as $instance) {
            $this->artisan->info("Stopping {$instance['service']} #{$instance['instance']}");

            if (!$this->docker->isNamedContainerRunning($instance['container'])) {
                if ($this->artisan->confirm("{$instance['service']} is not running, do you want start?")) {
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
        }

        $this->network = [];
    }

    /**
     * Restart containers
     */
    public function restart()
    {
        $this->stop();
        $this->start();
    }

    /**
     * Prepare container
     *
     * @param \luisgros\docker\containers\Container $container
     */
    public function prepare(Container $container)
    {
        $this->container = $container;
        $this->container->vars = new ContainerVariables();

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

        $this->container->vars->instances = $instances;
        $this->instances = $instances;
    }

    /**
     * Run container
     */
    private function runContainer()
    {
        $this->artisan->info("Starting {$this->instance['service']} #{$this->instance['instance']}", false);

        $this->container->vars->instance = $this->instance['instance'];
        $this->container->vars->container = $this->instance['container'];

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
        $ports = $this->docker->getNamedContainerPorts($this->instance['container']);

        $this->container->vars->host = $host;
        $this->container->vars->ports = $ports;

        $this->network[] = [
            'container' => ucfirst($this->instance['service']).' #'.$this->instance['instance'],
            'host'      => $host,
            'port'      => implode(", ", $ports),
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
        $command = $this->container->runCommand()[$this->instance['instance'] - 1];

        return '--name '.$this->instance['container'].' '.$this->parseEnvVars($command);
    }

    /**
     * Run pre execution commands
     */
    private function preCommand()
    {
        $this->container->preCommand();
    }

    /**
     * Run post execution commands
     */
    private function postCommand()
    {
        $this->container->postCommand();
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
     * Render a console table displaying network information
     * for each docker container running
     */
    private function displayNetwork()
    {
        if (empty($this->network)) {
            return;
        }

        $headers = ['Container', 'Host', 'Port'];
        $this->artisan->table($headers, $this->network);
    }
}
