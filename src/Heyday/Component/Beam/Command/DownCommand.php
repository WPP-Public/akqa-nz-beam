<?php

namespace Heyday\Component\Beam\Command;

class DownCommand extends TransferCommand
{
    const DIRECTION = 'down';

    protected function configure()
    {
        parent::configure();
        $this
            ->setName('down')
            ->setDescription('Transfer from a server');
    }
}