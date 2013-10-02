<?php

namespace Heyday\Component\Beam\TransferMethod;

use Heyday\Component\Beam\Utils;
use Heyday\Component\Beam\Exception\RuntimeException;
use Symfony\Component\Console\Helper\DialogHelper;
use Symfony\Component\Console\Helper\FormatterHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

/**
 * Class BeamCommand
 * @package Heyday\Component\Beam\TransferMethod
 */
abstract class TransferMethod
{
    /**
     * @var string
     */
    protected $direction;
    /**
     * @var \Symfony\Component\Console\Helper\DialogHelper
     */
    protected $dialogHelper;
    /**
     * @var \Symfony\Component\Console\Helper\FormatterHelper
     */
    protected $formatterHelper;
    /**
     * @param $direction
     */
    public function setDirection($direction)
    {
        $this->direction = $direction;
    }

    public function __construct()
    {
        $this->dialogHelper = new DialogHelper();
        $this->formatterHelper = new FormatterHelper();
    }

    /**
     * Return an InputDefinition specifying any additional command-line options
     * @return InputDefinition
     */
    abstract public function getInputDefinition();

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @param string $srcDir
     * @return array
     */
    public function getOptions(InputInterface $input, OutputInterface $output, $srcDir)
    {
        $options = array(
            'direction' => $this->direction,
            'target'    => $input->getArgument('target'),
            'srcdir'    => $srcDir
        );

        if ($input->getOption('ref')) {
            $options['ref'] = $input->getOption('ref');
        }
        if ($input->getOption('path')) {
            $options['path'] = $input->getOption('path');
        }
        if ($input->getOption('dry-run')) {
            $options['dry-run'] = true;
        }
        if ($input->getOption('working-copy')) {
            $options['working-copy'] = true;
        }
        if ($input->getOption('command-prompt')) {
            $options['commandprompthandler'] = $this->getCommandPromptHandler($output);
        }

        $options['commandfailurehandler'] = $this->getCommandFailureHandler($output);

        $options['outputhandler'] = $this->getOutputHandler($output);

        if ($input->getOption('tags')) {
            $options['command-tags'] = $input->getOption('tags');
        }

        if (OutputInterface::VERBOSITY_VERBOSE === $output->getVerbosity()) {

            $formatterHelper = $this->formatterHelper;

            $options['targetcommandoutputhandler'] = $options['localcommandoutputhandler'] = function ($type, $data) use (
                $output,
                $formatterHelper
            ) {
                if ($type == 'out') {
                    $output->write(
                        $formatterHelper->formatSection(
                            'command',
                            $data
                        )
                    );
                } elseif ($type == 'err') {
                    $output->write(
                        $formatterHelper->formatSection(
                            'error',
                            $data,
                            'error'
                        )
                    );
                }
            };

        }

        return $options;
    }

    /**
     * @param  OutputInterface $output
     * @return callable
     */
    protected function getOutputHandler(OutputInterface $output)
    {
        $formatterHelper = $this->formatterHelper;

        return function ($content, $section = 'info') use ($output, $formatterHelper) {
            $output->writeln(
                $formatterHelper->formatSection(
                    $section,
                    $content
                )
            );
        };
    }

    /**
     * @param  OutputInterface $output
     * @return callable
     */
    protected function getCommandPromptHandler(OutputInterface $output)
    {
        $dialogHelper = $this->dialogHelper;
        $formatterHelper = $this->formatterHelper;

        return function ($command) use ($output, $dialogHelper, $formatterHelper) {
            return in_array(
                $dialogHelper->askConfirmation(
                    $output,
                    $formatterHelper->formatSection(
                        $command['command'],
                        Utils::getQuestion('Do you want to run this command?', 'y'),
                        'comment'
                    ),
                    'y'
                ),
                array(
                    'y',
                    'yes'
                )
            );
        };
    }

    /**
     * @param  OutputInterface $output
     * @return callable
     */
    protected function getCommandFailureHandler(OutputInterface $output)
    {
        $dialogHelper = $this->dialogHelper;
        $formatterHelper = $this->formatterHelper;

        return function ($command, \Exception $exception, Process $process = null) use ($output, $dialogHelper, $formatterHelper) {
            // Ensure the output of the failed command is shown
            if (OutputInterface::VERBOSITY_VERBOSE !== $output->getVerbosity()) {
                $message = trim($exception->getMessage());

                if (!$message && $process) {
                    $message = trim($process->getErrorOutput()) || trim($process->getOutput());
                }

                if ($message) {
                    $output->writeln($message);
                }
            }

            $output->writeln(
                $formatterHelper->formatSection('Error', 'Error running: ' . $command['command'], 'error')
            );

            if ($command['required']) {
                throw new RuntimeException('A command marked as required exited with a non-zero status');
            }

            return $dialogHelper->askConfirmation(
                $output,
                $formatterHelper->formatSection(
                    'Prompt',
                    Utils::getQuestion('A command exited with a non-zero status. Do you want to continue', 'yes'),
                    'error'
                )
            );
        };
    }
}
