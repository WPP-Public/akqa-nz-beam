<?php

namespace Heyday\Beam\Config;

use Heyday\Beam\VcsProvider\GitLikeVcsProvider;
use PHPUnit\Framework\TestCase;

class ValueInterpolatorTest extends TestCase
{
    public function testProcessSkipsNonStringValues(): void
    {
        $vcs = $this->createMock(GitLikeVcsProvider::class);
        $vcs->method('getBranchForReference')->willReturn('main');
        $vcs->method('resolveReference')->willReturn('abc123');
        $vcs->method('getUserIdentity')->willReturn('Test User <test@example.com>');

        $interpolator = new ValueInterpolator($vcs, 'HEAD', [
            'target' => 'staging',
        ]);

        $config = [
            'servers' => [
                'staging' => [
                    'user' => 'ec2-user',
                    'host' => 'i-0abc123',
                    'webroot' => '/var/www/%%target%%',
                    'sshpass' => false,
                    'syncPermissions' => true,
                    'ssm' => [
                        'enabled' => true,
                        'region' => null,
                        'profile' => null,
                    ],
                ],
            ],
        ];

        $processed = $interpolator->process($config);

        $this->assertSame('/var/www/staging', $processed['servers']['staging']['webroot']);
        $this->assertNull($processed['servers']['staging']['ssm']['region']);
        $this->assertNull($processed['servers']['staging']['ssm']['profile']);
        $this->assertTrue($processed['servers']['staging']['ssm']['enabled']);
        $this->assertFalse($processed['servers']['staging']['sshpass']);
    }
}
