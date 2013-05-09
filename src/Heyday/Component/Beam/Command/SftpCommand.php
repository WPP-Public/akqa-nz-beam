<?php

namespace Heyday\Component\Beam\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Heyday\Component\Beam\Deployment\Sftp;

class SftpCommand extends BeamCommand
{
    protected function configure()
    {
        parent::configure();
        $this
            ->setName('sftp')
            ->setDescription('A file upload/download tool that utilises sftp and git')
            ->addOption(
                'full',
                'f',
                InputOption::VALUE_NONE,
                'Does a more full check on the target, relying less on the checksums file'
            );
    }
    protected function getOptions(InputInterface $input)
    {
        $options = parent::getOptions($input);
        $options['deploymentprovider'] = new Sftp($input->getOption('full'));
        return $options;
    }

}