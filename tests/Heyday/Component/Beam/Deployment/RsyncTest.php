<?php

namespace Heyday\Component\Beam\Deployment;

use org\bovigo\vfs\vfsStream;

class RsyncTest extends \PHPUnit_Framework_TestCase
{
    /**
     * Sets up the fixture, for example, opens a network connection.
     * This method is called before a test is executed.
     */
    protected function setUp()
    {
//        $this->validOptions = array(
//            'checksum' => true,
//            'delete'
//        );
    }

    protected function getRsyncMock($methods = array(), $options = array())
    {
        return $this->getMock(
            __NAMESPACE__ . '\Rsync',
            $methods,
            array(
                $options
            )
        );
    }

    protected function getBeamMock()
    {
        return $this->getMock(
            'Heyday\Component\Beam\Beam',
            array(),
            array(),
            '',
            false
        );
    }

    protected function getDeploymentResultMock($result = array())
    {
        return $this->getMock(
            'Heyday\Component\Beam\Deployment\DeploymentResult',
            array(),
            array(
                $result
            )
        );
    }

    protected function getAccessibleMethod($methodName)
    {
        $method = new \ReflectionMethod(__NAMESPACE__ . '\Rsync', $methodName);
        $method->setAccessible(true);

        return $method;
    }

    public function testConstruct()
    {
        $rsync = new Rsync(array());
        $this->assertInstanceOf('Heyday\Component\Beam\Deployment\Rsync', $rsync);
    }

    public function testUp()
    {
        $output = function () {};

        $rsync = $this->getRsyncMock(array('deploy', 'buildCommand', 'getTargetPath'));

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

        $rsync->expects($this->once())
            ->method('getTargetPath')
            ->will($this->returnValue('topath'));

        $beamMock = $this->getBeamMock();

        $beamMock->expects($this->once())
            ->method('getLocalPath')
            ->will($this->returnValue('frompath'));

        $rsync->setBeam($beamMock);

        $rsync->up($output);
    }

    public function testUpDryrun()
    {
        $output = function () {};

        $rsync = $this->getRsyncMock(array('deploy', 'buildCommand', 'getTargetPath'));

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

        $rsync->expects($this->once())
            ->method('getTargetPath')
            ->will($this->returnValue('topath'));

        $beamMock = $this->getBeamMock();

        $beamMock->expects($this->once())
            ->method('getLocalPath')
            ->will($this->returnValue('frompath'));

        $rsync->setBeam($beamMock);

        $rsync->up($output, true);
    }

    public function testDown()
    {
        $output = function () {};

        $rsync = $this->getRsyncMock(array('deploy', 'buildCommand', 'getTargetPath'));

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

        $beamMock = $this->getBeamMock();

        $beamMock->expects($this->once())
            ->method('getLocalPath')
            ->will($this->returnValue('frompath'));

        $rsync->setBeam($beamMock);

        $rsync->down($output);
    }

    public function testDownDryrun()
    {
        $output = function () {};

        $rsync = $this->getRsyncMock(array('deploy', 'buildCommand', 'getTargetPath'));

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

        $beamMock = $this->getBeamMock();

        $beamMock->expects($this->once())
            ->method('getLocalPath')
            ->will($this->returnValue('frompath'));

        $rsync->setBeam($beamMock);

        $rsync->down($output, true);
    }

