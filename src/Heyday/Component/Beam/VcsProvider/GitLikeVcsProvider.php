<?php


namespace Heyday\Component\Beam\VcsProvider;


interface GitLikeVcsProvider extends VcsProvider
{
    /**
     * Return the commit hash for a given reference
     *
     * @param string $ref
     * @param bool $abbreviated
     * @return string - commit hash
     */
    public function resolveReference($ref, $abbreviated = false);

    /**
     * Determine the branch a reference is on
     *
     * @param string $ref
     * @return string - branch name
     */
    public function getBranchForReference($ref);

    /**
     * Get the identity of a user as defined in the VCS config
     *
     * This is the name that the VCS uses to identify a user in commits.
     * Output should be in the format:
     *
     *     John Doe <john.doe@example.com>
     *
     * @return string
     */
    public function getUserIdentity();
}