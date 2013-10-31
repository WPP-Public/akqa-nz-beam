<?php

namespace Heyday\Component\Beam\Command;

use Heyday\Component\Beam\Beam;
use Heyday\Component\Beam\Config\BeamConfiguration;
use Heyday\Component\Beam\DeploymentProvider\DeploymentResult;
use Heyday\Component\Beam\Exception\Exception;
use Heyday\Component\Beam\Exception\InvalidConfigurationException;
use Heyday\Component\Beam\Exception\RuntimeException;
use Heyday\Component\Beam\Helper\ContentProgressHelper;
use Heyday\Component\Beam\Helper\DeploymentResultHelper;
use Heyday\Component\Beam\TransferMethod\TransferMethod;
use Heyday\Component\Beam\Utils;
use Symfony\Component\Console\Helper\DialogHelper;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class TransferCommand
 * @package Heyday\Component\Beam\Command
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
     * @var \Heyday\Component\Beam\Helper\ContentProgressHelper
     */
    protected $progressHelper;
    /**
     * @var \Heyday\Component\Beam\Helper\DeploymentResultHelper
     */
    protected $deploymentResultHelper;
    /**
     * @var \Symfony\Component\Console\Helper\DialogHelper
     */
    protected $dialogHelper;

    /**
     * @param null $name
     */
    public function __construct($name = null)
    {
        parent::__construct($name);
        $this->progressHelper = new ContentProgressHelper();
        $this->deploymentResultHelper = new DeploymentResultHelper($this->formatterHelper);
        $this->dialogHelper = new DialogHelper();
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
     * @return int|null|void
     * @throws \Heyday\Component\Beam\Exception\RuntimeException
     */
    protected function execute(InputInterface $input, OutputInterface $output)
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

            // Set up to stream the list of changes if streaming is available
            $doStreamResult = $beam->deploymentProviderImplements('Heyday\Component\Beam\DeploymentProvider\ResultStream');

            if ($doStreamResult) {
                $resultHelper = $this->deploymentResultHelper;
                $beam->setResultStreamHandler(
                    function($changes) use ($resultHelper, $output) {
                        $resultHelper->outputChanges($output, new DeploymentResult($changes));
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
                        if (!$this->isOkay($output)) {
                            $this->outputError($output, 'User cancelled');
                            exit(1);
                        }

                        $deleteCount = $deploymentResult->getUpdateCount('deleted');

                        if (
                            $deleteCount > 0
                            && !$this->isOkay(
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

                        // Set the output handler for displaying the progress bar etc
                        $beam->setOption(
                            'deploymentoutputhandler',
                            $this->getDeploymentOutputHandler($output, $deploymentResult)
                        );

                        // Disable the result stream handler so it doesn't mess with the progress bar
                        if ($doStreamResult) {
                            $beam->setResultStreamHandler(null);
                        }

                        // Run the deployment
                        try {
                            $deploymentResult = $beam->doRun($deploymentResult);
                            $this->progressHelper->finish();
                            $this->deploymentResultHelper->outputChangesSummary($output, $deploymentResult);
                        } catch (Exception $exception) {
                            if (!$this->handleDeploymentProviderFailure($exception, $output)) {
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
            $this->outputError(
                $output,
                $e->getMessage()
            );
            exit(1);
        }

    }

    /**
     * @param OutputInterface $output
     * @param Beam            $beam
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
            array(
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
            )
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
     * @param OutputInterface $output
     * @return bool
     */
    protected function handleDeploymentProviderFailure(Exception $exception, OutputInterface $output)
    {
        $this->outputMultiline($output, $exception->getMessage(), 'Error', 'error');

        return in_array(
            $this->dialogHelper->askConfirmation(
                $output,
                $this->formatterHelper->formatSection(
                    'Prompt',
                    Utils::getQuestion('The deployment provider threw an exception. Do you want to continue?', 'n'),
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
     * @param  OutputInterface  $output
     * @param  DeploymentResult $deploymentResult
     * @return callable
     */
    protected function getDeploymentOutputHandler(OutputInterface $output, DeploymentResult $deploymentResult)
    {
        $count = count($deploymentResult);
        $progressHelper = $this->progressHelper;

        return function ($stepSize = 1) use (
            $output,
            $deploymentResult,
            $progressHelper,
            $count
        ) {
            static $steps = 0;
            if ($steps === 0) {
                $progressHelper->setAutoWidth($count);
                // Start the progress bar
                $progressHelper->start($output, $count, 'File: ');
            }

            $filename = isset($deploymentResult[$steps]['filename']) ? $deploymentResult[$steps]['filename'] : '';
            $progressHelper->advance($stepSize, false, $filename);
            $steps += $stepSize;
        };
    }

    /**
     * @param  OutputInterface $output
     * @param  string          $question
     * @param  string          $default
     * @return mixed
     */
    protected function isOkay(
        OutputInterface $output,
        $question = 'Is this okay?',
        $default = 'yes'
    ) {
        //TODO: Respect no-interaction
        return $this->dialogHelper->askConfirmation(
            $output,
            $this->formatterHelper->formatSection(
                'prompt',
                Utils::getQuestion(
                    $question,
                    $default
                ),
                'comment'
            ),
            $default[0] === 'y' ? true : false
        );
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
     * @inheritdoc
     */
    public function asText()
    {
        $this->guessTarget();

        $text = parent::asText();

        $definitions = array();
        foreach (BeamConfiguration::$transferMethods as $class) {
            $class = new $class();
            $definitions[$class->getName()] = $class->getInputDefinition();
        }

        $text .= $this->inputDefinitionsAsText($definitions);

        return $text;
    }

    /**
     * Try to establish the transfer method to use.
     * This is used when the help command has taken over, since our initialize() method isn't called.
     * Where no input definition is available, see if $argv matches the definition for this command.
     * @param InputInterface $input - if null, an input will be created using argv
     */
    public function guessTarget(InputInterface $input = null)
    {
        if (!$this->transferMethod) {
            try {
                if (!$input) {
                    $args = array_diff($_SERVER['argv'], array('--help', '-h'));
                    array_shift($args);

                    $input = new ArgvInput($args, $this->getDefinition());
                }

                $this->initialize($input, new NullOutput());
            } catch (\Exception $e) {
                // Guessing failed
            }
        }
    }

    /**
     * Lifted from InputDefinition->asText
     * @param array|InputDefinition $inputs
     * @return string
     */
    protected function inputDefinitionsAsText($inputs)
    {
        // find the largest option or argument name
        $max = 0;
        $text = array('');

        foreach ($inputs as $input) {

            foreach ($input->getOptions() as $option) {
                $nameLength = strlen($option->getName()) + 2;
                if ($option->getShortcut()) {
                    $nameLength += strlen($option->getShortcut()) + 3;
                }

                $max = max($max, $nameLength);
            }
            foreach ($input->getArguments() as $argument) {
                $max = max($max, strlen($argument->getName()));
            }
            ++$max;

        }

        foreach ($inputs as $name => $input) {

            if ($input->getArguments()) {
                $text[] = '<comment>Arguments:</comment>';
                foreach ($input->getArguments() as $argument) {
                    if (null !== $argument->getDefault() && (!is_array($argument->getDefault()) || count($argument->getDefault()))) {
                        $default = sprintf('<comment> (default: %s)</comment>', $this->formatDefaultValue($argument->getDefault()));
                    } else {
                        $default = '';
                    }

                    $description = str_replace("\n", "\n".str_repeat(' ', $max + 2), $argument->getDescription());

                    $text[] = sprintf(" <info>%-${max}s</info> %s%s", $argument->getName(), $description, $default);
                }

                $text[] = '';
            }

            if ($input->getOptions()) {
                if (is_string($name)) {
                    $text[] = "<comment>$name Options:</comment>";
                } else {
                    $text[] = '<comment>Options:</comment>';
                }

                foreach ($input->getOptions() as $option) {
                    if ($option->acceptValue() && null !== $option->getDefault() && (!is_array($option->getDefault()) || count($option->getDefault()))) {
                        $default = sprintf('<comment> (default: %s)</comment>', $this->formatDefaultValue($option->getDefault()));
                    } else {
                        $default = '';
                    }

                    $multiple = $option->isArray() ? '<comment> (multiple values allowed)</comment>' : '';
                    $description = str_replace("\n", "\n".str_repeat(' ', $max + 2), $option->getDescription());

                    $optionMax = $max - strlen($option->getName()) - 2;
                    $text[] = sprintf(" <info>%s</info> %-${optionMax}s%s%s%s",
                        '--'.$option->getName(),
                        $option->getShortcut() ? sprintf('(-%s) ', $option->getShortcut()) : '',
                        $description,
                        $default,
                        $multiple
                    );
                }

                $text[] = '';
            }

        }

        return implode("\n", $text);
    }

    /**
     * Copy of InputDefinition->formatDefaultValue
     */
    protected function formatDefaultValue($default)
    {
        if (version_compare(PHP_VERSION, '5.4', '<')) {
            return str_replace('\/', '/', json_encode($default));
        }

        return json_encode($default, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

}