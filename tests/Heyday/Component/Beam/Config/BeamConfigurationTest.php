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

        $this->assertTrue(isset($processedConfig['commands']));

        $this->assertEquals(
            array(
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
            $processedConfig['commands']
        );

        $this->assertTrue(isset($processedConfig['exclude']));
        $this->assertTrue(count($processedConfig['exclude']) > 0);
        $this->assertTrue(in_array('test', $processedConfig['exclude']));
        $this->assertTrue(in_array('hello', $processedConfig['exclude']));

        $this->assertTrue(isset($processedConfig['servers']));

        $this->assertEquals(
            array(
                'live' => array(
                    'user' => 'test',
                    'host' => 'test',
                    'webroot' => 'test',
                    'branch' => 'test',
                    'type' => 'rsync'
                )
            ),
            $processedConfig['servers']
        );
    }

    public function testExcludesEmpty()
    {
        $processor = new Processor();
        $processedConfig = $processor->processConfiguration(
            $this->config,
            array(
                array(
                    'servers' => array(
                        'live' => array(
                            'user' => 'test',
                            'host' => 'test',
                            'webroot' => 'test'
                        )
                    )
                )
            )
        );
        $this->assertEquals(
            array(
                'servers' => array(
                    'live' => array(
                        'user' => 'test',
                        'host' => 'test',
                        'webroot' => 'test',
                        'type' => 'rsync'
                    )
                ),
                'commands' => array(),
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
                    '/maintenance/on'
                )
            ),
            $processedConfig
        );
    }
}
