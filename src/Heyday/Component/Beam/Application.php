<?php

namespace Heyday\Component\Beam;

use Heyday\Component\Beam\Command\CompileCommand;
use Heyday\Component\Beam\Command\FtpCommand;
use Heyday\Component\Beam\Command\GenerateDeployCommand;
use Heyday\Component\Beam\Command\MakeChecksumsCommand;
use Heyday\Component\Beam\Command\RsyncCommand;
use Heyday\Component\Beam\Command\SelfUpdateCommand;
use Heyday\Component\Beam\Command\SftpCommand;
use Heyday\Component\Beam\Helper\ContentProgressHelper;
use Heyday\Component\Beam\Helper\DeploymentResultHelper;
use Symfony\Component\Console\Application as BaseApplication;
use Symfony\Component\Console\Input\InputInterface;

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
        $commands[] = new RsyncCommand();
        $commands[] = new SftpCommand();
        $commands[] = new FtpCommand();
        $commands[] = new GenerateDeployCommand();
        $commands[] = new SelfUpdateCommand();
        $commands[] = new MakeChecksumsCommand();
        $commands[] = new CompileCommand();

        return $commands;
    }
    /**
     * Return the default helper set
     * @return \Symfony\Component\Console\Helper\HelperSet
     */
    protected function getDefaultHelperSet()
    {
        $helperset = parent::getDefaultHelperSet();
        $helperset->set(new DeploymentResultHelper());
        $helperset->set(new ContentProgressHelper());

        return $helperset;
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
