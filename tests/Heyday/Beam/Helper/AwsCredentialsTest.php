<?php

namespace Heyday\Beam\Helper;

use Heyday\Beam\Exception\InvalidArgumentException;
use org\bovigo\vfs\vfsStream;
use PHPUnit\Framework\TestCase;

class AwsCredentialsTest extends TestCase
{
    protected function setUp(): void
    {
        vfsStream::setup('root');
    }

    public function testWriteProfileCreatesFile(): void
    {
        $path = vfsStream::url('root/credentials');

        AwsCredentials::writeProfile(
            'deploy',
            'AKIATEST',
            'secret',
            'token',
            $path
        );

        $this->assertSame(
            "[deploy]\naws_access_key_id=AKIATEST\naws_secret_access_key=secret\naws_session_token=token\n",
            file_get_contents($path)
        );
    }

    public function testWriteProfileUpdatesExistingProfilePreservingOthers(): void
    {
        $path = vfsStream::url('root/credentials');
        file_put_contents(
            $path,
            "[other]\naws_access_key_id=OLD\naws_secret_access_key=oldsecret\n\n"
            . "[deploy]\naws_access_key_id=PREV\naws_secret_access_key=prevsecret\n"
        );

        AwsCredentials::writeProfile(
            'deploy',
            'AKIATEST',
            'secret',
            'token',
            $path
        );

        $parsed = AwsCredentials::parse($path);

        $this->assertSame('OLD', $parsed['other']['aws_access_key_id']);
        $this->assertSame('AKIATEST', $parsed['deploy']['aws_access_key_id']);
        $this->assertSame('secret', $parsed['deploy']['aws_secret_access_key']);
        $this->assertSame('token', $parsed['deploy']['aws_session_token']);
    }

    public function testWriteProfileRejectsEmptyValues(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AwsCredentials::writeProfile(
            'deploy',
            'AKIATEST',
            '',
            'token',
            vfsStream::url('root/credentials')
        );
    }
}
