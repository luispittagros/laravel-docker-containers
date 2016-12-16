<?php
/**
 * @author Luís Pitta Grós <luis@idris.pt>
 */
namespace luisgros\docker\containers;

abstract class Container
{
    /**
     * @var string
     */
    protected $repo;
    /**
     * @var string
     */
    protected $tag;
    /**
     * @var int
     */
    protected $instances;
    /**
     * @var boolean
     */
    protected $verbose;

    /**
     * @return string
     */
    public function run_command()
    {
    }

    /**
     * @return array
     */
    public function run_commands()
    {
    }

    /**
     * @return array
     */
    public function post_command()
    {
    }

    /**
     * @return array
     */
    public function pre_command()
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
