<?php

namespace Heyday\Beam\Config;

use Heyday\Beam\VcsProvider\GitLikeVcsProvider;

class ValueInterpolator
{
    /**
     * @var GitLikeVcsProvider
     */
    protected $vcs;

    /**
     * Reference to use when retrieving information from the VCS
     *
     * @var string
     */
    protected $ref;

    /**
     * Map of identifiers to replacement values
     *
     * @var array
     */
    protected $extraReplacements;

    /**
     * @param GitLikeVcsProvider $vcs
     * @param string $vcsReference - commit reference to use when retrieving information from the VCS
     * @param array $extraReplacements - array of tokens to provide replacement for
     */
    public function __construct(GitLikeVcsProvider $vcs, $vcsReference, array $extraReplacements = array())
    {
        $this->vcs = $vcs;
        $this->ref = $vcsReference;
        $this->extraReplacements = $extraReplacements;
    }

    /**
     * Apply interpolation (variable replacement) recursively to array values
     *
     * @param array $config
     * @return array - array with variables replaced
     */
    public function process(array $config)
    {
        $interpolations = $this->getInterpolationsWithCaching();

        array_walk_recursive($config, function(&$value, $key) use ($interpolations) {
            foreach ($interpolations as $token => $replaceCallback) {
                if (strpos($value, $token) !== false) {
                    $value = str_replace($token, $replaceCallback(), $value);
                }
            }
        });

        return $config;
    }

    /**
     * Return an array of callbacks to use with str_replace
     *
     * @return callable[]
     */
    protected function getInterpolations()
    {
        $vcs = $this->vcs;
        $ref = $this->ref;

        $interpolations = array(
            '%%branch%%' => function() use ($vcs, $ref) {
                return $vcs->getBranchForReference($ref);
            },
            '%%branch_pathsafe%%' => function() use ($vcs, $ref) {
                $branch = $vcs->getBranchForReference($ref);
                return str_replace(DIRECTORY_SEPARATOR, '-', $branch);
            },
            '%%commit%%' => function() use ($vcs, $ref) {
                return $vcs->resolveReference($ref);
            },
            '%%commit_abbrev%%' => function() use ($vcs, $ref) {
                return $vcs->resolveReference($ref, true);
            },
            '%%user_identity%%' => function() use ($vcs) {
                return $vcs->getUserIdentity();
            },
            '%%username%%' => get_current_user(),
        );

        foreach ($this->extraReplacements as $token => $value) {
            $interpolations["%%$token%%"] = $value;
        }

        return $interpolations;
    }

    /**
     * Return the results of self::getInterpolations wrapped in caching callbacks
     *
     * The first value returned for a token is cached so that multiple replacements do not
     * generate the value multiple times (done using command-line programs, so it's slow-ish)
     *
     * @return callable[]
     */
    protected function getInterpolationsWithCaching()
    {
        $callbacks = array();
        $cache = array();

        foreach ($this->getInterpolations() as $token => $value) {
            $callbacks[$token] = function() use ($token, $value, &$cache) {
                if (is_callable($value)) {
                    if (!array_key_exists($token, $cache)) {
                        $cache[$token] = $value();
                    }

                    return $cache[$token];
                }

                return $value;
            };
        }

        return $callbacks;
    }
}
