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
                        'test command' => array(
                            'command' => 'test',
                            'phase' => 'pre',
                            'location' => 'remote',
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
                            'symfony'
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
                    'test command' => array(
                        'command' => 'test',
                        'phase' => 'pre',
                        'location' => 'remote',
                        'servers' => array(
                            'live'
                        )
                    )
                ),
                'exclude' => array(
                    '*~',
                    '.DS_Store',
                    '.git',
                    '.gitignore',
                    '.mergesources.yml',
                    '.svn',
                    'README.md',
                    'composer.json',
                    'composer.lock',
                    'deploy.json',
                    'deploy.properties',
                    'exclude.properties',
                    'sftp-config.json',
                    '/access-logs/',
                    '/cgi-bin/',
                    '/.idea/',
                    '/images/repository/',
                    '/assets/',
                    '/silverstripe-cache/',
                    '/cache-include/cache/',
                    '/heyday-cacheinclude/cache/',
                    '/assets-generated/',
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
