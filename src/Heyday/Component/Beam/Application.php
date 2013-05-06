<?php

namespace Heyday\Component\Beam;

use Heyday\Component\Beam\Command\BeamCommand;
use Heyday\Component\Beam\Command\GenerateDeployCommand;
use Heyday\Component\Beam\Command\SelfUpdateCommand;
use Heyday\Component\Beam\Helper\ChangesHelper;
use Symfony\Component\Console\Application as BaseApplication;
use Symfony\Component\Console\Input\InputInterface;

/**
 * Class Application
 * @package Heyday\Component\Beam
 */
class Application extends BaseApplication
{
    /**
     * @var bool
     */
    protected $isSingleCommandApp = false;
    /**
     *
     */
    public function __construct()
    {
        parent::__construct('Beam', '~package_version~');
    }
    /**
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

            return 'beam';
        }
    }
    /**
     * @return array
     */
    protected function getDefaultCommands()
    {
        $commands = parent::getDefaultCommands();
        $commands[] = new BeamCommand();
        $commands[] = new GenerateDeployCommand();
        $commands[] = new SelfUpdateCommand();

        return $commands;
    }
    /**
     * @return \Symfony\Component\Console\Helper\HelperSet
     */
    protected function getDefaultHelperSet()
    {
        $helperset = parent::getDefaultHelperSet();
        $helperset->set(new ChangesHelper());

        return $helperset;
    }
    /**
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
