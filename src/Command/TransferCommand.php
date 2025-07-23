<?php

namespace Heyday\Beam\Command;

use Heyday\Beam\Beam;
use Heyday\Beam\Config\BeamConfiguration;
use Heyday\Beam\DeploymentProvider\DeploymentResult;
use Heyday\Beam\Exception\Exception;
use Heyday\Beam\Exception\InvalidConfigurationException;
use Heyday\Beam\Exception\RuntimeException;
use Heyday\Beam\Helper\ContentProgressHelper;
use Heyday\Beam\Helper\DeploymentResultHelper;
use Heyday\Beam\Helper\YesNoQuestion;
use Heyday\Beam\TransferMethod\TransferMethod;
use Heyday\Beam\Utils;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class TransferCommand
 * @package Heyday\Beam\Command
 */
abstract class TransferCommand extends Command
{
    /**
     * @var TransferMethod
     */
    protected $transferMethod;

    /**
     * @var array
     */
    protected $config;

    /**
     * @var \Heyday\Beam\Helper\DeploymentResultHelper
     */
    protected $deploymentResultHelper;

    /**
     * @var QuestionHelper
     */
    protected $questionHelper;

    /**
     * @param null $name
     */
    public function __construct($name = null)
    {
        parent::__construct($name);
        $this->deploymentResultHelper = new DeploymentResultHelper($this->formatterHelper);
        $this->questionHelper = new QuestionHelper();
    }

    /**
     * Configure the command
     */
    protected function configure()
    {
        // Bypass input validation as this happens before additional options have
        // been added from a TransferMethod. Validation occurs in initialize().
        $this->ignoreValidationErrors();

        $this
            ->addArgument(
                'target',
                InputArgument::REQUIRED,
                'Config name of target location to be beamed from or to'
            )
            ->addOption(
                'ref',
                'r',
                InputOption::VALUE_REQUIRED,
                'The object in your VCS to beam up <comment>(ie. HEAD~1, master, f147a16)</comment>'
            )
            ->addOption(
                'path',
                'p',
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
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
                'When uploading, syncs files from the working copy rather than exported VCS copy'
            )
            ->addOption(
                'command-prompt',
                '',
                InputOption::VALUE_NONE,
                "Prompts commands that aren't required"
            )
            ->addOption(
                'tags',
                't',
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Run the specified tagged commands <comment>(wildcards supported)</comment>'
            )
            ->addConfigOption();
    }

    /**
     * The direction to beam
     * @return mixed
     */
    abstract protected function getDirection();

    /**
     * @inheritdoc
     * @throws InvalidConfigurationException
     */
    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        $this->config = BeamConfiguration::parseConfig($this->getConfig($input));
        BeamConfiguration::validateArguments($input, $this->config);

        // Set transfer method from config
        if ($target = $input->getArgument('target')) {
            $method = isset($this->config['servers'][$target]['type']) ? $this->config['servers'][$target]['type'] : 'rsync';
            $this->setTransferMethodByKey($method);
        }

