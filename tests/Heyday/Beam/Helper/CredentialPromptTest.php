<?php

namespace Heyday\Beam\Helper;

use Heyday\Beam\Exception\InvalidArgumentException;
use org\bovigo\vfs\vfsStream;
use PHPUnit\Framework\TestCase;

class CredentialPromptTest extends TestCase
{
    public function testParseCredentialsFile(): void
    {
        vfsStream::setup('root');
        $path = vfsStream::url('root/credentials');
        file_put_contents($path, <<<'TXT'
# comment
AWS_ACCESS_KEY_ID=AKIAEXAMPLE
AWS_SECRET_ACCESS_KEY=secret/with/slashes
AWS_SESSION_TOKEN=IQoJb3JpZ2luX2VjEOz//////////wEaDmFwL
TXT);

        $this->assertSame(
            [
                'accessKeyId' => 'AKIAEXAMPLE',
                'secretAccessKey' => 'secret/with/slashes',
                'sessionToken' => 'IQoJb3JpZ2luX2VjEOz//////////wEaDmFwL',
            ],
            CredentialPrompt::parseCredentialsFile($path)
        );
    }

    public function testParseCredentialsFileMissingValueThrows(): void
    {
        vfsStream::setup('root');
        $path = vfsStream::url('root/credentials');
        file_put_contents($path, "AWS_ACCESS_KEY_ID=AKIAEXAMPLE\nAWS_SECRET_ACCESS_KEY=secret\nAWS_SESSION_TOKEN=\n");

        $this->expectException(InvalidArgumentException::class);
        CredentialPrompt::parseCredentialsFile($path);
    }

    public function testResolveEditorPrefersBeamEditor(): void
    {
        putenv('BEAM_EDITOR=test-editor');
        putenv('EDITOR=other');

        try {
            $this->assertSame('test-editor', CredentialPrompt::resolveEditor(null));
        } finally {
            putenv('BEAM_EDITOR');
        }
    }
}
