<?php

namespace Heyday\Beam\DeploymentProvider;

use Closure;
use Heyday\Beam\Beam;
use Heyday\Beam\Exception\RuntimeException;
use org\bovigo\vfs\vfsStream;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Process\Process;
use PHPUnit\Framework\TestCase;

class RsyncTest extends TestCase
{
    /**
     * @return MockObject
     */
    protected function getRsyncMock($methods = [])
    {
        return $this->createPartialMock(
            Rsync::class,
            $methods
        );
    }

    /**
     * @return MockObject
     */
    protected function getBeamMock($methods = [])
    {
        return $this->createPartialMock(
            Beam::class,
            $methods
        );
    }


    /**
     * @return MockObject
     */
    protected function getDeploymentResultMock()
    {
        return $this->createMock(DeploymentResult::class);
    }


    protected function getAccessibleMethod($methodName)
    {
        $method = new \ReflectionMethod(__NAMESPACE__ . '\Rsync', $methodName);
        $method->setAccessible(true);

        return $method;
    }

    public function testConstruct()
    {
        $rsync = new Rsync([]);
        $this->assertInstanceOf('Heyday\Beam\DeploymentProvider\Rsync', $rsync);
    }

    public function testUp()
    {
        $output = function () {
        };

        $rsync = $this->getRsyncMock(['deploy', 'buildCommand', 'getTargetPaths']);

        $rsync->expects($this->once())
            ->method('buildCommand')
            ->with(
                $this->equalTo('frompath'),
                $this->equalTo('topath'),
                $this->equalTo(false)
            )
            ->will($this->returnValue('test command'));

        $rsync->expects($this->once())
            ->method('deploy')
            ->with(
                $this->equalTo('test command'),
                $this->equalTo($output)
            )
            ->will($this->returnValue($this->getDeploymentResultMock()));

        $rsync->method('getTargetPaths')
            ->will($this->returnValue(['topath']));

        $beamMock = $this->getBeamMock(['getLocalPath']);

        $beamMock->expects($this->once())
            ->method('getLocalPath')
            ->will($this->returnValue('frompath'));

        $rsync->setBeam($beamMock);

        $rsync->up($output);
    }

    public function testUpDryrun()
    {
        $output = function () {
        };

        $rsync = $this->getRsyncMock(['deploy', 'buildCommand', 'getTargetPaths']);

        $rsync->expects($this->once())
            ->method('buildCommand')
            ->with(
                $this->equalTo('frompath'),
                $this->equalTo('topath'),
                $this->equalTo(true)
            )
            ->will($this->returnValue('test command'));

        $rsync->expects($this->once())
            ->method('deploy')
            ->with(
                $this->equalTo('test command'),
                $this->equalTo($output)
            )
            ->will($this->returnValue($this->getDeploymentResultMock()));

        $rsync->method('getTargetPaths')
            ->will($this->returnValue(['topath']));

        $beamMock = $this->getBeamMock([
            'getLocalPath',
        ]);

        $beamMock->expects($this->once())
            ->method('getLocalPath')
            ->will($this->returnValue('frompath'));

        $rsync->setBeam($beamMock);

        $rsync->up($output, true);
    }

    public function testDown()
    {
        $output = function () {
        };

        $rsync = $this->getRsyncMock(['deploy', 'buildCommand', 'getTargetPath']);

        $rsync->expects($this->once())
            ->method('buildCommand')
            ->with(
                $this->equalTo('topath'),
                $this->equalTo('frompath'),
                $this->equalTo(false)
            )
            ->will($this->returnValue('test command'));

        $rsync->expects($this->once())
            ->method('deploy')
            ->with(
                $this->equalTo('test command'),
                $this->equalTo($output)
            )
            ->will($this->returnValue($this->getDeploymentResultMock()));

        $rsync->expects($this->once())
            ->method('getTargetPath')
            ->will($this->returnValue('topath'));

        $beamMock = $this->getBeamMock([
            'getLocalPath'
        ]);

        $beamMock->expects($this->once())
            ->method('getLocalPath')
            ->will($this->returnValue('frompath'));

        $rsync->setBeam($beamMock);

        $rsync->down($output);
    }

