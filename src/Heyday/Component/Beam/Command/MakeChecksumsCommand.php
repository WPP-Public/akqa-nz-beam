<?php

namespace Heyday\Component\Beam\Command;

use Heyday\Component\Beam\Config\BeamConfiguration;
use Heyday\Component\Beam\Utils;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Config\Definition\Processor;

class MakeChecksumsCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('makechecksums')
            ->setDescription('Generate a checksums file')
            ->addOption(
                'path',
                'p',
                InputOption::VALUE_OPTIONAL,
                'The path to scan and make the checksums file in',
                getcwd()
            )
            ->addOption(
                'checksumfile',
                'c',
                InputOption::VALUE_OPTIONAL,
                'Filename to save the file with',
                'checksums.json'
            )
            ->addOption(
                'gzip',
                'g',
                InputOption::VALUE_NONE,
                'Gzip to save download time'
            )
            ->addOption(
                'nocompress',
                'nc',
                InputOption::VALUE_NONE,
                'Don\'t compress'
            )
            ->addConfigOption();
    }
    /**
     * @param InputInterface  $input
     * @param OutputInterface $output
     * @return int|null|void
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $processor = new Processor();
        $path = $input->getOption('path');

        $config = $processor->processConfiguration(
            new BeamConfiguration(),
            array(
                $this->getConfig($input, $path)
            )
        );

        $files = Utils::getAllowedFilesFromDirectory($config['exclude'], $path);
        $checksums = Utils::checksumsFromFiles($files, $path);
        $jsonfile = rtrim($path, '/') . '/' . $input->getOption('checksumfile');

        if ($input->getOption('gzip')) {
            file_put_contents(
                $jsonfile . '.gz',
                Utils::checksumsToGz($checksums)
            );
        } elseif ($input->getOption('nocompress')) {
            file_put_contents(
                $jsonfile,
                json_encode($checksums)
            );
        } else {
            file_put_contents(
                $jsonfile . '.bz2',
                Utils::checksumsToBz2($checksums)
            );
        }
    }
}