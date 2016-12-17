<?php
/**
 * @author Luís Pitta Grós <luis@idris.pt>
 */
namespace luisgros\docker\containers;

use Exception;

abstract class Container
{
    /**
     * @var string
     */
    public $repo;
    /**
     * @var string
     */
    public $tag;
    /**
     * @var string
     */
    public $network = 'bridge';
    /**
     * @var int
     */
    public $instances = 1;
    /**
     * @var boolean
     */
    public $verbose = false;
    /**
     * @var \luisgros\docker\ContainerVariables
     */
    public $vars;

    /**
     * @return array
     */
    abstract public function runCommand();

    /**
     * @return array
     */
    public function postCommand()
    {
    }

    /**
     * @return array
     */
    public function preCommand()
    {
    }

    /**
     * @param string $host
     * @param int    $retry
     * @param int    $sleep
     *
     * @return string
     */
    protected function ping($host, $retry = 900, $sleep = 1)
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
