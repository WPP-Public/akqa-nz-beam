<?php

namespace Heyday\Component\Beam\Config;

use Symfony\Component\Config\Definition\Processor;

class BeamConfigurationTest extends \PHPUnit_Framework_TestCase
{
    protected $config;
    protected function setUp()
    {
        $this->config = new BeamConfiguration();
    }
    public function testProcess()
    {
        $processor = new Processor();
        $processedConfig = $processor->processConfiguration(
            $this->config,
            array(
                array(
                    'commands' => array(
                        array(
                            'command' => 'test',
                            'phase' => 'pre',
                            'location' => 'target',
                            'servers' => array(
                                'live'
                            )
                        )
                    ),
                    'exclude' => array(
                        'applications' => array(
                            '_base',
                            'gear',
                            'silverstripe',
                            'symfony',
                            'wordpress',
                            'zf'
                        ),
                        'patterns' => array(
                            'test',
                            'hello'
                        )
                    ),
                    'servers' => array(
                        'live' => array(
                            'user' => 'test',
                            'host' => 'test',
                            'webroot' => 'test',
                            'branch' => 'test'
                        )
                    )
                )
            )
        );
        $this->assertEquals(
            array(
                'commands' => array(
                    array(
                        'command' => 'test',
                        'phase' => 'pre',
                        'location' => 'target',
                        'servers' => array(
                            'live'
                        ),
                        'required' => false,
                        'tag' => false,
                        'tty' => false
                    )
                ),
                'exclude' => array(
                    '*~',
                    '.DS_Store',
                    '.gitignore',
                    '.mergesources.yml',
                    'README.md',
                    'composer.json',
                    'composer.lock',
                    'deploy.json',
                    'beam.json',
                    'deploy.properties',
                    'sftp-config.json',
                    'checksums.json*',
                    '/access-logs/',
                    '/cgi-bin/',
                    '/.idea/',
                    '.svn/',
                    '.git/',
                    '/images/repository/',
                    '/assets/',
                    '/silverstripe-cache/',
                    '/assets-generated/',
                    '/cache-include/cache/',
                    '/heyday-cacheinclude/cache/',
                    '/silverstripe-cacheinclude/cache/',
                    '/cache/',
                    '/data/lucene/',
                    '/config/log/',
                    '/data/lucene/',
                    '/lib/form/base/',
                    '/lib/model/map/',
                    '/lib/model/om/',
                    '/log/',
                    '/web/uploads/',
                    'wp-content/uploads/',
                    '/www/uploads/',
                    '/web/uploads/',
                    'test',
                    'hello'
                ),
                'servers' => array(
                    'live' => array(
                        'user' => 'test',
                        'host' => 'test',
                        'webroot' => 'test',
                        'branch' => 'test'
                    )
                )
            ),
            $processedConfig
        );
    }
}
