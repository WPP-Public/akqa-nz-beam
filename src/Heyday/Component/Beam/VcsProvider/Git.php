<?php

namespace Heyday\Component\Beam\VcsProvider;

use Heyday\Component\Beam\Utils;
use Heyday\Component\Beam\Exception\InvalidConfigurationException;
use Heyday\Component\Beam\Exception\RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Class Git
 * @package Heyday\Component\Beam\VcsProvider
 */
class Git implements VcsProvider
{
    /**
     * @var
     */
    protected $srcdir;
    /**
     * @param $srcdir
     */
    public function __construct($srcdir)
    {
        $this->srcdir = $srcdir;
    }
    /**
     * @{inheritDoc}
     */
    public function getCurrentBranch()
    {
        $process = $this->process('git rev-parse --abbrev-ref HEAD');

        return trim($process->getOutput());
    }
    /**
     * @{inheritDoc}
     */
    public function getAvailableBranches()
    {
        $process = $this->process('git branch -a');
        $matches = array();
        preg_match_all('/[^\n](?:[\s\*]*)([^\s]*)(?:.*)/', $process->getOutput(), $matches);

        return $matches[1];
    }
    /**
     * @{inheritDoc}
     */
    public function exists()
    {
        return file_exists($this->srcdir . DIRECTORY_SEPARATOR . '.git');
    }
    /**
     * @{inheritDoc}
     */
    public function exportRef($branch, $location)
    {
        // Clean up previous deployment
        Utils::removeDirectory($location);

        // Ensure the location exists
        mkdir($location, 0755);

        $this->process(
            sprintf(
                '(git archive %s) | (cd %s && tar -xf -)',
                $branch,
                $location
            )
        );
    }
    /**
     * @{inheritDoc}
     */
    public function updateBranch($branch)
    {
        $parts = $this->getRemoteName($branch);
        if (!$parts) {
            throw new InvalidConfigurationException('The git vcs provider can only update remotes');
        }
        // TODO: Replace with git fetch --all ?
        $this->process(sprintf('git remote update --prune %s', $parts[0]));
    }
    /**
     * A helper method that returns a process with some defaults
     * @param $command
     * @throws RuntimeException
     * @return Process
     */
    protected function process($command)
    {
        $process = $this->getProcess($command);
        $process->run();
        if (!$process->isSuccessful()) {
            throw new RuntimeException($process->getErrorOutput());
        }

        return $process;
    }
    /**
     * @param $command
     * @return Process
     */
    protected function getProcess($command)
    {
        return new Process(
            $command,
            $this->srcdir
        );
    }
    /**
     * @param $ref
     * @return mixed
     */
    public function getLog($ref)
    {
        $process = $this->process(
            sprintf(
                'git log -1 --format=medium %s',
                $ref
            )
        );

        return sprintf(
            "Deployer: %s\nRef: %s\n%s\n",
            $this->getUserIdentity(),
            $ref,
            $process->getOutput()
        );
    }
    /**
     * @param $branch
     * @return bool
     */
    public function isRemote($branch)
    {
        return (bool) $this->getRemoteName($branch);
    }
    /**
     * @param $branch
     * @return array|bool
     */
    public function getRemoteName($branch)
    {
        $matches = array();
        if (1 === preg_match('{^remotes/(.+)/(.+)}', $branch, $matches)) {
            return array_slice($matches, 1);
        } else {
            return false;
        }
    }
    /**
     * @param $ref
     * @return bool
     */
    public function isValidRef($ref)
    {
        $process = $this->process(
            sprintf(
                'git show %s',
                escapeshellarg($ref)
            )
        );

        return $process->getExitCode() == 0;
    }
    /**
     * Get the identity of a user as defined in the git config
     * This is the name the git uses to identify a user in commits.
     * Output is in the format:
     *
     *     John Doe <john.doe@example.com>
     *
     * @return string
     */
    public function getUserIdentity()
    {
        $identity = false;

        try {

            $process = $this->process('git config --get user.name');
            $identity = trim($process->getOutput());

            if (!$identity || $process->getExitCode() > 0) {
                return false;
            }

            $process = $this->process('git config --get user.email');
            $email = trim($process->getOutput());

            if ($email && $process->getExitCode() === 0) {
                $identity .= " <$email>";
            }

            return $identity;

        } catch (RuntimeException $e) {

            // If no name/email is set in the user/project git config,
            // `git config` will exit with a non-zero code. In that case,
            // fall back to using the current username.
            return $identity ? $identity : get_current_user();
        }
    }
}
