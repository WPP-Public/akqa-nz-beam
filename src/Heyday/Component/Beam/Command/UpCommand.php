<?php

namespace Heyday\Component\Beam\Command;

/**
 * Class UpCommand
 * @package Heyday\Component\Beam\Command
 */
class UpCommand extends TransferCommand
{
    protected function configure()
    {
        parent::configure();
        $this
            ->setName('up')
            ->setDescription('Transfer to a server');
    }

    /**
     * @return string
     */
    protected function getDirection()
    {
        return 'up';
    }
}