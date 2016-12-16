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
    protected $repo = 'memcached';
    /**
     * @var string
     */
    protected $tag = 'alpine';

    /**
     * @return string
     */
    public function run_command()
    {
        return '-d -p ENV[MEMCACHED_PORT]:ENV[MEMCACHED_PORT] memcached';
    }
}
