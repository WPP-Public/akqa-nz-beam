<?php

namespace Heyday\Component\Beam\Command;

use Heyday\Component\Beam\Config\JsonConfigLoader;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Console\Command\Command as BaseCommand;
use Symfony\Component\Console\Helper\FormatterHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

abstract class Command extends BaseCommand
{
    /**
     * @var \Symfony\Component\Console\Helper\FormatterHelper
     */
    protected $formatterHelper;
    /**
     * @var
     */
    protected $jsonConfigLoader;
    /**
     * @param null $name
     */
    public function __construct($name = null)
    {
        $this->formatterHelper = new FormatterHelper();
        parent::__construct($name);
    }
    /**
     * @return JsonConfigLoader
     */
    protected function getJsonConfigLoader()
    {
        if (null === $this->jsonConfigLoader) {
            $path = getcwd();
            $paths = array();

            while ($path !== end($paths)) {
                $paths[] = $path;
                $path = dirname($path);
            }

            $this->jsonConfigLoader = new JsonConfigLoader(
                new FileLocator(
                    $paths
                )
            );
        }

        return $this->jsonConfigLoader;
    }
    /**
     * @param  InputInterface $input
     * @return mixed
     */
    protected function getConfig(InputInterface $input)
    {
        return $this->getJsonConfigLoader()->load(
            $input->getOption('config-file')
        );
    }
    /**
     * @param $configFile
     * @return string
     */
    protected function getSrcDir(InputInterface $input)
    {
        return dirname(
            $this->getJsonConfigLoader()->locate(
                $input->getOption('config-file')
            )
        );
    }
    /**
     * @return $this
     */
    protected function addConfigOption()
    {
        $this->addOption(
            'config-file',
            '',
            InputOption::VALUE_REQUIRED,
            'The config file name',
            'beam.json'
        );

        return $this;
    }
    /**
     * @param OutputInterface $output
     * @param                 $error
     */
    public function outputError(OutputInterface $output, $error)
    {
        $output->writeln(
            $this->formatterHelper->formatSection(
                'error',
                sprintf(
                    '<error>%s</error>',
                    $error
                ),
                'error'
            )
        );
    }
    /**
     * @param OutputInterface $output
     * @param                 $message
     * @param                 $section
     * @param                 $style
     */
    protected function outputMultiline(OutputInterface $output, $message, $section, $style)
    {
        foreach (explode(PHP_EOL, $message) as $line) {
            $output->writeln(
                $this->formatterHelper->formatSection(
                    $section,
                    $line,
                    $style
                )
            );
        }
    }
}
