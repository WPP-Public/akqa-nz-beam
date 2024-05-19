<?php

namespace Heyday\Beam;

use Heyday\Beam\Command;
use Symfony\Component\Console\Application as BaseApplication;
use Symfony\Component\Console\Helper\HelperSet;
use Symfony\Component\Console\Input\InputDefinition;

/**
 * Class Application
 * @package Heyday\Beam
 */
class Application extends BaseApplication
{
    /**
     * Set the name and version (with the version a dummy var to be swapped out by the compiler)
     */
    public function __construct()
    {
        parent::__construct('Beam', '~package_version~');
    }
    /**
     * Set the default commands
     * @return array
     */
    protected function getDefaultCommands(): array
    {
        $commands = parent::getDefaultCommands();

        $commands[] = new Command\UpCommand();
        $commands[] = new Command\DownCommand();
        $commands[] = new Command\InitCommand();
        $commands[] = new Command\SelfUpdateCommand();
        $commands[] = new Command\MakeChecksumsCommand();
        $commands[] = new Command\BeamCompletionCommand();
        $commands[] = new Command\ValidateCommand();

        return $commands;
    }

    /**
     * @return InputDefinition
     */
    protected function getDefaultInputDefinition(): InputDefinition
    {
        $definition = parent::getDefaultInputDefinition();

        $options = $definition->getOptions();

        if (isset($options['no-interaction'])) {
            unset($options['no-interaction']);
            $definition->setOptions($options);
        }

        return $definition;
    }

    /**
     * Return the default helper set
     * @return \Symfony\Component\Console\Helper\HelperSet
     */
    protected function getDefaultHelperSet(): HelperSet
    {
        return new HelperSet();
    }
}
