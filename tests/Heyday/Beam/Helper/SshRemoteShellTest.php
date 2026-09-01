<?php

namespace Heyday\Beam\Helper;

use Heyday\Beam\Exception\InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class SshRemoteShellTest extends TestCase
{
    public function testBuildDefaultsToSsh(): void
    {
        $this->assertSame('ssh', SshRemoteShell::build([
            'sshpass' => false,
            'ssm' => ['enabled' => false],
        ]));
    }

    public function testBuildUsesSshPass(): void
    {
        $this->assertSame('sshpass -e ssh', SshRemoteShell::build([
            'sshpass' => true,
            'ssm' => ['enabled' => false],
        ]));
    }

    public function testBuildUsesSsmProxyCommand(): void
    {
        $shell = SshRemoteShell::build([
            'sshpass' => false,
            'ssm' => [
                'enabled' => true,
                'region' => 'ap-southeast-2',
                'profile' => 'deploy',
            ],
        ]);

        $expectedProxy = "aws ssm start-session --target %h --document-name AWS-StartSSHSession --parameters portNumber=%p --region ap-southeast-2 --profile deploy";

        $this->assertSame(
            'ssh -o ProxyCommand=' . escapeshellarg($expectedProxy),
            $shell
        );
    }

    public function testBuildUsesIdentityFile(): void
    {
        $key = tempnam(sys_get_temp_dir(), 'beam-key');

        try {
            $shell = SshRemoteShell::build([
                'sshpass' => false,
                'ssm' => ['enabled' => false],
                'identityFile' => $key,
            ]);

            $this->assertSame(
                'ssh -i ' . escapeshellarg($key) . ' -o IdentitiesOnly=yes',
                $shell
            );
        } finally {
            unlink($key);
        }
    }

    public function testBuildExpandsHomeInIdentityFile(): void
    {
        $home = sys_get_temp_dir() . '/beam-home-' . uniqid();
        mkdir($home . '/.ssh', 0700, true);
        file_put_contents($home . '/.ssh/id_test', 'key');

        $originalHome = getenv('HOME');
        putenv('HOME=' . $home);

        try {
            $shell = SshRemoteShell::build([
                'sshpass' => false,
                'ssm' => ['enabled' => false],
                'identityFile' => '~/.ssh/id_test',
            ]);

            $this->assertStringContainsString(
                escapeshellarg($home . '/.ssh/id_test'),
                $shell
            );
        } finally {
            putenv($originalHome === false ? 'HOME' : 'HOME=' . $originalHome);
            unlink($home . '/.ssh/id_test');
            rmdir($home . '/.ssh');
            rmdir($home);
        }
    }

    public function testBuildRejectsMissingIdentityFile(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SshRemoteShell::build([
            'sshpass' => false,
            'ssm' => ['enabled' => false],
            'identityFile' => '/nonexistent/beam/key',
        ]);
    }

    public function testBuildCombinesSsmProxyCommandWithIdentityFile(): void
    {
        $key = tempnam(sys_get_temp_dir(), 'beam-key');

        try {
            $shell = SshRemoteShell::build([
                'sshpass' => false,
                'ssm' => [
                    'enabled' => true,
                    'region' => 'ap-southeast-2',
                    'profile' => 'deploy',
                ],
                'identityFile' => $key,
            ]);

            $expectedProxy = "aws ssm start-session --target %h --document-name AWS-StartSSHSession --parameters portNumber=%p --region ap-southeast-2 --profile deploy";

            $this->assertSame(
                'ssh -o ProxyCommand=' . escapeshellarg($expectedProxy)
                    . ' -i ' . escapeshellarg($key) . ' -o IdentitiesOnly=yes',
                $shell
            );
        } finally {
            unlink($key);
        }
    }

    public function testBuildAppendsSshOptions(): void
    {
        $shell = SshRemoteShell::build([
            'sshpass' => false,
            'ssm' => ['enabled' => false],
            'sshOptions' => ['StrictHostKeyChecking=accept-new', 'ServerAliveInterval=30'],
        ]);

        $this->assertSame(
            'ssh -o ' . escapeshellarg('StrictHostKeyChecking=accept-new')
                . ' -o ' . escapeshellarg('ServerAliveInterval=30'),
            $shell
        );
    }

    public function testBuildRejectsMalformedSshOptions(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SshRemoteShell::build([
            'sshpass' => false,
            'ssm' => ['enabled' => false],
            // Missing the "Keyword=value" form, so it would be read as a hostname by ssh
            'sshOptions' => ['-o StrictHostKeyChecking no'],
        ]);
    }

    public function testBuildRejectsSsmWithSshPass(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SshRemoteShell::build([
            'sshpass' => true,
            'ssm' => ['enabled' => true],
        ]);
    }

    public function testBuildSsmProxyCommandRejectsUnsafeTokens(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SshRemoteShell::buildSsmProxyCommand([
            'region' => 'ap-southeast-2;rm -rf /',
        ]);
    }

    public function testIsEnabled(): void
    {
        $this->assertTrue(SshRemoteShell::isEnabled([
            'ssm' => ['enabled' => true],
        ]));
        $this->assertFalse(SshRemoteShell::isEnabled([
            'ssm' => ['enabled' => false],
        ]));
        $this->assertFalse(SshRemoteShell::isEnabled([]));
    }

    public function testProcessSsmBooleanConfig(): void
    {
        $processor = new \Symfony\Component\Config\Definition\Processor();
        $processed = $processor->processConfiguration(
            new \Heyday\Beam\Config\BeamConfiguration(),
            [
                [
                    'servers' => [
                        'live' => [
                            'user' => 'ec2-user',
                            'host' => 'i-0abc123def456',
                            'webroot' => '/var/www/html',
                            'ssm' => true,
                        ],
                    ],
                ],
            ]
        );

        $this->assertTrue($processed['servers']['live']['ssm']['enabled']);
        $this->assertNull($processed['servers']['live']['ssm']['region']);
        $this->assertNull($processed['servers']['live']['ssm']['profile']);
        $this->assertNull($processed['servers']['live']['ssm']['portalUrl']);
    }

    public function testProcessSsmObjectConfig(): void
    {
        $processor = new \Symfony\Component\Config\Definition\Processor();
        $processed = $processor->processConfiguration(
            new \Heyday\Beam\Config\BeamConfiguration(),
            [
                [
                    'servers' => [
                        'live' => [
                            'user' => 'ec2-user',
                            'host' => 'i-0abc123def456',
                            'webroot' => '/var/www/html',
                            'ssm' => [
                                'region' => 'ap-southeast-2',
                                'profile' => 'deploy',
                            ],
                        ],
                    ],
                ],
            ]
        );

        $this->assertEquals(
            [
                'enabled' => true,
                'region' => 'ap-southeast-2',
                'profile' => 'deploy',
                'portalUrl' => null,
            ],
            $processed['servers']['live']['ssm']
        );
    }
}
