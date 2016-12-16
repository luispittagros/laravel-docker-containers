<?php
/**
 * @author Luís Pitta Grós <luis@idris.pt>
 */
namespace luisgros\docker\containers;

class Benstalkd extends Container
{
    /**
     * @var string
     */
    protected $repo = 'schickling/beanstalkd';
    /**
     * @var string
     */
    protected $tag = 'latest';

    /**
     * @return string
     */
    public function run_command()
    {
        return '-d --rm -p 11300:11300 schickling/beanstalkd';
    }
}
