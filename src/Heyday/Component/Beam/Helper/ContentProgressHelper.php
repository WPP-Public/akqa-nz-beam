<?php

namespace Heyday\Component\Beam\Helper;

use Symfony\Component\Console\Helper\ProgressHelper;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class ContentProgressHelper
 * @package Heyday\Component\Beam\Helper
 */
class ContentProgressHelper extends ProgressHelper
{
    /**
     * @var
     */
    protected $content;
    /**
     * @var array
     */
    protected $methods = array();
    /**
     * @var array
     */
    protected $properties = array();
    /**
     * @var
     */
    protected $cols;
    /**
     * @var
     */
    protected $first;
    protected $prefix = '';

    /**
     * @param $name
     * @return mixed
     */
    protected function getReflectionMethod($name)
    {
        if (!isset($this->methods[$name])) {
            $this->methods[$name] = new \ReflectionMethod('Symfony\Component\Console\Helper\ProgressHelper', $name);
            $this->methods[$name]->setAccessible(true);
        }
        return $this->methods[$name];
    }

    /**
     * @param $name
     * @return mixed
     */
    protected function getReflectionProperty($name)
    {
        if (!isset($this->properties[$name])) {
            $this->properties[$name] = new \ReflectionProperty('Symfony\Component\Console\Helper\ProgressHelper', $name);
            $this->properties[$name]->setAccessible(true);
        }
        return $this->properties[$name];
    }

    /**
     * @param OutputInterface $output
     * @param null            $max
     * @param string          $prefix
     */
    public function start(OutputInterface $output, $max = null, $prefix = '')
    {
        $this->prefix = $prefix;
        $this->cols = exec('tput cols');
        $output->write(str_repeat("\x20", $this->cols)); //next line and end line
        parent::start($output, $max);
    }

    /**
     * @param int    $step
     * @param bool   $redraw
     * @param string $content
     */
    public function advance($step = 1, $redraw = false, $content = '')
    {
        $this->setContent($content);
        parent::advance($step, $redraw);
    }

    /**
     * @param string $content
     */
    public function setContent($content = '')
    {
        $this->content = $this->prefix . substr($content, -$this->cols - strlen($this->prefix));
    }

    /**
     * @param bool $finish
     * @throws \LogicException
     */
    public function display($finish = false)
    {
        if (null === $this->getReflectionProperty('startTime')->getValue($this)) {
            throw new \LogicException('You must start the progress bar before calling display().');
        }

        $message = $this->getReflectionProperty('format')->getValue($this);
        foreach ($this->getReflectionMethod('generate')->invoke($this, $finish) as $name => $value) {
            $message = str_replace("%{$name}%", $value, $message);
        }
        $this->overwrite($this->getReflectionProperty('output')->getValue($this), $message);
    }

    /**
     * {@inheritDoc}
     */
    private function overwrite(OutputInterface $output, $messages)
    {
        $output->write("\033[A"); //up line
        $output->write("\x0D"); //start line
        $output->write("\033[K"); //next line and end line
        $output->write("\x0D"); //start line
        $output->write($this->content);

        if (strlen($this->content) !== $this->cols) {
            $output->write("\033[B"); // next line if content wasn't long enough
        }

        // start of line
        $output->write("\x0D");
        $output->write("\033[K"); //next line and end line
        $output->write("\x0D");
        $output->write($messages);
    }

    /**
     * @param $max
     */
    public function setAutoWidth($max)
    {
        $this->setBarWidth(exec('tput cols') - (strlen($max) * 2 + 18));
    }

    /**
     * {@inheritDoc}
     */
    public function getName()
    {
        return 'contentprogress';
    }
}