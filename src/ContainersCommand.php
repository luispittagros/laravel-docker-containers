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
     * @var \luisgros\docker\Containers
     */
    protected $dockerContainers;

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
        $container = $this->option('name') ? : null;
        $command   = $this->argument('option');

        $this->dockerContainers->loadContainers($this->containers);
        $this->dockerContainers->init($command, $container);

        $this->displayNetworkInformation();
    }

    /**
     * Render a console table displaying network information
     * for each docker container running
     */
    private function displayNetworkInformation()
    {
        $network = $this->dockerContainers->getNetworkInformation();

        if (empty($network)) {
            return;
        }

        $headers = ['Container', 'Host', 'Port'];
        $this->table($headers, $network);
    }
}
