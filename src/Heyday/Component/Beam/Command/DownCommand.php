<?php

namespace Heyday\Component\Beam\Command;

/**
 * Class DownCommand
 * @package Heyday\Component\Beam\Command
 */
class DownCommand extends TransferCommand
{
    protected function configure()
    {
        parent::configure();
        $this
            ->setName('down')
            ->setDescription('Transfer from a server');
    }
    /**
     * @return string
     */
    protected function getDirection()
    {
        return 'down';
    }
}