    public function testDeploy()
    {
        $processStub = $this->getMock('Symfony\Component\Process\Process', array(), array(), '', false);

        $processStub->expects($this->once())
            ->method('run')
            ->with($this->equalTo(null));

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
OUTPUT
                )
            );


        $rsync = $this->getRsyncMock(
            array(
                'generateExcludesFile',
                'getProcess'
            )
        );

        $rsync->expects($this->once())
            ->method('getProcess')
            ->with($this->equalTo('test command'))
            ->will($this->returnValue($processStub));

        $rsync->expects($this->once())
            ->method('generateExcludesFile');

        $this->assertEquals(
            new DeploymentResult(
                array(
                    array(
                        'update' => 'deleted',
                        'filename' => 'test1',
                        'filetype' => 'file',
                        'reason' =>
                        array (
                           'missing',
                        ),
                    ),
                    array (
                        'update' => 'deleted',
                        'filename' => 'test2',
                        'filetype' => 'file',
                        'reason' =>
                        array (
                            'missing',
                        ),
                    ),
                    array (
                        'update' => 'received',
                        'filetype' => 'file',
                        'reason' =>
                        array (
                            'checksum',
                            'size',
                        ),
                        'filename' => 'test3',
                    ),
                    array (
                        'update' => 'received',
                        'filetype' => 'file',
                        'reason' =>
                        array (
                            'checksum',
                            'size',
                        ),
                        'filename' => 'test4',
                    ),
                    array (
                        'update' => 'received',
                        'filetype' => 'file',
                        'reason' =>
                        array (
                            'new',
                        ),
                        'filename' => 'test5',
                    ),
                    array (
                        'update' => 'received',
                        'filetype' => 'file',
                        'reason' =>
                        array (
                            'new',
                        ),
                        'filename' => 'test6',
                    ),
                    array (
                        'update' => 'received',
                        'filetype' => 'file',
                        'reason' =>
                        array (
                            'checksum',
                            'size',
                            'permissions',
                            'owner',
                            'group',
                            'acl',
                            'extended'
                        ),
                        'filename' => 'test7',
                    ),
                    array (
                        'update' => 'created',
                        'filetype' => 'directory',
                        'reason' =>
                        array (
                        ),
                        'filename' => 'test9',
                    ),
                    array (
                        'update' => 'sent',
                        'filetype' => 'file',
                        'reason' =>
                        array (
                        ),
                        'filename' => 'test10',
                    ),
                    array (
                        'update' => 'attributes',
                        'filetype' => 'file',
                        'reason' =>
                        array (
                        ),
                        'filename' => 'test11',
                    ),
                    array (
                        'update' => 'link',
                        'filetype' => 'file',
                        'reason' =>
                        array (
                        ),
                        'filename' => 'test12',
                    ),
                    array (
                        'update' => 'sent',
                        'filetype' => 'symlink',
                        'reason' =>
                        array (
                        ),
                        'filename' => 'test13',
                    ),
                    array (
                        'update' => 'sent',
                        'filetype' => 'device',
                        'reason' =>
                        array (
                        ),
                        'filename' => 'test14',
                    ),
                    array (
                        'update' => 'sent',
                        'filetype' => 'special',
                        'reason' =>
                        array (
                        ),
                        'filename' => 'test15',
                    ),
                )
            ),
            $this->getAccessibleMethod('deploy')->invoke(
                $rsync,
                'test command'
            )
        );
    }
    /**
     * @expectedException RuntimeException
     * @expectedExceptionMessage Some error
     */
    public function testDeployException()
    {
        $processStub = $this->getMock('Symfony\Component\Process\Process', array(), array(), '', false);

        $processStub->expects($this->once())
            ->method('run')
            ->with($this->equalTo(null));

        $processStub->expects($this->once())
            ->method('isSuccessful')
            ->will($this->returnValue(false));

        $processStub->expects($this->once())
            ->method('getErrorOutput')
            ->will($this->returnValue('Some error'));


        $rsync = $this->getRsyncMock(
            array(
                'generateExcludesFile',
                'getProcess'
            )
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
        $rsync = new Rsync(array());

        $beamMock = $this->getBeamMock();

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
        $rsync = new Rsync(array());

        $beamMock = $this->getBeamMock();

        $beamMock->expects($this->once())
            ->method('getServer')
            ->will(
                $this->returnValue(
                    array(
                        'user' => 'user',
                        'host' => 'host',
                        'webroot' => 'webroot'
                    )
                )
            );

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
            array()
        );

        $beamMock = $this->getBeamMock();

        $beamMock->expects($this->once())
            ->method('getConfig')
            ->will(
                $this->returnValue(
                    array(
                        'test',
                        'test2'
                    )
                )
            );

        $beamMock->expects($this->once())
            ->method('hasPath')
            ->will(
                $this->returnValue(false)
            );

        $rsync = $this->getRsyncMock(
            array(
                'getExcludesPath'
            )
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
            array()
        );

        $beamMock = $this->getBeamMock();

        $beamMock->expects($this->once())
            ->method('getConfig')
            ->will(
                $this->returnValue(
                    array(
                        'test',
                        'test2'
                    )
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
            array(
                'getExcludesPath'
            )
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
            array(
                'getExcludesPath'
            )
        );

        $rsync->expects($this->atLeastOnce())
            ->method('getExcludesPath')
            ->will($this->returnValue('/test'));

        $beamMock = $this->getBeamMock();

        $beamMock->expects($this->atLeastOnce())
            ->method('hasPath')
            ->will(
                $this->returnValue(false)
            );

        $rsync->setBeam($beamMock);

        $this->assertEquals(
            'rsync /testfrom/ /testto -rlpgoD --itemize-changes --exclude-from="/test" --checksum --compress --delay-updates',
            $this->getAccessibleMethod('buildCommand')->invoke(
                $rsync,
                '/testfrom',
                '/testto'
            )
        );

        $this->assertEquals(
            'rsync /testfrom/ /testto -rlpgoD --itemize-changes --exclude-from="/test" --dry-run --checksum --compress --delay-updates',
            $this->getAccessibleMethod('buildCommand')->invoke(
                $rsync,
                '/testfrom',
                '/testto',
                true
            )
        );

        $rsync = $this->getRsyncMock(
            array(
                'getExcludesPath'
            ),
            array(
                'delete' => true
            )
        );

        $rsync->expects($this->atLeastOnce())
            ->method('getExcludesPath')
            ->will($this->returnValue('/test'));

        $rsync->setBeam($beamMock);

        $this->assertEquals(
            'rsync /testfrom/ /testto -rlpgoD --itemize-changes --exclude-from="/test" --checksum --delete --compress --delay-updates',
            $this->getAccessibleMethod('buildCommand')->invoke(
                $rsync,
                '/testfrom',
                '/testto'
            )
        );

    }
}