    public function testDownDryrun()
    {
        $output = function () {
        };

        $rsync = $this->getRsyncMock(['deploy', 'buildCommand', 'getTargetPath']);

        $rsync->expects($this->once())
            ->method('buildCommand')
            ->with(
                $this->equalTo('topath'),
                $this->equalTo('frompath'),
                $this->equalTo(true)
            )
            ->will($this->returnValue('test command'));

        $rsync->expects($this->once())
            ->method('deploy')
            ->with(
                $this->equalTo('test command'),
                $this->equalTo($output)
            )
            ->will($this->returnValue($this->getDeploymentResultMock()));

        $rsync->expects($this->once())
            ->method('getTargetPath')
            ->will($this->returnValue('topath'));

        $beamMock = $this->getBeamMock([
            'getLocalPath'
        ]);

        $beamMock->expects($this->once())
            ->method('getLocalPath')
            ->will($this->returnValue('frompath'));

        $rsync->setBeam($beamMock);

        $rsync->down($output, true);
    }

    public function testDeploy()
    {
        /** @var MockObject */
        $processStub = $this->createMock(Process::class, [], [], '', false);

        $processStub->expects($this->once())
            ->method('run')
            ->with($this->isInstanceOf(Closure::class));

        $processStub->expects($this->once())
            ->method('isSuccessful')
            ->will($this->returnValue(true));

        $processStub->expects($this->once())
            ->method('getOutput')
            ->will(
                $this->returnValue(
                    <<<OUTPUT
*deleting test1
*deleting test2
>fcsT.... test3
>fcsT.... test4
>f+++++++ test5
>f+++++++ test6
>fcsTpog.ax test7
>f..t.... test8
cd....... test9
<f....... test10
.f....... test11
hf....... test12
<L....... test13
<D....... test14
<S....... test15
<f..T.... test16
>f..T.... test17
OUTPUT
                )
            );


        $rsync = $this->getRsyncMock(
            [
                'generateExcludesFile',
                'getProcess'
            ]
        );

        $rsync->expects($this->once())
            ->method('getProcess')
            ->with($this->equalTo('test command'))
            ->will($this->returnValue($processStub));

        $rsync->expects($this->once())
            ->method('generateExcludesFile');

        $this->assertEquals(
            new DeploymentResult(
                [
                    [
                        'update'   => 'deleted',
                        'filename' => 'test1',
                        'filetype' => 'file',
                        'reason'   =>
                            [
                                'missing',
                            ],
                    ],
                    [
                        'update'   => 'deleted',
                        'filename' => 'test2',
                        'filetype' => 'file',
                        'reason'   =>
                            [
                                'missing',
                            ],
                    ],
                    [
                        'update'   => 'received',
                        'filetype' => 'file',
                        'reason'   =>
                            [
                                'checksum',
                                'size',
                            ],
                        'filename' => 'test3',
                    ],
                    [
                        'update'   => 'received',
                        'filetype' => 'file',
                        'reason'   =>
                            [
                                'checksum',
                                'size',
                            ],
                        'filename' => 'test4',
                    ],
                    [
                        'update'   => 'received',
                        'filetype' => 'file',
                        'reason'   =>
                            [
                                'new',
                            ],
                        'filename' => 'test5',
                    ],
                    [
                        'update'   => 'received',
                        'filetype' => 'file',
                        'reason'   =>
                            [
                                'new',
                            ],
                        'filename' => 'test6',
                    ],
                    [
                        'update'   => 'received',
                        'filetype' => 'file',
                        'reason'   =>
                            [
                                'checksum',
                                'size',
                                'permissions',
                                'owner',
                                'group',
                                'acl',
                                'extended'
                            ],
                        'filename' => 'test7',
                    ],
                    [
                        'update'   => 'created',
                        'filetype' => 'directory',
                        'reason'   =>
                            [],
                        'filename' => 'test9',
                    ],
                    [
                        'update'   => 'sent',
                        'filetype' => 'file',
                        'reason'   =>
                            [],
                        'filename' => 'test10',
                    ],
                    [
                        'update'   => 'attributes',
                        'filetype' => 'file',
                        'reason'   =>
                            [],
                        'filename' => 'test11',
                    ],
                    [
                        'update'   => 'link',
                        'filetype' => 'file',
                        'reason'   =>
                            [],
                        'filename' => 'test12',
                    ],
                    [
                        'update'   => 'sent',
                        'filetype' => 'symlink',
                        'reason'   =>
                            [],
                        'filename' => 'test13',
                    ],
                    [
                        'update'   => 'sent',
                        'filetype' => 'device',
                        'reason'   =>
                            [],
                        'filename' => 'test14',
                    ],
                    [
                        'update'   => 'sent',
                        'filetype' => 'special',
                        'reason'   =>
                            [],
                        'filename' => 'test15',
                    ],
                ]
            ),
            $this->getAccessibleMethod('deploy')->invoke(
                $rsync,
                'test command'
            )
        );
    }


