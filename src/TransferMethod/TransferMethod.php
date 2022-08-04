<?php

namespace Heyday\Beam\TransferMethod;

use Heyday\Beam\Exception\RuntimeException;
use Heyday\Beam\Helper\YesNoQuestion;
use Heyday\Beam\Utils;
use Symfony\Component\Console\Helper\FormatterHelper;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Process\Process;

/**
 * Class BeamCommand
 * @package Heyday\Beam\TransferMethod
 */
abstract class TransferMethod
{
    /**
     * @var string
     */
    protected $direction;
    /**
     * @var QuestionHelper
     */
    protected $questionHelper;
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
        $this->questionHelper = new QuestionHelper();
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
        $options = [
            'direction' => $this->direction,
            'target'    => $input->getArgument('target'),
            'srcdir'    => $srcDir
        ];

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
            $options['commandprompthandler'] = $this->getCommandPromptHandler($input, $output);
        }

        $options['commandfailurehandler'] = $this->getCommandFailureHandler($input, $output);

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
     * @param InputInterface $input
     * @param  OutputInterface $output
     * @return callable
     */
    protected function getCommandPromptHandler(InputInterface $input, OutputInterface $output)
    {
        $questionHelper = $this->questionHelper;
        $formatterHelper = $this->formatterHelper;

        return function ($command) use ($input, $output, $questionHelper, $formatterHelper) {
            $question = new YesNoQuestion(
                $formatterHelper->formatSection(
                    $command['command'],
                    Utils::getQuestion('Do you want to run this command?', 'y'),
                    'comment'
                ),
                'y'
            );

            return in_array(
                $questionHelper->ask(
                    $input,
                    $output,
                    $question
                ),
                [
                    'y',
                    'yes'
                ]
            );
        };
    }

    /**
     * @param InputInterface $input
     * @param  OutputInterface $output
     * @return callable
     */
    protected function getCommandFailureHandler(InputInterface $input, OutputInterface $output)
    {
        $questionHelper = $this->questionHelper;
        $formatterHelper = $this->formatterHelper;

        return function ($command, \Exception $exception, Process $process = null)
            use ($input, $output, $questionHelper, $formatterHelper) {

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

            return $questionHelper->ask(
                $input,
                $output,
                new YesNoQuestion(
                    $formatterHelper->formatSection(
                        'Prompt',
                        Utils::getQuestion('A command exited with a non-zero status. Do you want to continue', 'yes'),
                        'error'
                    )
                )
            );
        };
    }
}
