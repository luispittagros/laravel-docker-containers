<?php
/**
 * @author Luís Pitta Grós <luis@idris.pt>
 */
namespace luisgros\docker\containers;

class MySQL extends Container
{
    /**
     * @var string
     */
    public $repo = 'mysql';
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
            '-d --rm -v /mysql:/var/lib/mysql \\'.
            '-e MYSQL_USER=ENV[DB_USERNAME] \\'.
            '-e MYSQL_PASSWORD=ENV[DB_PASSWORD] \\'.
            '-e MYSQL_DATABASE=ENV[DB_DATABASE] \\'.
            '-e MYSQL_ROOT_PASSWORD=ENV[DB_PASSWORD] mysql \\'.
            '--character-set-server=utf8mb4 --collation-server=utf8mb4_unicode_ci',
        ];
    }
}
