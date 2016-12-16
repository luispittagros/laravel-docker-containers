<?php
/**
 * @author Luís Pitta Grós <luis@idris.pt>
 */
namespace luisgros\docker;

use Exception;
use Illuminate\Console\Command;

/**
 * Class ContainersCommand
 *
 * @package luisgros\docker
 */
class ContainersCommand extends Command
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
     * Containers to be loaded
     *
     * @var array
     */
    protected $containers = [];

    /**
     * ContainersCommand constructor.
     *
     * @param \luisgros\docker\Containers $dockerContainers
     */
    public function __construct(Containers $dockerContainers)
    {
        $this->dockerContainers = $dockerContainers;
    }

    /**
     * @throws Exception
     */
    public function handle()
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

        $this->displayNetworkInformation();
    }

    /**
     * Render a console table displaying network information
     * for each docker container running
     */
    private function displayNetworkInformation()
    {
        if (empty($this->dockerContainers->network)) {
            return;
        }

        $headers = ['Container', 'Host', 'Port'];
        $this->table($headers, $this->dockerContainers->network);
    }

    /**
     * @param string $host
     * @param int    $retry
     * @param int    $sleep
     *
     * @return string
     */
    public function ping($host, $retry = 900, $sleep = 1)
    {
        $connected = false;
        $retries = 0;

        $contextOptions = [
            "ssl" => [
                "verify_peer"      => false,
                "verify_peer_name" => false,
            ],
        ];

        while (!$connected && $retry > $retries) {
            try {
                if (file_get_contents($host, false, stream_context_create($contextOptions))) {
                    $connected = true;
                }
            } catch (Exception $e) {
                print('.');
                $retries++;
                sleep($sleep);
            }
        }
    }
}
