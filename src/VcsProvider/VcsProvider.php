<?php

namespace Heyday\Beam\VcsProvider;

/**
 * Class VcsProvider
 * @package Heyday\Beam\VcsProvider
 */
interface VcsProvider
{
    /**
     * @param string $srcdir
     */
    public function __construct(string $srcdir);

    /**
     * Get the current branch the user is on
     * @return string
     */
    public function getCurrentBranch(): string;

    /**
     * Returns the branches that are available for the source directory
     * @return array
     */
    public function getAvailableBranches(): array;

    /**
     * Checks if a ref is valid in the source directory
     * @param $ref
     * @return boolean
     */
    public function isValidRef(string $ref): bool;

    /**
     * A helper method for determining whether the src directory has a version control system
     * @return bool
     */
    public function exists(): bool;

    /**
     * Exports the appropriate branch for deployment
     */
    public function exportRef(string $branch, string $location): void;

    /**
     * Updates the local remote branches
     * @throws \Exception
     */
    public function updateBranch(string $branch): void;

    /**
     * @param string $ref
     */
    public function getLog(string $ref): string;

    /**
     * @param $branch
     * @return bool
     */
    public function isRemote(string $branch): bool;
}
