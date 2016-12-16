<?php
/**
 * @author Luís Pitta Grós <luis@idris.pt>
 */

namespace luisgros\docker\containers;

class Redis extends Container
{
    /**
     * @var string
     */
    protected $repo = 'redis';
    /**
     * @var string
     */
    protected $tag = 'alpine';

    /**
     * @return string
     */
    public function run_command()
    {
        return '-d --rm -p ENV[REDIS_PORT]:ENV[REDIS_PORT] \\'.
        'redis redis-server --appendonly yes --requirepass ENV[REDIS_PASSWORD]';
    }
}
