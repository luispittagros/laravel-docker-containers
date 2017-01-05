<?php
/**
 * @author Luís Pitta Grós <luis@idris.pt>
 */
namespace luisgros\docker;

class ContainerVariables
{
    /**
     * @var int
     */
    public $container;
    /**
     * @var int
     */
    public $instance;
    /**
     * @var string
     */
    public $host;
    /**
     * @var array
     */
    public $ports = [];
    /**
     * @var array
     */
    public $instances = [];
}
