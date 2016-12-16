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
}
