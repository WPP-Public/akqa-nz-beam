<?php

namespace Heyday\Component\Beam;

use Heyday\Component\Beam\Command;
use Heyday\Component\Beam\Helper;
use Symfony\Component\Console\Application as BaseApplication;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Helper\HelperSet;

/**
 * Class Application
 * @package Heyday\Component\Beam
 */
class Application extends BaseApplication
{
    /**
     * The default command when beam is operating in single command mode
     */
    const DEFAULT_COMMAND = 'rsync';
    /**
     * @var bool
     */
    protected $isSingleCommandApp = false;
    /**
     * Set the name and version (with the version a dummy var to be swapped out by the compiler)
     */
    public function __construct()
    {
        parent::__construct('Beam', '~package_version~');
    }
    /**
     * If the first argument is a registered command then return that as the command name, else default to the
     * specified default command
     * @param  InputInterface $input
     * @return string
     */
    protected function getCommandName(InputInterface $input)
    {
        $firstArg = $input->getFirstArgument();
        if ($this->has($firstArg)) {
            return $firstArg;
        } else {
            $this->isSingleCommandApp = true;

            return static::DEFAULT_COMMAND;
        }
    }
    /**
     * Set the default commands
     * @return array
     */
    protected function getDefaultCommands()
    {
        $commands = parent::getDefaultCommands();

        $commands[] = new Command\RsyncCommand();
        $commands[] = new Command\SftpCommand();
        $commands[] = new Command\FtpCommand();
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
    /**
     * When operating as a single command app, ensure the app doesn't error due to the first argument
     * not being a command on the command set
     * @return \Symfony\Component\Console\Input\InputDefinition
     */
    public function getDefinition()
    {
        $inputDefinition = parent::getDefinition();
        if ($this->isSingleCommandApp) {
            $inputDefinition->setArguments();
        }

        return $inputDefinition;
    }
}
