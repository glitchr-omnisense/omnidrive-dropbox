<?php

namespace Omnidrive\Dropbox;

use GuzzleHttp\ClientInterface;
use Omnidrive\Config;
use Omnidrive\Drive;
use Omnidrive\Exception\InvalidConfigException;
use Omnidrive\Flysystem\FlysystemDriveFactory;
use Spatie\Dropbox\Client;

/**
 * A folder of Dropbox, through spatie/flysystem-dropbox (spatie/dropbox-api,
 * Guzzle):
 *
 *   (new DropboxDriveFactory())->create(['refresh_token' => '...', 'app_key' => '...', 'app_secret' => '...', 'prefix' => 'site']);
 *   (new DropboxDriveFactory())->create(['access_token' => '...']);
 *
 * Options: refresh_token with app_key and app_secret (the access token renewed
 * on its own: what an app is given since Dropbox's tokens expire in four
 * hours), or access_token (as it is, until it expires); prefix (every path
 * under it: an app folder's app is already confined to its folder);
 * team_member_id (a team's member the requests act as), namespace_id (a team
 * space's root); chunk_size (bytes per request of a large upload, 150 MB at
 * most); public_url (Dropbox serves none of its own).
 *
 * temporaryUrl() is Dropbox's temporary link, which serves the file four hours:
 * asked for longer, it is refused. Dropbox keeps no visibility per file. Nothing
 * is sent until the first operation.
 */
final class DropboxDriveFactory extends FlysystemDriveFactory
{
    public function __construct(private readonly ?ClientInterface $http = null)
    {
    }

    protected function populate(Config $c): void
    {
        $c->defaults([
            'omnidrive.factory_name' => 'dropbox',
            'omnidrive.factory_title' => 'A folder of Dropbox',
            'prefix' => '',
            'chunk_size' => Client::MAX_CHUNK_SIZE,
        ]);
    }

    protected function build(Config $c, string $name): Drive
    {
        if (null !== $refresh = $c->string('refresh_token')) {
            if (null === $c->string('app_key') || null === $c->string('app_secret')) {
                throw new InvalidConfigException('The "dropbox" drive\'s refresh_token needs app_key and app_secret.');
            }
            $token = new RefreshTokenProvider($refresh, (string) $c->string('app_key'), (string) $c->string('app_secret'), $this->http);
        } elseif (null === $token = $c->string('access_token')) {
            throw new InvalidConfigException('The "dropbox" drive needs refresh_token (with app_key and app_secret) or access_token.');
        }

        $client = new Client($token, $this->http, $c->int('chunk_size', Client::MAX_CHUNK_SIZE), 0, $c->string('team_member_id'));
        if (null !== $namespace = $c->string('namespace_id')) {
            $client->setNamespaceId($namespace);
        }

        return $this->drive($name, new DropboxAdapter($client, trim((string) $c->string('prefix'), '/')), $c);
    }
}
