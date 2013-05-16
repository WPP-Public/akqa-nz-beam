<?php

namespace Heyday\Component\Beam\Vcs;

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
     * A helper method for determining whether the src directory has a version control system
     * @return bool
     */
    public function exists();
    /**
     * Exports the appropriate branch for deployment
     */
    public function exportBranch($branch, $location);
    /**
     * Updates the local remote branches
     * @throws \RuntimeException
     */
    public function updateBranch($branch);
    /**
     * @param $branch
     * @return mixed
     */
    public function getLog($branch);
    /**
     * @param $branch
     * @return bool
     */
    public function isRemote($branch);
}
