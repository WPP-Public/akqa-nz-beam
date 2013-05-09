<?php

namespace Heyday\Component\Beam\Command;

class RsyncCommand extends BeamCommand
{
    protected function configure()
    {
        parent::configure();
        $this
            ->setName('rsync')
            ->setDescription('A file upload/download tool that utilises rsync and git');
    }
}