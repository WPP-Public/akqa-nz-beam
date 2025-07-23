<?php

namespace Heyday\Beam\Command;

/**
 * Class UpCommand
 * @package Heyday\Beam\Command
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
    protected function getDirection(): string
    {
        return 'up';
    }
}