        $input->bind($this->getDefinition());
    }

    /**
     * @param string $key - key from BeamConfiguration::$transferMethods
     *
     * @throws InvalidConfigurationException
     */
    protected function setTransferMethodByKey($key)
    {
        $this->transferMethod = $this->instantiateTransferMethod($key);
        $this->transferMethod->setDirection($this->getDirection());

        // Extend definition
        $this->getDefinition()->addOptions(
            $this->transferMethod->getInputDefinition()->getOptions()
        );
    }

    /**
     * @param InputInterface  $input
     * @param OutputInterface $output
     * @return int
     * @throws RuntimeException
     * @throws \Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->transferMethod) {
            throw new RuntimeException('Transfer method must be set. Run initialize before execute.');
        }

        try {
            $beam = new Beam(
                $this->config,
                $this->transferMethod->getOptions($input, $output, $this->getSrcDir($input))
            );

            $this->outputSummary($output, $beam);

            // Trigger the deployment provider's post-init method
            $beam->configureDeploymentProvider($input, $output);

            // Set up to stream the list of changes if streaming is available
            $doStreamResult = $beam->deploymentProviderImplements('Heyday\Beam\DeploymentProvider\ResultStream');

            if ($doStreamResult) {
                $resultHelper = $this->deploymentResultHelper;
                $beam->setResultStreamHandler(
                    function ($changes) use ($resultHelper, $output) {
                        // show the calleee
                        $func = trim(debug_backtrace()[1]['function']);

                        if (!empty($changes) && ($func == 'up' || $func == 'down')) {
                            $result = $changes instanceof DeploymentResult
                                ? $changes
                                : new DeploymentResult($changes);
                            $resultHelper->outputChanges($output, $result);
                        }
                    }
                );
            }

            // Prompt the user with the affected files and a confirmation dialog
            if (!$input->getOption('no-prompt')) {
                $output->writeln(
                    $this->formatterHelper->formatSection(
                        'info',
                        'Determining list of files that will be modified...'
                    )
                );

                // Get the affected files
                $deploymentResult = $beam->doDryrun();

                // If there are any show them
                $count = count($deploymentResult);

                // If there is more that 1 item there are updates
                if ($count > 0) {
                    // Output the actual changed files and folders
                    if (!$doStreamResult) {
                        $this->deploymentResultHelper->outputChanges($output, $deploymentResult);
                    }

                    // Output a summary of the changes
                    $this->deploymentResultHelper->outputChangesSummary($output, $deploymentResult);

                    // If it is a dry run we are complete
                    if (!$input->getOption('dry-run')) {
                        // If we have confirmation do the beam
                        if (!$this->isOkay($input, $output)) {
                            $this->outputError($output, 'User cancelled');
                            exit(1);
                        }

                        $deleteCount = $deploymentResult->getUpdateCount('deleted');

                        if (
                            $deleteCount > 0
                            && !$this->isOkay(
                                $input,
                                $output,
                                sprintf(
                                    '%d file%s going to be deleted in this deployment, are you sure this is okay?',
                                    $deleteCount,
                                    $deleteCount === 1 ? ' is' : 's are'
                                ),
                                'no'
                            )
                        ) {
                            $this->outputError($output, 'User cancelled');
                            exit(1);
                        }

                        // Create a progress bar
                        $progressHelper = ContentProgressHelper::setupBar(new ProgressBar($output));

                        // Set the output handler for displaying the progress bar etc
                        $beam->setOption(
                            'deploymentoutputhandler',
                            $this->getDeploymentOutputHandler($progressHelper, $deploymentResult)
                        );

                        // Disable the result stream handler so it doesn't mess with the progress bar
                        if ($doStreamResult) {
                            $beam->setResultStreamHandler(null);
                        }

                        // Run the deployment
                        try {
                            $deploymentResult = $beam->doRun($deploymentResult, function () use ($progressHelper) {
                                $progressHelper->finish();
                            });
                            $this->deploymentResultHelper->outputChangesSummary($output, $deploymentResult);
                        } catch (Exception $exception) {
                            if (!$this->handleDeploymentProviderFailure($exception, $input, $output)) {
                                exit(1);
                            }
                        }
                    }
                } else {
                    $this->outputError($output, 'No files to deploy');
                    exit(0);
                }
            } else {
                if ($input->getOption('dry-run')) {
                    $changedFiles = $beam->doDryrun();
                } else {
                    $changedFiles = $beam->doRun();
                }

                // Output all changes
                $this->deploymentResultHelper->outputChanges($output, $changedFiles);

                // Output a summary
                $this->deploymentResultHelper->outputChangesSummary($output, $changedFiles);
            }
        } catch (\Exception $e) {
            if ($output->getVerbosity() === OutputInterface::VERBOSITY_VERBOSE) {
                throw $e;
            } else {
                $this->outputError(
                    $output,
                    $e->getMessage()
                );
                exit(1);
            }
        }

        return 0;
    }

    /**
     * @param OutputInterface $output
     * @param Beam            $beam
     * @throws \Heyday\Beam\Exception\InvalidArgumentException
     */
    protected function outputSummary(OutputInterface $output, Beam $beam)
    {
        if ($beam->isUp()) {
            $fromMessage = sprintf(
                'SOURCE: %s %s',
                $beam->getLocalPath(),
                $beam->getOption('working-copy') ? '' : '@ <info>' . $beam->getOption('ref') . '</info>'
            );
            $toMessage = sprintf(
                'TARGET: %s',
                $beam->getTargetPath()
            );
        } else {
            $toMessage = sprintf(
                'TARGET: %s',
                $beam->getLocalPath()
            );
            $fromMessage = sprintf(
                'SOURCE: %s',
                $beam->getTargetPath()
            );
        }

        if ($beam->getOption('dry-run')) {
            $action = 'You\'re about to do a <comment>dry run</comment> between';
        } else {
            $action = 'You\'re about to sync files between:';
        }

        $output->writeln(
            [
                $this->formatterHelper->formatSection(
                    'warn',
                    $action,
                    'comment'
                ),
                $this->formatterHelper->formatSection(
                    'warn',
                    $fromMessage,
                    'comment'
                ),
                $this->formatterHelper->formatSection(
                    'warn',
                    $toMessage,
                    'comment'
                )
            ]
        );

        if ($beam->hasPath()) {
            $pathsMessage = 'PATHS: ';

            foreach ($beam->getOption('path') as $path) {
                $pathsMessage .= "$path\n" . str_repeat(' ', 14);
            }

            $output->writeln(
                $this->formatterHelper->formatSection(
                    'warn',
                    trim($pathsMessage),
                    'comment'
                )
            );
        }
    }

    /**
     * @param Exception       $exception
     * @param InputInterface  $input
     * @param OutputInterface $output
     * @return bool
     */
    protected function handleDeploymentProviderFailure(Exception $exception, InputInterface $input, OutputInterface $output)
    {
        $this->outputMultiline($output, $exception->getMessage(), 'Error', 'error');

        $question = new YesNoQuestion(
            $this->formatterHelper->formatSection(
                'Prompt',
                Utils::getQuestion('The deployment provider threw an exception. Do you want to continue?', 'n'),
                'error'
            ),
            false
        );

        return in_array(
            $this->questionHelper->ask(
                $input,
                $output,
                $question
            ),
            [
                'y',
                'yes'
            ]
        );
    }

    /**
     * @param ProgressBar $progressHelper
     * @param  DeploymentResult     $deploymentResult
     * @return callable
     */
    protected function getDeploymentOutputHandler(ProgressBar $progressHelper, DeploymentResult $deploymentResult)
    {
        $count = count($deploymentResult);

        // Find terminal width
        return function ($stepSize = 1) use (
            $deploymentResult,
            $progressHelper,
            $count
        ) {
            static $steps = 0;
            if ($steps === 0) {
                // Start the progress bar
                $maxValueText = $count;
                $cols = exec('tput cols');
                $progressHelper->setBarWidth($cols - (strlen($maxValueText) * 2 + 18));
                $progressHelper->start($count);
            }

            $filename = isset($deploymentResult[$steps]['filename']) ? $deploymentResult[$steps]['filename'] : '';
            ContentProgressHelper::setContent($progressHelper, $filename);
            $progressHelper->advance($stepSize);
            $steps += $stepSize;
        };
    }

    /**
     * @param InputInterface   $input
     * @param  OutputInterface $output
     * @param  string          $questionText
     * @param  string          $default
     * @return mixed
     */
    protected function isOkay(
        InputInterface $input,
        OutputInterface $output,
        $questionText = 'Is this okay?',
        $default = 'yes'
    ) {
        //TODO: Respect no-interaction
        $question = new YesNoQuestion(
            $this->formatterHelper->formatSection(
                'prompt',
                Utils::getQuestion(
                    $questionText,
                    $default
                ),
                'comment'
            ),
            $default
        );

        return $this->questionHelper->ask($input, $output, $question);
    }

    /**
     * @param $methodName
     * @return mixed
     * @throws InvalidConfigurationException
     */
    protected function instantiateTransferMethod($methodName)
    {
        if (isset(BeamConfiguration::$transferMethods[$methodName])) {
            return new BeamConfiguration::$transferMethods[$methodName]();
        } else {
            $methods = implode("', '", BeamConfiguration::$transferMethods);
            throw new InvalidConfigurationException("No transfer method '$methodName'. Must be one of '$methods'");
        }
    }

    /**
     * Return the command synopsis with current target's TransferMethod options merged in
     *
     * This override is used to ensure TransferMethod options get into the help text.
     *
     * If no target is specified, none of the TransferMethod options are displayed currently. This used
     * to be more customisable before Symfony Console 2.3 when formatting of command descriptions was
     * moved to an internal class: Symfony\Component\Console\Descriptor
     *
     * @inheritdoc
     */
    public function getSynopsis($short = false): string
    {
        $this->guessTarget();

        return parent::getSynopsis($short);
    }

    /**
     * Try to establish the transfer method to use.
     *
     * This is used when the help command has taken over, since our initialize() method isn't called.
     * Where no input definition is available, see if $argv matches the definition for this command.
     *
     * @param InputInterface|null $input - if null, an input will be created using argv
     */
    public function guessTarget(?InputInterface $input = null)
    {
        if (!$this->transferMethod) {
            try {
                if (!$input) {
                    $args = array_diff($_SERVER['argv'], ['--help', '-h']);
                    array_shift($args);

                    $input = new ArgvInput($args, $this->getDefinition());
                }

                $this->initialize($input, new NullOutput());
            } catch (\Exception $e) {
                // Guessing failed
            }
        }
    }
}
