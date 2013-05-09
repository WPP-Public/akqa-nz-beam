<?php

namespace Heyday\Component\Beam\Command;

use Symfony\Component\Console\Command\Command as BaseCommand;
use Heyday\Component\Beam\Config\JsonConfigLoader;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

abstract class Command extends BaseCommand
{
    /**
     * @var
     */
    protected $jsonConfigLoader;
    /**
     * @return JsonLoader
     */
    protected function getJsonConfigLoader($path)
    {
        if (null === $this->jsonConfigLoader) {

            $paths = array();

            while ($path !== end($paths)) {
                $paths[] = $path;
                $path = dirname($path);
            }

            $this->jsonConfigLoader = new JsonConfigLoader(
                new FileLocator(
                    $paths
                )
            );
        }

        return $this->jsonConfigLoader;
    }
    /**
     * @param  InputInterface $input
     * @return mixed
     */
    protected function getConfig(InputInterface $input, $path)
    {
        return $this->getJsonConfigLoader($path)->load(
            $input->getOption('configfile')
        );
    }
    /**
     * @return $this
     */
    protected function addConfigOption()
    {
        $this->addOption(
            'configfile',
            '',
            InputOption::VALUE_REQUIRED,
            'The config file name',
            'beam.json'
        );

        return $this;
    }
}
