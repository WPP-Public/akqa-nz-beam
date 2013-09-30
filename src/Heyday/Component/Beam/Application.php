<?php

namespace Heyday\Component\Beam;

use Heyday\Component\Beam\Command;
use Heyday\Component\Beam\Helper;
use Symfony\Component\Console\Application as BaseApplication;
use Symfony\Component\Console\Helper\HelperSet;

/**
 * Class Application
 * @package Heyday\Component\Beam
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
    protected function getDefaultCommands()
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
     * Return the default helper set
     * @return \Symfony\Component\Console\Helper\HelperSet
     */
    protected function getDefaultHelperSet()
    {
        return new HelperSet();
    }
}
