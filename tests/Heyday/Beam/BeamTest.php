<?php

namespace Heyday\Beam;

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
            'target' => 'live',
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
        $vcsProviderStub = $this->getMock('Heyday\Beam\VcsProvider\VcsProvider');
        $vcsProviderStub->expects($this->any())
            ->method('getCurrentBranch')
            ->will($this->returnValue($current));
        $vcsProviderStub->expects($this->any())
            ->method('getAvailableBranches')
            ->will($this->returnValue($available));
        $vcsProviderStub->expects($this->any())
            ->method('isValidRef')
            ->will($this->returnValue(true));
        $vcsProviderStub->expects($this->any())
            ->method('exists')
            ->will($this->returnValue($exists));

        return $vcsProviderStub;
    }

    protected function getDeploymentProviderStub()
    {
        $deploymentProviderStub = $this->getMock('Heyday\Beam\DeploymentProvider\DeploymentProvider');

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
        $this->markTestSkipped('Config is no longer validated in Beam::__constructor');
        new Beam(
            array(),
            array()
        );
    }
    /**
     * @expectedException \Symfony\Component\OptionsResolver\Exception\MissingOptionsException
     */
    public function testBeamConstructMissingOptionsException()
    {
        new Beam(
            $this->validConfig,
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
            $this->validConfig,
            $this->getCombinedOptions(
                array(
                    'direction' => 'fake'
                )
            )
        );
    }
    /**
     * @expectedExceptionMessage The option "target" has the value "fake", but is expected to be one of "live"
     * @expectedException \Symfony\Component\OptionsResolver\Exception\InvalidOptionsException
     */
    public function testBeamConstructInvalidOptionsRemoteException()
    {
        new Beam(
            $this->validConfig,
            $this->getCombinedOptions(
                array(
                    'target' => 'fake'
                )
            )
        );
    }
    /**
     * @expectedExceptionMessage Specified ref "test" doesn't match the locked branch "master"
     * @expectedException \Heyday\Beam\Exception\InvalidArgumentException
     */
    public function testBeamConstructInvalidLockedBranch()
    {
        new Beam(
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
            ),
            $this->getCombinedOptions(
                array(
                    'ref' => 'test'
                )
            )
        );
    }
    /**
     * @expectedExceptionMessage Working copy can't be used with a locked remote branch
     * @expectedException \Heyday\Beam\Exception\InvalidArgumentException
     */
    public function testBeamConstructWorkingCopyLockedRemote()
    {
        $vcsProvider = $this->getVcsProviderStub(
            true,
            array(
                'remotes/origin/master'
            )
        );
        $vcsProvider->expects($this->once())
            ->method('isRemote')
            ->with($this->equalTo('remotes/origin/master'))
            ->will($this->returnValue(true));

        new Beam(
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
            ),
            $this->getCombinedOptions(
                array(
                    'working-copy' => true,
                    'vcsprovider' => $vcsProvider
                )
            )
        );
    }
    /**
     * @expectedExceptionMessage You can't use beam without a vcs.
     * @expectedException \Heyday\Beam\Exception\InvalidArgumentException
     */
    public function testBeamConstructNoVcs()
    {
        new Beam(
            $this->validConfig,
            $this->getCombinedOptions(
                array(
                    'vcsprovider' => $this->getVcsProviderStub(false)
                )
            )
        );
    }
    /**
     * @expectedExceptionMessage The local path "vfs://root" is not writable
     * @expectedException \Heyday\Beam\Exception\InvalidArgumentException
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
            $this->validConfig,
            $this->validOptions
        );
    }

    public function testBeamConstruct()
    {
        $this->assertInstanceOf(
            __NAMESPACE__ . '\Beam',
            new Beam(
                $this->validConfig,
                $this->validOptions
            )
        );
    }

    public function testGetLocalPath()
    {
        $beam = new Beam(
            $this->validConfig,
            $this->validOptions
        );
        $this->assertEquals('/tmp/beam-57df3e8661206a673d2fef647599e814', $beam->getLocalPath());
        $beam = new Beam(
            $this->validConfig,
            $this->getCombinedOptions(
                array(
                    'path' => 'extra'
                )
            )
        );
        $this->assertEquals('/tmp/beam-57df3e8661206a673d2fef647599e814', $beam->getLocalPath());
        $beam = new Beam(
            $this->validConfig,
            $this->getCombinedOptions(
                array(
                    'working-copy' => true
                )
            )
        );
        $this->assertEquals('vfs://root/test', $beam->getLocalPath());
        $beam = new Beam(
            $this->validConfig,
            $this->getCombinedOptions(
                array(
                    'working-copy' => true,
                    'path' => 'test/'
                )
            )
        );
        $this->assertEquals('vfs://root/test', $beam->getLocalPath());
    }

    public function testIsUp()
    {
        $beam = new Beam(
            $this->validConfig,
            $this->validOptions
        );
        $this->assertTrue($beam->isUp());
    }

    public function testIsDown()
    {
        $beam = new Beam(
            $this->validConfig,
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
            $this->validConfig,
            $this->getCombinedOptions(
                array(
                    'working-copy' => true
                )
            )
        );
        $this->assertTrue($beam->isWorkingCopy());
    }

    public function testIsServerLocked()
    {
        $beam = new Beam(
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
            ),
            $this->validOptions
        );
        $this->assertTrue($beam->isServerLocked());
    }
    public function testIsServerLockedRemote()
    {
        $vcsProvider = $this->getVcsProviderStub(
            true,
            array(
                'remotes/origin/master'
            )
        );
        $vcsProvider->expects($this->once())
            ->method('isRemote')
            ->with($this->equalTo('remotes/origin/master'))
            ->will($this->returnValue(true));

        $beam = new Beam(
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
            ),
            $this->getCombinedOptions(
                array(
                    'vcsprovider' => $vcsProvider
                )
            )
        );
        $this->assertTrue($beam->isTargetLockedRemote());
    }
    public function testIsBranchRemote()
    {
        $vcsProvider = $this->getVcsProviderStub(true, array('remotes/origin/master'));
        $vcsProvider->expects($this->once())
            ->method('isRemote')
            ->with($this->equalTo('remotes/origin/master'))
            ->will($this->returnValue(true));
        $beam = new Beam(
            array(
                'servers' => array(
                    'live' => array(
                        'user' => 'testuser',
                        'host' => 'testhost',
                        'webroot' => '/test/webroot'
                    )
                ),
                'exclude' => array()
            ),
            $this->getCombinedOptions(
                array(
                    'ref' => 'remotes/origin/master',
                    'vcsprovider' => $vcsProvider
                )
            )
        );
        $this->assertTrue($beam->isBranchRemote());
    }
    public function testHasPath()
    {
        $beam = new Beam(
            $this->validConfig,
            $this->validOptions,
            $this->getVcsProviderStub()
        );
        $this->assertFalse($beam->hasPath());
        $beam = new Beam(
            $this->validConfig,
            $this->getCombinedOptions(
                array(
                    'path' => array(
                        'test'
                    )
                )
            )
        );
        $this->assertTrue($beam->hasPath());
    }
    public function testMatchTag()
    {
        $beam = new Beam(
            $this->validConfig,
            $this->validOptions
        );

        $class = new \ReflectionClass($beam);
        $matchTag = $class->getMethod('matchTag');
        $matchTag->setAccessible(true);

        $tests = array(
            'kitten' => array(
                'kitten' => true,
                'kitties' => false,
                'kittens-deploy' => false,
                'puppies' => false
            ),
            'kitten*' => array(
                'kitten' => true,
                'kitties' => false,
                'kittens-deploy' => true,
                'puppies' => false
            ),
            'kit*' => array(
                'kitten' => true,
                'kitties' => true,
                'kittens-deploy' => true,
                'puppies' => false
            ),
            '*deploy' => array(
                'kitten' => false,
                'kitties' => false,
                'kittens-deploy' => true,
                'deploy-velociraptors' => false,
                'puppies-deploy' => true
            )
        );

        foreach ($tests as $input => $asserts) {
            $beam->setOption('command-tags', array($input));

            foreach ($asserts as $tag => $expected) {
                $this->assertEquals($expected, $matchTag->invoke($beam, $tag), "Input value '$input' incorrectly matched tag $tag");
            }
        }
    }
}
