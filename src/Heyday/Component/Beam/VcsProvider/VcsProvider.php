<?php

namespace Heyday\Component\Beam\VcsProvider;

/**
 * Class VcsProvider
 * @package Heyday\Component\Beam\VcsProvider
 */
interface VcsProvider
{
    /**
     * @param $srcdir
     */
    public function __construct($srcdir);
    /**
     * Get the current branch the user is on
     * @return string
     */
    public function getCurrentBranch();
    /**
     * Returns the branches that are available for the source directory
     * @return array
     */
    public function getAvailableBranches();
    /**
     * Checks if a ref is valid in the source directory
     * @param $ref
     * @return boolean
     */
    public function isValidRef($ref);
    /**
     * A helper method for determining whether the src directory has a version control system
     * @return bool
     */
    public function exists();
    /**
     * Exports the appropriate branch for deployment
     */
    public function exportRef($branch, $location);
    /**
     * Updates the local remote branches
     * @throws \Exception
     */
    public function updateBranch($branch);
    /**
     * @param $ref
     * @return mixed
     */
    public function getLog($ref);
    /**
     * @param $branch
     * @return bool
     */
    public function isRemote($branch);
}
