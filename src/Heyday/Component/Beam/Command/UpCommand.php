<?php

namespace Heyday\Component\Beam\Command;

class UpCommand extends TransferCommand
{
    const DIRECTION = 'up';

    protected function configure()
    {
        parent::configure();
        $this
            ->setName('up')
            ->setDescription('Transfer to a server');
    }
}