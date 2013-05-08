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

        $config = $processor->processConfiguration(
            new BeamConfiguration(),
            array(
                $this->getConfig($input)
            )
        );

        $dir = $input->getOption('path');
        $files = Utils::getAllFiles(
            function ($file) use ($config, $dir) {
                return ($file->isFile() || $file->isLink()) && !Utils::isExcluded(
                    $config['exclude'],
                    Utils::getRelativePath(
                        $dir,
                        $file->getPathname()
                    )
                );
            },
            $dir
        );

        $json = array();
        foreach ($files as $file) {
            $path = str_replace($dir . '/', '', $file->getPathname());
            $json[$path] = md5_file($file);
        }

        $jsonfile = rtrim($dir, '/') . '/' . $input->getOption('checksumfile');
        if ($input->getOption('gzip')) {
            file_put_contents(
                $jsonfile . '.gz',
                gzencode(json_encode($json), 9)
            );
        } elseif ($input->getOption('nocompress')) {
            file_put_contents(
                $jsonfile,
                json_encode($json)
            );
        } else {
            file_put_contents(
                $jsonfile . '.bz2',
                bzcompress(json_encode($json), 9)
            );
        }
    }
}