    public function testDeployException()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Some error');

        /** @var MockObject */
        $processStub = $this->createMock(Process::class, [], [], '', false);

        $processStub->expects($this->once())
            ->method('run')
            ->with($this->isInstanceOf(Closure::class));

        $processStub->expects($this->once())
            ->method('isSuccessful')
            ->will($this->returnValue(false));

        $processStub->expects($this->once())
            ->method('getErrorOutput')
            ->will($this->returnValue('Some error'));


        $rsync = $this->getRsyncMock(
            [
                'generateExcludesFile',
                'getProcess'
            ]
        );

        $rsync->expects($this->once())
            ->method('getProcess')
            ->with($this->equalTo('test command'))
            ->will($this->returnValue($processStub));

        $rsync->expects($this->once())
            ->method('generateExcludesFile');

        $this->getAccessibleMethod('deploy')->invoke(
            $rsync,
            'test command'
        );
    }

    public function testGetExcludesPath()
    {
        $rsync = new Rsync([]);

        $beamMock = $this->getBeamMock([
            'getLocalPathname'
        ]);

        $beamMock->expects($this->once())
            ->method('getLocalPathname')
            ->will($this->returnValue('test'));

        $rsync->setBeam($beamMock);

        $this->assertEquals(
            '/tmp/test.excludes',
            $this->getAccessibleMethod('getExcludesPath')->invoke($rsync)
        );
    }

    public function testGetTargetPath()
    {
        $rsync = new Rsync([]);

        $beamMock = $this->getBeamMock([
            'getServer',
            'getPrimaryHost'
        ]);

        $beamMock->expects($this->once())
            ->method('getServer')
            ->will(
                $this->returnValue(
                    [
                        'user'    => 'user',
                        'host'    => 'host',
                        'webroot' => 'webroot'
                    ]
                )
            );

        $beamMock->expects($this->once())
            ->method('getPrimaryHost')
            ->will($this->returnValue('host'));

        $rsync->setBeam($beamMock);

        $this->assertEquals(
            'user@host:webroot',
            $rsync->getTargetPath()
        );
    }

    public function testGenerateExcludesFile()
    {
        vfsStream::setup(
            'root',
            0755,
            []
        );

        $beamMock = $this->getBeamMock([
            'getConfig',
            'hasPath'
        ]);

        $beamMock->expects($this->once())
            ->method('getConfig')
            ->will(
                $this->returnValue(
                    [
                        'test',
                        'test2'
                    ]
                )
            );

        $beamMock->expects($this->once())
            ->method('hasPath')
            ->will(
                $this->returnValue(false)
            );

        $rsync = $this->getRsyncMock(
            [
                'getExcludesPath'
            ]
        );

        $rsync->expects($this->once())
            ->method('getExcludesPath')
            ->will($this->returnValue(vfsStream::url('root/excludes')));

        $rsync->setBeam($beamMock);

        $this->getAccessibleMethod('generateExcludesFile')->invoke($rsync);

        $this->assertEquals(
            <<<OUTPUT
test
test2

OUTPUT
            ,
            file_get_contents(vfsStream::url('root/excludes'))
        );
    }

    public function testGenerateExcludesFileWithPath()
    {
        vfsStream::setup(
            'root',
            0755,
            []
        );

        $beamMock = $this->getBeamMock([
            'getConfig',
            'hasPath',
            'getOption'
        ]);

        $beamMock->expects($this->once())
            ->method('getConfig')
            ->will(
                $this->returnValue(
                    [
                        'test',
                        'test2'
                    ]
                )
            );

        $beamMock->expects($this->once())
            ->method('hasPath')
            ->will(
                $this->returnValue(true)
            );

        $beamMock->expects($this->once())
            ->method('getOption')
            ->with($this->equalTo('path'))
            ->will(
                $this->returnValue('test')
            );

        $rsync = $this->getRsyncMock(
            [
                'getExcludesPath'
            ]
        );

        $rsync->expects($this->once())
            ->method('getExcludesPath')
            ->will($this->returnValue(vfsStream::url('root/excludes')));

        $rsync->setBeam($beamMock);

        $this->getAccessibleMethod('generateExcludesFile')->invoke($rsync);

        $this->assertEquals(
            "test2\n",
            file_get_contents(vfsStream::url('root/excludes'))
        );
    }

    public function testBuildCommand()
    {
        $rsync = $this->getRsyncMock(
            [
                'getExcludesPath'
            ]
        );

        $rsync->expects($this->atLeastOnce())
            ->method('getExcludesPath')
            ->will($this->returnValue('/test'));

        $beamMock = $this->getBeamMock([
            'hasPath',
            'getServer'
        ]);

        $beamMock->expects($this->atLeastOnce())
            ->method('hasPath')
            ->will(
                $this->returnValue(false)
            );

        $server = [
            'syncPermissions' => false
        ];

        $beamMock->method('getServer')->will(
            $this->returnCallback(function () use (&$server) {
                return $server;
            })
        );

        $rsync->setBeam($beamMock);

        $this->assertEquals(
            'rsync /testfrom/ /testto -rlD --itemize-changes --checksum --compress --delay-updates --exclude-from="/test"',
            $this->getAccessibleMethod('buildCommand')->invoke(
                $rsync,
                '/testfrom',
                '/testto'
            )
        );

        $server['syncPermissions'] = true;

        $this->assertEquals(
            'rsync /testfrom/ /testto -rlD --itemize-changes --perms --dry-run --checksum --compress --delay-updates --exclude-from="/test"',
            $this->getAccessibleMethod('buildCommand')->invoke(
                $rsync,
                '/testfrom',
                '/testto',
                true
            )
        );

        $server['syncPermissions'] = false;

        // Pretend to be an old version of rsync to output pre-3.0.1 compatible options
        $rsync = $this->getRsyncMock(
            [
                'getExcludesPath',
                'getRsyncVersion'
            ]
        );

        $rsync->setOption('delete', true);

        $rsync->expects($this->atLeastOnce())
            ->method('getExcludesPath')
            ->will($this->returnValue('/test'));

        $rsync->setBeam($beamMock);

        $rsync->method('getRsyncVersion')->willReturn('2.6.0');

        $this->assertEquals(
            'rsync /testfrom/ /testto -rlD --itemize-changes --checksum --delete --compress --delay-updates --delete-after --exclude-from="/test"',
            $this->getAccessibleMethod('buildCommand')->invoke(
                $rsync,
                '/testfrom',
                '/testto'
            )
        );

        // Pretend to be the minimum rsync version required for --delete-delay to work
        $rsync = $this->getRsyncMock(
            [
                'getExcludesPath',
                'getRsyncVersion'
            ]
        );

        $rsync->setOption('delete', true);

        $rsync->method('getRsyncVersion')->willReturn('3.0.0');
        $rsync->method('getExcludesPath')->will($this->returnValue('/test'));
        $rsync->setBeam($beamMock);

        $this->assertEquals(
            'rsync /testfrom/ /testto -rlD --itemize-changes --checksum --delete --compress --delay-updates --delete-delay --exclude-from="/test"',
            $this->getAccessibleMethod('buildCommand')->invoke(
                $rsync,
                '/testfrom',
                '/testto'
            )
        );
    }

    public function testGetRsyncVersion()
    {
        $rsync = $this->getRsyncMock([
            'getRsyncVersion'
        ]);

        $rsync->method('getRsyncVersion')->willReturn('3.0.0');

        $version = $this->getAccessibleMethod('getRsyncVersion')
            ->invoke($rsync);

        $this->assertEquals(1, preg_match('/^\d+\.\d+\.\d+/', $version), 'Check retrieved rsync version number looks like a version');
    }
}
