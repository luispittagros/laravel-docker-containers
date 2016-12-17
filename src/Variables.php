<?php
/**
 * @author Luís Pitta Grós <luis@idris.pt>
 */
namespace luisgros\docker;

class Variables
{
    /**
     * @var int
     */
    public $instance;
    /**
     * @var array
     */
    public $instances = [];
    /**
     * @var string
     */
    public $host;
    /**
     * @var array
     */
    public $ports = [];
}
