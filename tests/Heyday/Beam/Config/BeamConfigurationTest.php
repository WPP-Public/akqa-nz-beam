<?php

namespace Heyday\Beam\Config;

use org\bovigo\vfs\vfsStream;
use Symfony\Component\Config\Definition\Processor;

class BeamConfigurationTest extends \PHPUnit_Framework_TestCase
{
    protected $config;
    protected function setUp()
    {
        vfsStream::setup();
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
                    'type' => 'rsync',
                    'sshpass' => false
                )
            ),
            $processedConfig['servers']
        );
    }

    public function testExcludesEmpty()
    {
        $reflection = new \ReflectionClass('\Heyday\Beam\Config\BeamConfiguration');
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
        
        $excludes = $reflection->getStaticProperties();

        $this->assertEquals(
            array(
                'servers' => array(
                    'live' => array(
                        'user' => 'test',
                        'host' => 'test',
                        'webroot' => 'test',
                        'type' => 'rsync',
                        'sshpass' => false
                    )
                ),
                'import' => array(),
                'commands' => array(),
                'exclude' => $excludes['defaultExcludes']
            ),
            $processedConfig
        );
    }

    public function testLoadImports()
    {
        $externalConfig = array(
            'exclude' => array(
                'patterns' => array(
                    '/secret/files/',
                    '.htaccess',
                    '_*/'
                )
            )
        );

        $otherExternalConfig = array(
            'commands' => array(
                array(
                    'command' => 'echo Hello World',
                    'phase' => 'pre',
                    'location' => 'local'
                )
            )
        );

        $file1 = vfsStream::url('root/some-beam-config.json');
        $file2 = vfsStream::url('root/some-other-beam-config.json');

        file_put_contents($file1, json_encode($externalConfig));
        file_put_contents($file2, json_encode($otherExternalConfig));

        $loaded = BeamConfiguration::loadImports(array($file1, $file2));

        $this->assertEquals(array(
                $externalConfig,
                $otherExternalConfig
            ),
            $loaded,
            'Beam config imports were not loaded correctly'
        );

        unlink($file1);
        unlink($file2);
    }

    public function testParseConfig()
    {
        $file1 = vfsStream::url('root/external-config-1.json');
        $file2 = vfsStream::url('root/external-config-2.json');

        $config = array(
            'import' => array(
                $file1,
            ),
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
        );

        $externalConfig = array(
            'import' => array(
                $file2
            ),
            'exclude' => array(
                'patterns' => array(
                    '/secret/files/',
                    '.htaccess',
                    '_*/',
                    'SUPER_FILE_3000'
                )
            )
        );

        $otherExternalConfig = array(
            'commands' => array(
                array(
                    'command' => 'echo Hello World',
                    'phase' => 'pre',
                    'location' => 'local'
                )
            )
        );

        file_put_contents($file1, json_encode($externalConfig));
        file_put_contents($file2, json_encode($otherExternalConfig));

        $reflection = new \ReflectionClass('\Heyday\Beam\Config\BeamConfiguration');
        
        $excludes = $reflection->getStaticProperties();

        $this->assertEquals(
            array(
                'import' => array(
                    $file1,
                    $file2
                ),
                'commands' => array(
                    array(
                        'command' => 'test',
                        'phase' => 'pre',
                        'location' => 'target',
                        'servers' => array(
                            'live'
                        ),
                        'tag' => false,
                        'tty' => false,
                        'required' => false
                    ),
                    array(
                        'command' => 'echo Hello World',
                        'phase' => 'pre',
                        'location' => 'local',
                        'servers' => array(),
                        'tag' => false,
                        'tty' => false,
                        'required' => false
                    )
                ),
                'exclude' => array_merge(
                    $excludes['defaultExcludes'],
                    array(
                        'test',
                        'hello',
                        '/secret/files/',
                        '.htaccess',
                        '_*/',
                        'SUPER_FILE_3000'
                    )
                ),
                'servers' => array(
                    'live' => array(
                        'user' => 'test',
                        'host' => 'test',
                        'webroot' => 'test',
                        'branch' => 'test',
                        'type' => 'rsync',
                        'sshpass' => false
                    )
                )
            ),
            BeamConfiguration::parseConfig($config),
            'Beam config did not parse correctly'
        );

        unlink($file1);
        unlink($file2);
    }
}