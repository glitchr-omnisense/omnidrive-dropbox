<?php

namespace Omnidrive\Dropbox\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use Omnidrive\Drive;
use Omnidrive\Dropbox\DropboxDriveFactory;
use Omnidrive\Dropbox\Tests\Fixtures\FakeDropboxApi;
use Omnidrive\Exception\DriveException;
use Omnidrive\Exception\InvalidConfigException;
use Omnidrive\Testing\DriveContractTests;
use PHPUnit\Framework\TestCase;

/**
 * The contract against FakeDropboxApi - Dropbox's API as its documentation
 * describes it, not Dropbox itself (DropboxLiveTest is, with a token) - and the
 * factory's options.
 */
final class DropboxDriveTest extends TestCase
{
    use DriveContractTests;

    private ?FakeDropboxApi $api = null;

    protected function keepsVisibility(): bool
    {
        return false;
    }

    protected function drive(): Drive
    {
        return $this->factory()->create(['refresh_token' => 'the-refresh-token', 'app_key' => 'key', 'app_secret' => 'secret', 'prefix' => 'site'], 'dropbox');
    }

    private function factory(): DropboxDriveFactory
    {
        $this->api ??= new FakeDropboxApi();

        return new DropboxDriveFactory(new Client(['handler' => HandlerStack::create($this->api)]));
    }

    public function testTheAccessTokenIsRenewedFromTheRefreshTokenAndEveryPathIsUnderThePrefix(): void
    {
        $drive = $this->drive();
        $api = $this->api ?? throw new \LogicException('no API');
        self::assertSame(['local' => false, 'publicUrls' => false, 'temporaryUrls' => true], $drive->capabilities()->toArray());
        self::assertCount(0, $api->requests, 'nothing is sent when the drive is built');

        $drive->write('a/b.txt', 'hello');
        self::assertSame(['https://api.dropboxapi.com/oauth2/token', 'https://content.dropboxapi.com/2/files/upload'], array_column(\array_slice($api->requests, 0, 2), 'url'));
        self::assertSame('Bearer fake-token', $api->requests[1]['authorization'] ?? null);
        self::assertSame([['path' => 'a/b.txt', 'type' => 'file']], array_map(static fn ($e) => ['path' => $e->path, 'type' => $e->directory ? 'directory' : 'file'], [...$drive->list('a')]));

        // Dropbox expires the token: one 401, the token renewed, the request made again.
        $api->token = 'renewed-token';
        self::assertSame('hello', $drive->read('a/b.txt'));
        self::assertSame(hash('sha256', 'hello'), $drive->checksum('a/b.txt', 'dropbox'), 'Dropbox\'s own content_hash on demand');
        self::assertStringStartsWith('https://uc.dl.dropboxusercontent.com/', $drive->temporaryUrl('a/b.txt', new \DateTimeImmutable('+1 hour')));
        $drive->deleteDirectory('a');
    }

    /**
     * A refresh token Dropbox refuses (revoked, malformed: the token endpoint answers 400
     * {"error": "invalid_grant", ...}, Dropbox's OAuth guide) is the drive's failure, named: every
     * read was a FileNotFoundException with no reason, exists() false, and spatie's BadRequest
     * warned of a missing error_summary.
     */
    public function testARefreshTokenDropboxRefusesIsADriveExceptionNamingIt(): void
    {
        $drive = $this->factory()->create(['refresh_token' => 'a-revoked-token', 'app_key' => 'key', 'app_secret' => 'secret'], 'dropbox');

        foreach (['exists' => fn () => $drive->exists('a.txt'), 'read' => fn () => $drive->read('a.txt'), 'write' => fn () => $drive->write('a.txt', 'x')] as $operation => $call) {
            try {
                $call();
                self::fail("$operation went through");
            } catch (DriveException $e) {
                self::assertNotInstanceOf(\Omnidrive\Exception\FileNotFoundException::class, $e, $operation);
                self::assertStringContainsString('Dropbox refused the refresh token', $e->getMessage(), $operation);
                self::assertStringContainsString('invalid_grant', $e->getMessage(), $operation);
            }
        }
    }

    public function testATemporaryLinkServesFourHoursNotMore(): void
    {
        $drive = $this->drive();
        $drive->write('t.txt', 't');
        try {
            $drive->temporaryUrl('t.txt', new \DateTimeImmutable('+1 day'));
            self::fail('a link for a day');
        } catch (\Exception $e) {
            self::assertInstanceOf(DriveException::class, $e);
            self::assertSame('temporary_url', $e->operation);
        } finally {
            $drive->delete('t.txt');
        }
    }

    public function testATokenIsRequired(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('refresh_token (with app_key and app_secret) or access_token');
        (new DropboxDriveFactory())->create([]);
    }

    public function testARefreshTokenNeedsTheAppsKeyAndSecret(): void
    {
        $this->expectException(InvalidConfigException::class);
        (new DropboxDriveFactory())->create(['refresh_token' => 'x', 'app_key' => 'key']);
    }
}
