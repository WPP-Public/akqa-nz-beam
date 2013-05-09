<?php

namespace Heyday\Component\Beam;

use org\bovigo\vfs\vfsStream;

class BeamTest extends \PHPUnit_Framework_TestCase
{
    protected $validConfig;
    protected $validOptions;

    public function setUp()
    {
        vfsStream::setup(
            'root',
            0777,
            array(
                'test' => array()
            )
        );

        $this->validConfig = array(
            'servers' => array(
                'live' => array(
                    'user' => 'testuser',
                    'host' => 'testhost',
                    'webroot' => '/test/webroot'
                )
            ),
            'exclude' => array()
        );
        $this->validOptions = array(
            'direction' => 'up',
            'remote' => 'live',
            'srcdir' => vfsStream::url('root/test'),
            'vcsprovider' => $this->getVcsProviderStub(),
            'deploymentprovider' => $this->getDeploymentProviderStub()
        );
    }
    /**
     * @param  bool                                     $exists
     * @param  array                                    $available
     * @param  string                                   $current
     * @return \PHPUnit_Framework_MockObject_MockObject
     */
    protected function getVcsProviderStub($exists = true, $available = array('master'), $current = 'master')
    {
        $vcsProviderStub = $this->getMock('Heyday\Component\Beam\Vcs\VcsProvider');
        $vcsProviderStub->expects($this->any())
            ->method('getCurrentBranch')
            ->will($this->returnValue($current));
        $vcsProviderStub->expects($this->any())
            ->method('getAvailableBranches')
            ->will($this->returnValue($available));
        $vcsProviderStub->expects($this->any())
            ->method('exists')
            ->will($this->returnValue($exists));

        return $vcsProviderStub;
    }

    protected function getDeploymentProviderStub()
    {
        $deploymentProviderStub = $this->getMock('Heyday\Component\Beam\Deployment\DeploymentProvider');

        return $deploymentProviderStub;
    }

