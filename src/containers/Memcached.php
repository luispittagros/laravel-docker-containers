<?php
/**
 * @author Luís Pitta Grós <luis@idris.pt>
 */
namespace luisgros\docker\containers;

class Memcached extends Container
{
    /**
     * @var string
     */
    public $repo = 'memcached';
    /**
     * @var string
     */
    public $tag = 'alpine';

    /**
     * @return string
     */
    public function runCommand()
    {
        return '-d -p ENV[MEMCACHED_PORT]:ENV[MEMCACHED_PORT] memcached';
    }
}
