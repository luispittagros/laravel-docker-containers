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
    public $repo = 'schickling/beanstalkd';
    /**
     * @var string
     */
    public $tag = 'latest';

    /**
     * @return array
     */
    public function runCommand()
    {
        return [
            '-d --rm -p 11300:11300 schickling/beanstalkd',
        ];
    }
}