    protected function getCombinedOptions($options)
    {
        return array_merge(
            $this->validOptions,
            $options
        );
    }
    /**
     * @expectedExceptionMessage The child node "servers" at path "beam" must be configured.
     * @expectedException \Symfony\Component\Config\Definition\Exception\InvalidConfigurationException
     */
    public function testBeamConstructConfigException()
    {
        new Beam(
            array(
                array()
            ),
            array()
        );
    }
    /**
     * @expectedException \Symfony\Component\OptionsResolver\Exception\MissingOptionsException
     */
    public function testBeamConstructMissingOptionsException()
    {
        new Beam(
            array(
                $this->validConfig
            ),
            array()
        );
    }
    /**
     * @expectedExceptionMessage The option "direction" has the value "fake", but is expected to be one of "up", "down"
     * @expectedException \Symfony\Component\OptionsResolver\Exception\InvalidOptionsException
     */
    public function testBeamConstructInvalidOptionsDirectionException()
    {
        new Beam(
            array(
                $this->validConfig
            ),
            $this->getCombinedOptions(
                array(
                    'direction' => 'fake'
                )
            )
        );
    }
    /**
     * @expectedExceptionMessage The option "remote" has the value "fake", but is expected to be one of "live"
     * @expectedException \Symfony\Component\OptionsResolver\Exception\InvalidOptionsException
     */
    public function testBeamConstructInvalidOptionsRemoteException()
    {
        new Beam(
            array(
                $this->validConfig
            ),
            $this->getCombinedOptions(
                array(
                    'remote' => 'fake'
                )
            )
        );
    }
    /**
     * @expectedExceptionMessage Invalid branch "test" valid options are: 'test1', 'test2'
     * @expectedException \InvalidArgumentException
     */
    public function testBeamConstructInvalidBranch()
    {
        new Beam(
            array(
                $this->validConfig
            ),
            $this->getCombinedOptions(
                array(
                    'branch' => 'test',
                    'vcsprovider' => $this->getVcsProviderStub(
                        true,
                        array(
                            'test1',
                            'test2'
                        )
                    )
                )
            )
        );
    }
    /**
     * @expectedExceptionMessage Specified branch "test" doesn't match the locked branch "master"
     * @expectedException \InvalidArgumentException
     */
    public function testBeamConstructInvalidLockedBranch()
    {
        new Beam(
            array(
                array(
                    'servers' => array(
                        'live' => array(
                            'user' => 'testuser',
                            'host' => 'testhost',
                            'webroot' => '/test/webroot',
                            'branch' => 'master'
                        )
                    ),
                    'exclude' => array()
                )
            ),
            $this->getCombinedOptions(
                array(
                    'branch' => 'test'
                )
            )
        );
    }
    /**
     * @expectedExceptionMessage Working copy can't be used with a locked remote branch
     * @expectedException \InvalidArgumentException
     */
    public function testBeamConstructWorkingCopyLockedRemote()
    {
        new Beam(
            array(
                array(
                    'servers' => array(
                        'live' => array(
                            'user' => 'testuser',
                            'host' => 'testhost',
                            'webroot' => '/test/webroot',
                            'branch' => 'remotes/origin/master'
                        )
                    ),
                    'exclude' => array()
                )
            ),
            $this->getCombinedOptions(
                array(
                    'workingcopy' => true
                )
            )
        );
    }
    /**
     * @expectedExceptionMessage You can't use beam without a vcs.
     * @expectedException \InvalidArgumentException
     */
    public function testBeamConstructNoVcs()
    {
        new Beam(
            array(
                $this->validConfig
            ),
            $this->getCombinedOptions(
                array(
                    'vcsprovider' => $this->getVcsProviderStub(false)
                )
            )
        );
    }
    /**
     * @expectedExceptionMessage The local path "vfs://root" is not writable
     * @expectedException \InvalidArgumentException
     */
    public function testBeamConstructLocalPathNotWritable()
    {
        vfsStream::setup(
            'root',
            0000,
            array(
                'test' => array()
            )
        );
        new Beam(
            array(
                $this->validConfig
            ),
            $this->validOptions
        );
    }

    public function testBeamConstruct()
    {
        $this->assertInstanceOf(
            __NAMESPACE__ . '\Beam',
            new Beam(
                array(
                    $this->validConfig
                ),
                $this->validOptions
            )
        );
    }

    public function testGetRemotePath()
    {
        $this->markTestIncomplete();
//        $beam = new Beam(
//            array(
//                $this->validConfig
//            ),
//            $this->validOptions
//        );
//        $this->assertEquals('testuser@testhost:/test/webroot', $beam->getRemotePath());
//        $beam = new Beam(
//            array(
//                $this->validConfig
//            ),
//            $this->getCombinedOptions(
//                array(
//                    'path' => 'testing/'
//                )
//            )
//        );
//        $this->assertEquals('testuser@testhost:/test/webroot/testing', $beam->getRemotePath());
//        $beam = new Beam(
//            array(
//                array(
//                    'servers' => array(
//                        'live' => array(
//                            'user' => 'testuser',
//                            'host' => 'testhost',
//                            'webroot' => '/test/webroot/'
//                        )
//                    ),
//                    'exclude' => array()
//                )
//            ),
//            $this->validOptions
//        );
//        $this->assertEquals('testuser@testhost:/test/webroot', $beam->getRemotePath());
    }

