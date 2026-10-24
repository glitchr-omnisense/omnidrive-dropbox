<?php

namespace Omnidrive\Dropbox\Tests;

use Omnidrive\Drive;
use Omnidrive\Dropbox\DropboxDriveFactory;
use Omnidrive\Testing\DriveContractTests;
use PHPUnit\Framework\TestCase;

/**
 * The contract against Dropbox itself, in a folder of an account of one's own:
 * OMNIDRIVE_DROPBOX_REFRESH_TOKEN with OMNIDRIVE_DROPBOX_APP_KEY and
 * OMNIDRIVE_DROPBOX_APP_SECRET, or OMNIDRIVE_DROPBOX_ACCESS_TOKEN; the folder
 * OMNIDRIVE_DROPBOX_PREFIX (omnidrive-live). Skipped when no token is given.
 */
final class DropboxLiveTest extends TestCase
{
    use DriveContractTests;

    protected function keepsVisibility(): bool
    {
        return false;
    }

    protected function drive(): Drive
    {
        $env = static fn (string $name): ?string => false === getenv($name) || '' === getenv($name) ? null : (string) getenv($name);
        if (null === $env('OMNIDRIVE_DROPBOX_REFRESH_TOKEN') && null === $env('OMNIDRIVE_DROPBOX_ACCESS_TOKEN')) {
            self::markTestSkipped('No Dropbox token given (OMNIDRIVE_DROPBOX_REFRESH_TOKEN or OMNIDRIVE_DROPBOX_ACCESS_TOKEN).');
        }

        return (new DropboxDriveFactory())->create([
            'refresh_token' => $env('OMNIDRIVE_DROPBOX_REFRESH_TOKEN'),
            'app_key' => $env('OMNIDRIVE_DROPBOX_APP_KEY'),
            'app_secret' => $env('OMNIDRIVE_DROPBOX_APP_SECRET'),
            'access_token' => $env('OMNIDRIVE_DROPBOX_ACCESS_TOKEN'),
            'prefix' => $env('OMNIDRIVE_DROPBOX_PREFIX') ?? 'omnidrive-live',
        ], 'dropbox');
    }
}
