<?php

namespace idrislab\DockerServices;

use Docker\DockerClient;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Class Docker
 *
 * @package idrislab\DockerServices
 */
class Docker
{
    /**
     * @var bool
     */
    private $sudo = false;
    /**
     * @var \Docker\Docker
     */
    private $client;

    /**
     * Docker constructor.
     *
     * @param \Docker\Docker $docker
     */
    public function __construct(\Docker\Docker $docker)
    {
        $client = new DockerClient([
            'remote_socket' => getenv('DOCKER_SOCKET') ? : 'unix:///var/run/docker.sock',
        ]);

        $this->client= new $docker($client);
    }

    /**
     * @param      $image
     * @param bool $verbose
     *
     * @return string
     */
    public function pull($image, $verbose = true)
    {
        return $this->docker("pull $image", $verbose);
    }

    /**
     * @param      $options
     * @param bool $verbose
     *
     * @return string
     */
    public function run($options, $verbose = false)
    {
        return $this->docker("run $options", $verbose);
    }

    /**
     * @param $name
     *
     * @return bool
     */
    public function isNamedContainerRunning($name)
    {
        $containerManager = $this->client->getContainerManager();
        $containers = $containerManager->findAll();

        foreach ($containers as $container) {
            if ($container->getNames()[0] == "/".$name && $container->getState() == "running") {
                return true;
            }
        }
        return false;
    }

    /**
     * @param $name
     *
     * @return string
     */
    public function getNamedContainerIp($name)
    {
        $containerManager = $this->client->getContainerManager();
        $containers = $containerManager->findAll();

        foreach ($containers as $container) {
            if ($container->getNames()[0] == "/".$name) {
                return $container->getNetworkSettings()->getNetworks()['bridge']->getIPAddress();
            }
        }

        return '';
    }

    /**
     * @param $name
     *
     * @return bool
     */
    public function getNamedContainerPorts($name)
    {
        $containerManager = $this->client->getContainerManager();
        $containers = $containerManager->findAll();

        $ports = [];

        foreach ($containers as $container) {
            if ($container->getNames()[0] == "/".$name) {
                $containerPorts = $container->getPorts();
                foreach ($containerPorts as $port) {
                    $ports[] = $port->getPrivatePort();
                }
            }
        }
        return $ports;
    }

    /**
     * @param $name
     *
     * @return bool
     */
    public function stopNamedContainer($name)
    {
        $this->docker("stop $name", false);
    }

    /**
     * @param $name
     *
     * @return bool
     */
    public function removeNamedContainer($name)
    {
        $this->docker("rm $name", false);
    }

    /**
     * @param $tag
     *
     * @return bool
     */
    public function imageExists($tag)
    {
        $imageManager = $this->client->getImageManager();
        $images = $imageManager->findAll();

        foreach ($images as $image) {
            if ($image->getRepoTags()[0] == $tag) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param      $options
     * @param bool $verbose
     *
     * @return string
     */
    private function docker($options, $verbose = true)
    {
        $sudo = $this->sudo ? 'sudo' : '';

        $process = new Process("$sudo docker $options");
        $process->run(function ($type, $buffer) use ($verbose) {
            if (!$verbose) {
                return;
            }

            if (Process::ERR === $type) {
                print $buffer;
            } else {
                print $buffer;
            }
        });

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        return $process->getOutput();
    }

    /**
     * @param bool $sudo
     *
     * @return $this
     */
    public function sudo($sudo = true)
    {
        $this->sudo = $sudo;

        return $this;
    }
}