    public function testGetLocalPath()
    {
        $beam = new Beam(
            array(
                $this->validConfig
            ),
            $this->validOptions
        );
        $this->assertEquals('vfs://root/_temp', $beam->getLocalPath());
        $beam = new Beam(
            array(
                $this->validConfig
            ),
            $this->getCombinedOptions(
                array(
                    'path' => 'extra'
                )
            )
        );
        $this->assertEquals('vfs://root/_temp', $beam->getLocalPath());
        $beam = new Beam(
            array(
                $this->validConfig
            ),
            $this->getCombinedOptions(
                array(
                    'exportdir' => 'testing'
                )
            )
        );
        $this->assertEquals('vfs://root/testing', $beam->getLocalPath());
        $beam = new Beam(
            array(
                $this->validConfig
            ),
            $this->getCombinedOptions(
                array(
                    'exportdir' => 'testing',
                    'path' => 'extra/'
                )
            )
        );
        $this->assertEquals('vfs://root/testing', $beam->getLocalPath());
        $beam = new Beam(
            array(
                $this->validConfig
            ),
            $this->getCombinedOptions(
                array(
                    'workingcopy' => true
                )
            )
        );
        $this->assertEquals('vfs://root/test', $beam->getLocalPath());
        $beam = new Beam(
            array(
                $this->validConfig
            ),
            $this->getCombinedOptions(
                array(
                    'workingcopy' => true,
                    'path' => 'test/'
                )
            )
        );
        $this->assertEquals('vfs://root/test', $beam->getLocalPath());
    }

    public function testIsUp()
    {
        $beam = new Beam(
            array(
                $this->validConfig
            ),
            $this->validOptions
        );
        $this->assertTrue($beam->isUp());
    }

    public function testIsDown()
    {
        $beam = new Beam(
            array(
                $this->validConfig
            ),
            $this->getCombinedOptions(
                array(
                    'direction' => 'down'
                )
            )
        );
        $this->assertTrue($beam->isDown());
    }

    public function testIsWorkingCopy()
    {
        $beam = new Beam(
            array(
                $this->validConfig
            ),
            $this->getCombinedOptions(
                array(
                    'workingcopy' => true
                )
            )
        );
        $this->assertTrue($beam->isWorkingCopy());
    }

    public function testIsServerLocked()
    {
        $beam = new Beam(
            array(
                array(
                    'servers' => array(
                        'live' => array(
                            'user' => 'testuser',
                            'host' => 'testhost',
                            'webroot' => '/test/webroot',
                            'branch' => 'master'
                        )
                    ),
                    'exclude' => array()
                )
            ),
            $this->validOptions
        );
        $this->assertTrue($beam->isServerLocked());
    }
    public function testIsServerLockedRemote()
    {
        $beam = new Beam(
            array(
                array(
                    'servers' => array(
                        'live' => array(
                            'user' => 'testuser',
                            'host' => 'testhost',
                            'webroot' => '/test/webroot',
                            'branch' => 'remotes/origin/master'
                        )
                    ),
                    'exclude' => array()
                )
            ),
            $this->getCombinedOptions(
                array(
                    'vcsprovider' => $this->getVcsProviderStub(
                        true,
                        array(
                            'remotes/origin/master'
                        )
                    )
                )
            )
        );
        $this->assertTrue($beam->isServerLockedRemote());
    }
    public function testIsBranchRemote()
    {
        $beam = new Beam(
            array(
                array(
                    'servers' => array(
                        'live' => array(
                            'user' => 'testuser',
                            'host' => 'testhost',
                            'webroot' => '/test/webroot'
                        )
                    ),
                    'exclude' => array()
                )
            ),
            $this->getCombinedOptions(
                array(
                    'branch' => 'remotes/origin/master',
                    'vcsprovider' => $this->getVcsProviderStub(true, array('remotes/origin/master'))
                )
            )
        );
        $this->assertTrue($beam->isBranchRemote());
    }
    public function testHasPath()
    {
        $beam = new Beam(
            array(
                $this->validConfig
            ),
            $this->validOptions,
            $this->getVcsProviderStub()
        );
        $this->assertFalse($beam->hasPath());
        $beam = new Beam(
            array(
                $this->validConfig
            ),
            $this->getCombinedOptions(
                array(
                    'path' => 'test'
                )
            )
        );
        $this->assertTrue($beam->hasPath());
    }
}
