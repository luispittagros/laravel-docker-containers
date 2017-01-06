<?php
/**
 * @author Luís Pitta Grós <luis@idris.pt>
 */
namespace luisgros\docker;

use Exception;
use Illuminate\Console\Command;
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
     * @param string     $command
     * @param array|null $containers
     *
     * @throws \Exception
     */
    public function init($command, $containers = null)
    {
        $containers = collect($this->containers)
            ->reject(function ($current) use ($containers) {
                if ($containers === null) {
                    return false;
                }

                foreach ($containers as $container) {
                    if (strtolower(class_basename($current)) === strtolower($container)) {
                        return false;
                    }
                }

                return true;
            })
            ->each(function ($container) use ($command) {
                $this->prepare(new $container());
                $this->{$command}();
            });

        if ($containers->isEmpty()) {
            throw new Exception(sprintf("Container(s) '%s' not found", ucwords(implode(' ', $containers))));
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
            if ($this->docker->isNamedContainerRunning($instance['container'])) {
                if ($this->artisan->confirm("{$instance['container']} is already running, do you want restart?")) {
                    $this->restart();
                }
                continue;
            }

            $this->instance = $instance;

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
    }

    /**
     * Restart containers
     */
    public function restart()
    {
        $this->stop();
        sleep(1);
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
        $this->container->setDockerClient($this->docker);
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

            putenv("CONTAINER_I$i=$name");
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

        putenv("INSTANCE_ID={$this->instance['instance']}");
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
                    $this->artisan->error(sprintf("Environment variable '%s' is not set", $variable));
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

        if (($host == '') || empty($ports)) {
            return;
        }

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
        //if (!$this->docker->imageExists($image)) {
        $this->docker->pull($image);
        //}
    }

    /**
     * Get normalized docker run command
     *
     * @return string
     * @throws \Exception
     */
    private function getCommand()
    {
        var_dump($this->instance['instance']-1);

        $command = $this->container->runCommand()[($this->instance['instance']-1)];

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
