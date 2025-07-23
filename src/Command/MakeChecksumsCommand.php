<?php

namespace Heyday\Beam\Command;

use Heyday\Beam\Config\BeamConfiguration;
use Heyday\Beam\Utils;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class MakeChecksumsCommand
 * @package Heyday\Beam\Command
 */
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
            ->addConfigOption();
    }
    /**
     * @param  InputInterface  $input
     * @param  OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $path = realpath($input->getOption('path'));

        $config = BeamConfiguration::parseConfig($this->getConfig($input, $path));

        $checksums = Utils::checksumsFromFiles(
            Utils::getAllowedFilesFromDirectory($config['exclude'], $path),
            $path
        );

        $jsonfile = rtrim($path, '/') . '/' . $input->getOption('checksumfile');

        file_put_contents(
            $jsonfile . '.gz',
            Utils::checksumsToGz($checksums)
        );

        return 0;
    }
}
