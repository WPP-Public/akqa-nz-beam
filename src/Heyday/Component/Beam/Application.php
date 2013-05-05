<?php

namespace Heyday\Component\Beam;

use Heyday\Component\Beam\Command\BeamCommand;
use Heyday\Component\Beam\Command\GenerateDeployCommand;
use Heyday\Component\Beam\Helper\ChangesHelper;
use Symfony\Component\Console\Application as BaseApplication;

class Application extends BaseApplication
{
    public function __construct()
    {
        parent::__construct('Beam', '2.0.0');
    }

    protected function getDefaultCommands()
    {
        $commands = parent::getDefaultCommands();
        $commands[] = new BeamCommand();
        $commands[] = new GenerateDeployCommand();
        return $commands;
    }
    protected function getDefaultHelperSet()
    {
        $helperset = parent::getDefaultHelperSet();
        $helperset->set(new ChangesHelper());
        return $helperset;
    }
}