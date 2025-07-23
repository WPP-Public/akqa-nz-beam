<?php

namespace Heyday\Beam\Config;

use org\bovigo\vfs\vfsStream;
use Symfony\Component\Config\Definition\Processor;
use PHPUnit\Framework\TestCase;

class BeamConfigurationTest extends TestCase
{
    protected $config;

    protected function setUp(): void
    {
        vfsStream::setup();
        $this->config = new BeamConfiguration();
    }

    public function testProcess()
    {
        $processor = new Processor();
        $processedConfig = $processor->processConfiguration(
            $this->config,
            [
                [
                    'commands' => [
                        [
                            'command' => 'test',
                            'phase' => 'pre',
                            'location' => 'target',
                            'servers' => [
                                'live'
                            ]
                        ]
                    ],
                    'exclude' => [
                        'patterns' => [
                            'test',
                            'hello'
                        ]
                    ],
                    'servers' => [
                        'live' => [
                            'user' => 'test',
                            'host' => 'test',
                            'webroot' => 'test',
                            'branch' => 'test',
                            'syncPermissions' => false,
                        ]
                    ]
                ]
            ]
        );

        $this->assertTrue(isset($processedConfig['commands']));

        $this->assertEquals(
            [
                [
                    'command' => 'test',
                    'phase' => 'pre',
                    'location' => 'target',
                    'servers' => [
                        'live'
                    ],
                    'required' => false,
                    'tag' => false,
                    'tty' => false
                ]
            ],
            $processedConfig['commands']
        );

        $this->assertTrue(isset($processedConfig['exclude']));
        $this->assertTrue(count($processedConfig['exclude']) > 0);
        $this->assertTrue(in_array('test', $processedConfig['exclude']));
        $this->assertTrue(in_array('hello', $processedConfig['exclude']));

        $this->assertTrue(isset($processedConfig['servers']));

        $this->assertEquals(
            [
                'live' => [
                    'user' => 'test',
                    'host' => 'test',
                    'webroot' => 'test',
                    'branch' => 'test',
                    'type' => 'rsync',
                    'sshpass' => false,
                    'syncPermissions' => false,
                    'hosts' => [],
                ]
            ],
            $processedConfig['servers']
        );
    }

    public function testExcludesEmpty()
    {
        $reflection = new \ReflectionClass('\Heyday\Beam\Config\BeamConfiguration');
        $processor = new Processor();
        $processedConfig = $processor->processConfiguration(
            $this->config,
            [
                [
                    'servers' => [
                        'live' => [
                            'user' => 'test',
                            'host' => 'test',
                            'webroot' => 'test',
                            'hosts' => [],
                        ]
                    ]
                ]
            ]
        );

        $excludes = $reflection->getStaticProperties();

        $this->assertEquals(
            [
                'servers' => [
                    'live' => [
                        'user' => 'test',
                        'host' => 'test',
                        'webroot' => 'test',
                        'type' => 'rsync',
                        'sshpass' => false,
                        'syncPermissions' => true,
                        'hosts' => [],
                    ]
                ],
                'import' => [],
                'commands' => [],
                'exclude' => $excludes['defaultExcludes']
            ],
            $processedConfig
        );
    }

    public function testLoadImports()
    {
        $externalConfig = [
            'exclude' => [
                'patterns' => [
                    '/secret/files/',
                    '.htaccess',
                    '_*/'
                ]
            ]
        ];

        $otherExternalConfig = [
            'commands' => [
                [
                    'command' => 'echo Hello World',
                    'phase' => 'pre',
                    'location' => 'local'
                ]
            ]
        ];

        $file1 = vfsStream::url('root/some-beam-config.json');
        $file2 = vfsStream::url('root/some-other-beam-config.json');

        file_put_contents($file1, json_encode($externalConfig));
        file_put_contents($file2, json_encode($otherExternalConfig));

        $loaded = BeamConfiguration::loadImports([$file1, $file2]);

        $this->assertEquals(
            [
                $externalConfig,
                $otherExternalConfig
            ],
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

        $config = [
            'import' => [
                $file1,
            ],
            'commands' => [
                [
                    'command' => 'test',
                    'phase' => 'pre',
                    'location' => 'target',
                    'servers' => [
                        'live'
                    ]
                ]
            ],
            'exclude' => [
                'patterns' => [
                    'test',
                    'hello'
                ]
            ],
            'servers' => [
                'live' => [
                    'user' => 'test',
                    'host' => 'test',
                    'webroot' => 'test',
                    'branch' => 'test'
                ]
            ]
        ];

        $externalConfig = [
            'import' => [
                $file2
            ],
            'exclude' => [
                'patterns' => [
                    '/secret/files/',
                    '.htaccess',
                    '_*/',
                    'SUPER_FILE_3000'
                ]
            ]
        ];

        $otherExternalConfig = [
            'commands' => [
                [
                    'command' => 'echo Hello World',
                    'phase' => 'pre',
                    'location' => 'local'
                ]
            ]
        ];

        file_put_contents($file1, json_encode($externalConfig));
        file_put_contents($file2, json_encode($otherExternalConfig));

        $reflection = new \ReflectionClass('\Heyday\Beam\Config\BeamConfiguration');

        $excludes = $reflection->getStaticProperties();

        $this->assertEquals(
            [
                'import' => [
                    $file1,
                    $file2
                ],
                'commands' => [
                    [
                        'command' => 'test',
                        'phase' => 'pre',
                        'location' => 'target',
                        'servers' => [
                            'live'
                        ],
                        'tag' => false,
                        'tty' => false,
                        'required' => false
                    ],
                    [
                        'command' => 'echo Hello World',
                        'phase' => 'pre',
                        'location' => 'local',
                        'servers' => [],
                        'tag' => false,
                        'tty' => false,
                        'required' => false
                    ]
                ],
                'exclude' => array_merge(
                    $excludes['defaultExcludes'],
                    [
                        'test',
                        'hello',
                        '/secret/files/',
                        '.htaccess',
                        '_*/',
                        'SUPER_FILE_3000'
                    ]
                ),
                'servers' => [
                    'live' => [
                        'user' => 'test',
                        'host' => 'test',
                        'webroot' => 'test',
                        'branch' => 'test',
                        'type' => 'rsync',
                        'sshpass' => false,
                        'syncPermissions' => true,
                        'hosts' => [],
                    ]
                ]
            ],
            BeamConfiguration::parseConfig($config),
            'Beam config did not parse correctly'
        );

        unlink($file1);
        unlink($file2);
    }
}
