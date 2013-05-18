<?php

namespace Heyday\Component\Beam\Command;

use Heyday\Component\Beam\Deployment\Sftp;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class SftpCommand
 * @package Heyday\Component\Beam\Command
 */
class SftpCommand extends BeamCommand
{
    /**
     *
     */
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
            )->addOption(
                'delete',
                '',
                InputOption::VALUE_NONE,
                'Delete mode'
            );
    }
    /**
     * @param  InputInterface $input
     * @return array
     */
    protected function getOptions(InputInterface $input, OutputInterface $output)
    {
        $options = parent::getOptions($input, $output);
        $options['deploymentprovider'] = new Sftp(
            $input->getOption('full'),
            $input->getOption('delete')
        );

        return $options;
    }
}
