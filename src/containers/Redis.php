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
    public $repo = 'redis';
    /**
     * @var string
     */
    public $tag = 'alpine';

    /**
     * @return array
     */
    public function runCommand()
    {
        return [
            '-d --rm -p ENV[REDIS_PORT]:ENV[REDIS_PORT] \\'.
            'redis redis-server --appendonly yes --requirepass ENV[REDIS_PASSWORD]',
        ];
    }
}
