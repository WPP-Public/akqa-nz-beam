<?php

namespace Heyday\Component\Beam\Command;

use Heyday\Component\Beam\Beam;
use Heyday\Component\Beam\Deployment\DeploymentResult;
use Symfony\Component\Console\Helper\FormatterHelper;
use Symfony\Component\Console\Helper\ProgressHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Application;

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
            $this->progressHelper->setFormat('[%bar%] %current%/%max% files');
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
                'branch',
                'b',
                InputOption::VALUE_REQUIRED,
                'The branch to be beamed up'
            )
            ->addOption(
                'path',
                'p',
                InputOption::VALUE_REQUIRED,
                'The path to be beamed up or down'
            )
            ->addOption(
                'dryrun',
                'd',
                InputOption::VALUE_NONE,
                'If set, no files will be transferred'
            )
            ->addOption(
                'noprompt',
                '',
                InputOption::VALUE_NONE,
                'Skips the dry-run and prompt'
            )
            ->addOption(
                'workingcopy',
                '',
                InputOption::VALUE_NONE,
                'When uploading, syncs files from the working copy rather than exported git copy'
            )
            ->addOption(
                'commands-prompt',
                '',
                InputOption::VALUE_NONE,
                'Prompts non-required commands'
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

                $options['targetcommandoutputhandler'] = $options['outputhandler'];

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

            if (!$this->isOkay($output, $dialogHelper, $formatterHelper)) {
                throw new \RuntimeException('User canceled');
            }

            $output->writeln(
                $formatterHelper->formatSection(
                    'warn',
                    'Determining list of files that will be modified...',
                    'comment'
                )
            );

            // Prompt the user with the affected files and a confirmation dialog
            if (!$input->getOption('noprompt')) {
                // Get the affected files
                $deploymentResult = $beam->doDryrun();
                // If there are any show them
                $count = count($deploymentResult);
                // If there is more that 1 item there are updates,
                // If there is 1 and it is nochange treat it as no update
                if ($count > 1 || (isset($deploymentResult[0]) && $deploymentResult[0]['update'] != 'nochange')) {
                    $deploymentResultHelper->outputChanges($formatterHelper, $output, $deploymentResult);
                    // Output a summary of the changes
                    $deploymentResultHelper->outputChangesSummary($formatterHelper, $output, $deploymentResult);
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
                        $deploymentResult = $beam->doRun($deploymentResult);

                        $deploymentResultHelper->outputChangesSummary(
                            $formatterHelper,
                            $output,
                            $deploymentResult
                        );
                    } else {
                        throw new \RuntimeException('User canceled');
                    }
                } else {
                    throw new \RuntimeException('No changed files');
                }
            } else {
                $changedFiles = $beam->doRun();

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

        $options = array(
            'direction' => $input->getArgument('direction'),
            'target'    => $input->getArgument('target')
        );

        if ($input->getOption('branch')) {
            $options['branch'] = $input->getOption('branch');
        }
        if ($input->getOption('path')) {
            $options['path'] = $input->getOption('path');
        }
        if ($input->getOption('dryrun')) {
            $options['dryrun'] = true;
        }
        if ($input->getOption('workingcopy')) {
            $options['workingcopy'] = true;
        }
        if ($input->getOption('commands-prompt')) {
            $that = $this;
            $options['commandprompthandler'] = function ($command) use ($that, $output, $dialogHelper, $formatterHelper) {
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

        $options['srcdir'] = dirname(
            $this->getJsonConfigLoader(getcwd())->locate(
                $input->getOption('configfile')
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
                'From: %s @ %s',
                $beam->getCombinedPath($beam->getLocalPath()),
                $beam->getOption('branch')
            );
            $toMessage = sprintf(
                'To:   %s',
                $beam->getTargetPath()
            );
        } else {
            $toMessage = sprintf(
                'To: %s @ %s',
                $beam->getCombinedPath($beam->getLocalPath()),
                $beam->getOption('branch')
            );
            $fromMessage = sprintf(
                'From:   %s',
                $beam->getTargetPath()
            );
        }

        if ($beam->getOption('dryrun')){
            $action = 'You\'re about to do a <comment>dry run</comment> for a sync between';
        } else {
            $action = 'You\'re about to sync files between';
        }

        $output->writeln(
            array(
                $formatterHelper->formatSection(
                    'warn',
                    'Starting...',
                    'comment'
                ),
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
