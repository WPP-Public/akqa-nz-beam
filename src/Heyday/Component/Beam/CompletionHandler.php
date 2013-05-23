<?php


namespace Heyday\Component\Beam;

use Symfony\Component\Console\Application as BaseApplication;
use Symfony\Component\Console\Command\Command as BaseCommand;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;

class CompletionHandler {

    /**
     * COMP_WORDS
     * An array consisting of the individual words in the current command line.
     * @var array|string
     */
    protected $words;

    /**
     * COMP_CWORD
     * The index in COMP_WORDS of the word containing the current cursor position.
     * @var string
     */
    protected $wordIndex;

    /**
     * COMP_LINE
     * The current contents of the command line.
     * @var string
     */
    protected $commandLine;

    /**
     * COMP_POINT
     * The index of the current cursor position relative to the beginning of the
     * current command. If the current cursor position is at the end of the current
     * command, the value of this variable is equal to the length of COMP_LINE.
     * @var string
     */
    protected $charIndex;

    /**
     * Application to complete for
     * @var \Symfony\Component\Console\Application
     */
    protected $application;

    protected $argumentHandlers = array();

    public function __construct(BaseApplication $application)
    {
        $this->application = $application;
    }

    /**
     * Set completion context from the environment variables set by BASH completion
     */
    public function configureFromEnvironment()
    {
        $this->commandLine = getenv('COMP_LINE');
        $this->wordIndex = intval(getenv('COMP_CWORD'));
        $this->index = intval(getenv('COMP_POINT'));

        $breaks = preg_quote(getenv('COMP_WORDBREAKS'));
        $this->words = array_filter(
            preg_split( "/[$breaks]+/", $this->commandLine),
            function($val){
                return $val != ' ';
            }
        );

//        if ($this->commandLine === false) {
//           throw new \RuntimeException('Failed to configure from environment; Environment var COMP_LINE not set');
//        }
    }

    /**
     * Set completion context with an array
     * @param $array
     */
    public function configureWithArray($array)
    {
        $this->wordIndex = $array['wordIndex'];
        $this->commandLine = $array['commandLine'];
        $this->charIndex = $array['charIndex'];
        $this->words = $array['words'];
    }

    /**
     * Get the first argument
     * @return mixed
     */
    protected function getCommandName()
    {
        $ref = new \ReflectionClass($this->application);
        $method = $ref->getMethod('getCommandName');
        $method->setAccessible(true);

        $input = new ArrayInput($this->words);
        return $method->invoke($this->application, $input);
    }

    public function runCompletion()
    {
        $app = $this->application;

        // Get the app command to complete for
        $cmdName = $this->getCommandName();
        if (!$app->has($cmdName)) {
            // TODO: Complete first argument
            return;
        }

        $command = $app->get($cmdName);

        // Complete for either options or the current argument
        if (strpos($this->words[$this->wordIndex], '-') === 0) {
            $completion = $this->formatOptions($command);
        } else {
            $completion = $this->formatArguments($command);
        }

        if(is_array($completion)){
            return implode(' ', $completion);
        }

        return '';
    }

    protected function formatOptions(BaseCommand $cmd)
    {
        $options = array();
        foreach ($cmd->getDefinition()->getOptions() as $opt) {
            $options[] = '--'.$opt->getName();
        }
        return $options;
    }

    protected function formatArguments(BaseCommand $cmd)
    {
        $argWords = $this->mapArgumentsToWords($cmd->getDefinition()->getArguments());

        foreach ($argWords as $name => $wordNum) {
            if ($this->wordIndex == $wordNum) {
                if($this->hasArgHandler($name)){
                    return $this->runArgHandler($name);
                }
            }
        }

        return false;
    }

    protected function hasArgHandler($name)
    {
        return isset($this->argumentHandlers[$name]);
    }

    protected function runArgHandler($name)
    {
        if (is_array($this->argumentHandlers[$name])) {
            return $this->argumentHandlers[$name];
        }

        if (is_callable($this->argumentHandlers[$name])) {
            return call_user_func($this->argumentHandlers[$name]);
        }
    }

    /**
     * @param $arguments array|InputArgument
     * @return array
     */
    protected function mapArgumentsToWords($arguments)
    {
        $argNum = -1;
        $wordNum = 0;
        $argPositions = array();
        $arguments = array_keys($arguments);
        foreach ($this->words as $word) {
            $wordNum++;
            if ($word && '-' === $word[0]) {
                continue;
            }

            if (isset($arguments[++$argNum])) {
                $argPositions[$arguments[$argNum]] = $wordNum;
            }
        }

        return $argPositions;
    }

    /**
     * Return the BASH script necessary to use bash completion with this handler
     * @return string
     */
    public function generateBashCompletionHook()
    {
        global $argv;
        $command = $argv[0];

        return <<<"END"
function _beamcomplete {
    export COMP_CWORD COMP_KEY COMP_LINE COMP_POINT COMP_WORDBREAKS;
    COMPREPLY=(`compgen -W "$($command completion)" -- \${COMP_WORDS[COMP_CWORD]}`);
};
complete -F _beamcomplete beam;
END;
    }

    /**
     * @return array|string
     */
    public function getWords()
    {
        return $this->words;
    }

    /**
     * @param array|string $words
     */
    public function setWords($words)
    {
        $this->words = $words;
    }

    /**
     * @param string $wordIndex
     */
    public function setWordIndex($wordIndex)
    {
        $this->wordIndex = $wordIndex;
    }

    /**
     * @return string
     */
    public function getCommandLine()
    {
        return $this->commandLine;
    }

    /**
     * @param string $commandLine
     */
    public function setCommandLine($commandLine)
    {
        $this->commandLine = $commandLine;
    }

    /**
     * @param string $charIndex
     */
    public function setCharIndex($charIndex)
    {
        $this->charIndex = $charIndex;
    }

    /**
     * @return array
     */
    public function getArgumentHandlers()
    {
        return $this->argumentHandlers;
    }

    /**
     * @param array $argumentHandlers
     */
    public function setArgumentHandlers($argumentHandlers)
    {
        $this->argumentHandlers = $argumentHandlers;
    }

    /**
     * @param $argName
     * @param Closure $handler
     */
    public function addArgumentHandler($argName, Closure $handler)
    {
        $this->argumentHandlers[$argName] = $handler;
    }
}