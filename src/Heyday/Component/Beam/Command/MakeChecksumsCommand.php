<?php

namespace Heyday\Component\Beam\Command;

use Heyday\Component\Beam\Utils;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;

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
                'bzip2',
                'b',
                InputOption::VALUE_OPTIONAL,
                'Bzip2 to save download time',
                true
            )
            ->addOption(
                'gzip',
                'g',
                InputOption::VALUE_OPTIONAL,
                'Gzip to save download time',
                false
            );
    }
    /**
     * @param InputInterface  $input
     * @param OutputInterface $output
     * @return int|null|void
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $dir = $input->getOption('path');
        $files = Utils::getAllFiles($dir);
        $json = array();
        foreach ($files as $file) {
            $path = $file->getPathname();
            $json[$path] = md5_file($path);
        }
        $file = rtrim($dir, '/') . '/' . $input->getOption('checksumfile');
        if ($input->getOption('bzip2')) {
            file_put_contents(
                $file . '.bz2',
                bzcompress(json_encode($json))
            );
        } elseif ($input->getOption('gzip')) {
            file_put_contents(
                $file . '.gz',
                gzencode(json_encode($json))
            );
        } else {
            file_put_contents(
                $file,
                json_encode($json)
            );
        }
    }
}