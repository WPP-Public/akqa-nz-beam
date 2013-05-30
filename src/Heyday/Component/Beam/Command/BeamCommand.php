<?php

namespace Heyday\Component\Beam\Command;

use Heyday\Component\Beam\Beam;
use Heyday\Component\Beam\Deployment\DeploymentResult;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Helper\FormatterHelper;
use Symfony\Component\Console\Helper\ProgressHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class BeamCommand
 * @package Heyday\Component\Beam\Command
 */
abstract class BeamCommand extends Command
{
    /**
     * @var \Symfony\Component\Console\Helper\HelperInterface
     */
    protected $formatterHelper;
    /**
     * @var \Symfony\Component\Console\Helper\HelperInterface
     */
    protected $progressHelper;
    /**
     * @var \Symfony\Component\Console\Helper\HelperInterface
     */
    protected $deploymentResultHelper;
    /**
     * @var \Symfony\Component\Console\Helper\HelperInterface
     */
    protected $dialogHelper;
    /**
     * @param Application $application
     */
    public function setApplication(Application $application = null)
    {
        parent::setApplication($application);
        if ($application) {
            $helperSet = $this->getHelperSet();
            $this->formatterHelper = $helperSet->get('formatter');
            $this->progressHelper = $helperSet->get('contentprogress');
            $this->deploymentResultHelper = $helperSet->get('deploymentresult');
            $this->dialogHelper = $helperSet->get('dialog');
        }
    }
    /**
     *
     */
    protected function configure()
    {
        $this
            ->addArgument(
                'direction',
                InputArgument::REQUIRED,
                'Valid values are \'up\' or \'down\''
            )
            ->addArgument(
                'target',
                InputArgument::REQUIRED,
                'Config name of target location to be beamed from or to'
            )
            ->addOption(
                'ref',
                'r',
                InputOption::VALUE_REQUIRED,
                'The object in your VCS to beam up (ie. HEAD~1, master, f147a16)'
            )
            ->addOption(
                'path',
                'p',
                InputOption::VALUE_REQUIRED,
                'The path to be beamed up or down'
            )
            ->addOption(
                'dry-run',
                'd',
                InputOption::VALUE_NONE,
                'If set, no files will be transferred'
            )
            ->addOption(
                'no-prompt',
                '',
                InputOption::VALUE_NONE,
                'Skips the dry-run and prompt'
            )
            ->addOption(
                'working-copy',
                '',
                InputOption::VALUE_NONE,
                'When uploading, syncs files from the working copy rather than exported git copy'
            )
            ->addOption(
                'command-prompt',
                '',
                InputOption::VALUE_NONE,
                'Prompts non-required commands'
            )
            ->addOption(
                'tags',
                't',
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Run the specified tagged commands (wildcards supported).'
            )
            ->addConfigOption();
    }
    /**
     * @param  InputInterface  $input
     * @param  OutputInterface $output
     * @return int|null|void
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        //Set for use in local closures
        $formatterHelper = $this->formatterHelper;
        $progressHelper = $this->progressHelper;
        $deploymentResultHelper = $this->deploymentResultHelper;
        $dialogHelper = $this->dialogHelper;

        try {

            $options = $this->getOptions($input, $output);

            $options['outputhandler'] = function ($content, $section = 'info') use ($output, $formatterHelper) {
                $output->writeln(
                    $formatterHelper->formatSection(
                        $section,
                        $content
                    )
                );
            };

            if (OutputInterface::VERBOSITY_VERBOSE === $output->getVerbosity()) {

                $options['localcommandoutputhandler'] = function ($type, $data) use ($output, $formatterHelper) {
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

                $options['targetcommandoutputhandler'] = $options['localcommandoutputhandler'];

            }

            $beam = new Beam(
                array(
                    $this->getConfig($input, getcwd())
                ),
                $options
            );

            $this->outputSummary(
                $output,
                $formatterHelper,
                $beam
            );

            $output->writeln(
                $formatterHelper->formatSection(
                    'warn',
                    'Determining list of files that will be modified...',
                    'comment'
                )
            );

            // Prompt the user with the affected files and a confirmation dialog
            if (!$input->getOption('no-prompt')) {
                // Get the affected files
                $deploymentResult = $beam->doDryrun();
                // If there are any show them
                $count = count($deploymentResult);
                // If there is more that 1 item there are updates,
                // If there is 1 and it is nochange treat it as no update
                if ($count > 0) {
                    $deploymentResultHelper->outputChanges($formatterHelper, $output, $deploymentResult);
                    // Output a summary of the changes
                    $deploymentResultHelper->outputChangesSummary($formatterHelper, $output, $deploymentResult);
                    if (!$input->getOption('dry-run')) {
                        // If we have confirmation do the beam
                        if ($this->isOkay($output, $dialogHelper, $formatterHelper)) {
                            // Set the output handler for displaying the progress bar etc
                            $beam->setOption(
                                'deploymentoutputhandler',
                                $this->getDeploymentOutputHandler(
                                    $output,
                                    $progressHelper,
                                    $formatterHelper,
                                    $deploymentResult
                                )
                            );

                            // Run the deployment
                            try {
                                $deploymentResult = $beam->doRun($deploymentResult);
                            } catch (\Exception $exception) {
                                if (!$this->handleDeploymentProviderFailure($exception, $output)) {
                                    exit(1);
                                }
                            }

                            $deploymentResultHelper->outputChangesSummary(
                                $formatterHelper,
                                $output,
                                $deploymentResult
                            );

                        } else {
                            throw new \RuntimeException('User canceled');
                        }
                    }
                } else {
                    throw new \RuntimeException('No changed files');
                }
            } else {

                if ($input->getOption('dry-run')) {
                    $changedFiles = $beam->doDryrun();
                } else {
                    $changedFiles = $beam->doRun();
                }

                $deploymentResultHelper->outputChanges(
                    $formatterHelper,
                    $output,
                    $changedFiles
                );

                $deploymentResultHelper->outputChangesSummary(
                    $formatterHelper,
                    $output,
                    $changedFiles
                );
            }

        } catch (\Exception $e) {
            $this->outputError(
                $output,
                $formatterHelper,
                $e->getMessage()
            );
        }

    }
    protected function handleDeploymentProviderFailure(\Exception $exception, OutputInterface $output)
    {
        $output->writeln(
            $this->formatterHelper->formatSection(
                'Error',
                $exception->getMessage(),
                'error'
            )
        );

        return in_array(
            $this->dialogHelper->askConfirmation(
                $output,
                $this->formatterHelper->formatSection(
                    'Prompt',
                    $this->getQuestion('The deployment provider threw an exception. Do you want to continue?', 'n'),
                    'error'
                ),
                false
            ),
            array(
                'y',
                'yes'
            )
        );
    }
    /**
     * @param \Symfony\Component\Console\Output\OutputInterface  $output
     * @param \Symfony\Component\Console\Helper\ProgressHelper   $progressHelper
     * @param \Symfony\Component\Console\Helper\FormatterHelper  $formatterHelper
     * @param \Heyday\Component\Beam\Deployment\DeploymentResult $deploymentResult
     * @internal param $count
     * @return mixed
     */
    protected function getDeploymentOutputHandler(
        OutputInterface $output,
        ProgressHelper $progressHelper,
        FormatterHelper $formatterHelper,
        DeploymentResult $deploymentResult
    ) {
        $count = count($deploymentResult);

        return function () use (
            $output,
            $progressHelper,
            $formatterHelper,
            $deploymentResult,
            $count
        ) {
            static $steps = 0;
            if ($steps === 0) {
                $progressHelper->setAutoWidth($count);
                // Start the progress bar
                $progressHelper->start($output, $count, 'File: ');
            }
            $progressHelper->advance(1, false, $deploymentResult[$steps]['filename']);
            $steps++;
            if ($steps >= $count) {
                $progressHelper->finish();
            }
        };
    }
    /**
     * @param  InputInterface $input
     * @return array
     */
    protected function getOptions(InputInterface $input, OutputInterface $output)
    {
        $formatterHelper = $this->formatterHelper;
        $dialogHelper = $this->dialogHelper;
        $that = $this;

        $options = array(
            'direction' => $input->getArgument('direction'),
            'target'    => $input->getArgument('target')
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
            $options['commandprompthandler'] = function ($command) use (
                $that,
                $output,
                $dialogHelper,
                $formatterHelper
            ) {
                return in_array(
                    $dialogHelper->askConfirmation(
                        $output,
                        $formatterHelper->formatSection(
                            $command['command'],
                            $that->getQuestion('Do you want to run this command?', 'y'),
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

        $options['commandfailurehandler'] = function ($command, $exception) use (
            $that,
            $output,
            $dialogHelper,
            $formatterHelper
        ) {

            // Ensure the output of the failed command is shown
            if (OutputInterface::VERBOSITY_VERBOSE !== $output->getVerbosity()) {
                $output->write(
                    $formatterHelper->formatSection('Error', trim($exception->getMessage(), "\n") . "\n", 'error')
                );
            }

            $output->write(
                $formatterHelper->formatSection('Error', 'Error running: ' . $command['command'] . "\n", 'error')
            );

            if ($command['required']) {
                throw new \RuntimeException('A command marked as required exited with a non-zero status');
            }

            return in_array(
                $dialogHelper->askConfirmation(
                    $output,
                    $formatterHelper->formatSection(
                        'Prompt',
                        $that->getQuestion('A command exited with a non-zero status. Do you want to continue', 'y'),
                        'error'
                    ),
                    'y'
                ),
                array(
                    'y',
                    'yes'
                )
            );
        };

        if ($input->getOption('tags')) {
            $options['command-tags'] = $input->getOption('tags');
        }

        $options['srcdir'] = dirname(
            $this->getJsonConfigLoader(getcwd())->locate(
                $input->getOption('config-file')
            )
        );

        return $options;
    }
    /**
     * @param         $question
     * @param  null   $default
     * @return string
     */
    public function getQuestion($question, $default = null)
    {
        if ($default !== null) {
            return sprintf(
                '<question>%s</question> [<comment>%s</comment>]: ',
                $question,
                $default
            );
        } else {
            return sprintf(
                '<question>%s</question>: ',
                $question
            );
        }
    }
    /**
     * @param OutputInterface $output
     * @param                 $formatterHelper
     * @param                 $beam
     */
    protected function outputSummary(OutputInterface $output, $formatterHelper, Beam $beam)
    {
        if ($beam->isUp()) {
            $fromMessage = sprintf(
                'SOURCE: %s %s',
                $beam->getCombinedPath($beam->getLocalPath()),
                $beam->getOption('working-copy') ? '' : '@ <info>' . $beam->getOption('ref') . '</info>'
            );
            $toMessage = sprintf(
                'TARGET: %s',
                $beam->getTargetPath()
            );
        } else {
            $toMessage = sprintf(
                'TARGET: %s',
                $beam->getCombinedPath($beam->getLocalPath())
            );
            $fromMessage = sprintf(
                'SOURCE: %s',
                $beam->getTargetPath()
            );
        }

        if ($beam->getOption('dry-run')) {
            $action = 'You\'re about do a <comment>dry run</comment> between';
        } else {
            $action = 'You\'re about sync files between:';
        }

        $output->writeln(
            array(
                $formatterHelper->formatSection(
                    'warn',
                    $action,
                    'comment'
                ),
                $formatterHelper->formatSection(
                    'warn',
                    $fromMessage,
                    'comment'
                ),
                $formatterHelper->formatSection(
                    'warn',
                    $toMessage,
                    'comment'
                )
            )
        );

    }
    /**
     * @param OutputInterface $output
     * @param                 $formatterHelper
     * @param                 $error
     */
    public function outputError(OutputInterface $output, $formatterHelper, $error)
    {
        $output->writeln(
            $formatterHelper->formatSection(
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
     * @param  OutputInterface $output
     * @param                  $dialogHelper
     * @param                  $formatterHelper
     * @return mixed
     */
    protected function isOkay(OutputInterface $output, $dialogHelper, $formatterHelper)
    {
        //TODO: Respect no-interaction
        return in_array(
            $dialogHelper->askConfirmation(
                $output,
                $formatterHelper->formatSection(
                    'prompt',
                    $this->getQuestion('Is this okay?', 'y'),
                    'comment'
                ),
                'y'
            ),
            array(
                'y',
                'yes'
            )
        );
    }
}
