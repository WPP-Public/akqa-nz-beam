<?php

namespace Heyday\Beam\VcsProvider;

use Heyday\Beam\Utils;
use Heyday\Beam\Exception\InvalidConfigurationException;
use Heyday\Beam\Exception\RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Class Git
 * @package Heyday\Beam\VcsProvider
 */
class Git implements GitLikeVcsProvider
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
        $matches = [];
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
     *
     * @param string|string[] $command
     * @throws RuntimeException
     * @return Process
     */
    public function process($command): Process
    {
        $process = $this->getProcess($command);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new RuntimeException($process->getErrorOutput());
        }

        return $process;
    }


    /**
     * @param string $command
     * @return Process
     */
    public function getProcess($command): Process
    {
        return Process::fromShellCommandline($command, $this->srcdir);
    }


    /**
     * @param string $ref
     *
     * @return string
     */
    public function getLog($ref): string
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
     * @param string $branch
     * @return bool
     */
    public function isRemote($branch): bool
    {
        return (bool) $this->getRemoteName($branch);
    }


    /**
     * @param string $branch
     * @return array|bool
     */
    public function getRemoteName($branch)
    {
        if (strpos($branch, 'remotes/') !== 0) {
            return false;
        }

        // Remove 'remotes/' prefix
        $branch = substr($branch, 8);

        // Split into remote and branch parts
        $parts = explode('/', $branch, 2);

        if (count($parts) !== 2) {
            return false;
        }

        return $parts;
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

    /**
     * Return the commit hash for a given reference
     *
     * @param string $ref
     * @param bool $abbreviated
     * @return string - commit hash
     * @throws RuntimeException
     */
    public function resolveReference($ref, $abbreviated = false)
    {
        if ($abbreviated) {
            $command = 'git rev-parse --short %s';
        } else {
            $command = 'git rev-parse %s';
        }

        $process = $this->process(sprintf(
            $command,
            escapeshellarg($ref)
        ));

        return trim($process->getOutput());
    }

    /**
     * Determine the branch a reference is on
     *
     * In the case multiple branches are returned, the current branch is preferred,
     * otherwise the first returned by Git is used. This means the return value of
     * this function is a bit of a guess.
     *
     * @param string $ref
     * @return string - branch name
     */
    public function getBranchForReference($ref)
    {
        $process = $this->process(sprintf(
            'git branch --contains %s -a',
            escapeshellarg($ref)
        ));

        $lines = explode("\n", trim($process->getOutput()));

        foreach ($lines as $index => $line) {
            // Prefer the current branch
            if (strpos($line, '*') === 0) {
                $branch = $line;
                break;
            }

            // Default to the first line if there's no current branch returned
            if ($index === 0) {
                $branch = $line;
            }
        }

        return ltrim($branch, '* ');
    }
}
