# omnidrive/dropbox

A folder of Dropbox as an [omniscient/omnidrive](https://github.com/glitchr-studio/omnidrive) drive,
through spatie/flysystem-dropbox (spatie/dropbox-api over Guzzle), with its own `DropboxAdapter`
where spatie's parts from Flysystem's contract and a `RefreshTokenProvider` that renews Dropbox's
short-lived access tokens.

| Operation | Dropbox API v2 (the endpoints spatie's client calls) |
|---|---|
| token | `POST https://api.dropboxapi.com/oauth2/token` (`grant_type=refresh_token`) |
| read, write | `https://content.dropboxapi.com/2/files/download`, `/files/upload` (sessions above `chunk_size`) |
| list, metadata, delete, copy, move, directories | `https://api.dropboxapi.com/2/files/list_folder`, `/files/get_metadata`, `/files/delete`, `/files/copy_v2`, `/files/move_v2`, `/files/create_folder` |
| temporary address | `https://api.dropboxapi.com/2/files/get_temporary_link` |

## Installation

```sh
composer require omniscient/omnidrive omnidrive/dropbox
```

Requires PHP 8.2, `omniscient/omnidrive` (1.x), `spatie/flysystem-dropbox` (^3.0) and
`guzzlehttp/guzzle` (^7.0).

## Credentials

A Dropbox app (Dropbox's App Console): its **app key** and **app secret**, and a **refresh token**
an account gave the app (OAuth with `token_access_type=offline`). Access tokens expire in four
hours since 2021; `RefreshTokenProvider` exchanges the refresh token for one on the first request,
again a minute before it expires, and when Dropbox answers 401. An `access_token` alone works until
it expires. An app with "App folder" access is confined to its folder already.

## Options

`new DropboxDriveFactory(?GuzzleHttp\ClientInterface $http = null)`; factory name `dropbox`; no
option is declared required, but one of the two credentials is.

| Option | Type | Default | Required | |
|---|---|---|---|---|
| `refresh_token` | string | none | this, or `access_token` | with `app_key` and `app_secret` |
| `app_key`, `app_secret` | string | none | with `refresh_token` | |
| `access_token` | string | none | this, or `refresh_token` | used as it is, until it expires |
| `prefix` | string | `''` | no | every path under it |
| `team_member_id` | string | none | no | a team's member the requests act as |
| `namespace_id` | string | none | no | a team space's root |
| `chunk_size` | int | 150 MB (`Spatie\Dropbox\Client::MAX_CHUNK_SIZE`) | no | bytes per request of a large upload |
| `public_url` | string or list | none | no | the prefix files are served from, or several (sharded) |
| `visibility` | `public`/`private` | ignored | no | a write's visibility when it does not say |
| `directory_visibility` | `public`/`private` | ignored | no | the same for directories |
| `retain_visibility` | bool | `true` | no | whether a copy keeps its source's visibility |

## Capabilities

| Capability | |
|---|---|
| `local` | no |
| `publicUrls` | with `public_url` (Dropbox serves no public address of its own) |
| `temporaryUrls` | yes: Dropbox's temporary link, four hours at most |
| visibility per file | no: "not kept" (a `DriveException`) |
| checksum | any algorithm `hash()` knows (the file downloaded and hashed); `dropbox`: Dropbox's `content_hash` |

## Behaviour

`DropboxAdapter` differs from spatie's: deleting a file or a directory that is not there is no
error; a visibility is "not kept" rather than an empty attribute; a checksum is the file's own (md5
by default) - spatie's gave Dropbox's `content_hash` for sha256 and a hash of that hash for any
other -, `checksum($path, 'dropbox')` is the `content_hash`; a temporary address is the temporary
link of the path under the prefix, and an `$expiresAt` more than four hours ahead is refused
(`TEMPORARY_LINK_LIFETIME`). Paths are case-insensitive on Dropbox. Nothing is sent when the drive
is built.

## Example

```php
<?php

require __DIR__.'/vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use Omnidrive\Dropbox\DropboxDriveFactory;
use Omnidrive\Dropbox\Tests\Fixtures\FakeDropboxApi;
use Omnidrive\Registry;

// The package's stand-in of Dropbox's API (its tests' fixture) as Guzzle's handler; new Client() against Dropbox.
$api = new FakeDropboxApi();
$http = new Client(['handler' => HandlerStack::create($api)]);
$drives = new Registry([new DropboxDriveFactory($http)], [
    'dropbox' => ['factory' => 'dropbox', 'options' => ['refresh_token' => 'the-refresh-token', 'app_key' => 'app-key', 'app_secret' => 'app-secret', 'prefix' => 'site']],
]);
$dropbox = $drives->get('dropbox');

$dropbox->write('exports/orders.csv', "id,total\n1,12.50\n");
echo json_encode($dropbox->capabilities()->toArray()), "\n";
echo $dropbox->read('exports/orders.csv');
echo $dropbox->checksum('exports/orders.csv'), "\n";
echo strlen($dropbox->temporaryUrl('exports/orders.csv', new DateTimeImmutable('+1 hour'))) > 0 ? "a temporary link\n" : '';
try {
    $dropbox->temporaryUrl('exports/orders.csv', new DateTimeImmutable('+1 day'));
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
$dropbox->delete('exports/never-there.csv');
echo implode("\n", array_unique(array_map(static fn ($r) => preg_replace('~\?.*~', '', $r['url']), $api->requests))), "\n";
```

```
{"local":false,"publicUrls":false,"temporaryUrls":true}
id,total
1,12.50
08c189cd313e1b94ebe8205506343984
a temporary link
Omnidrive\Exception\DriveException
https://api.dropboxapi.com/oauth2/token
https://content.dropboxapi.com/2/files/upload
https://content.dropboxapi.com/2/files/download
https://api.dropboxapi.com/2/files/get_temporary_link
https://api.dropboxapi.com/2/files/delete
```

(run on 2026-10-25 in omnidrive's harness against the package's own stand-in of Dropbox's API,
`Tests/Fixtures/FakeDropboxApi` - written from Dropbox's documentation, not Dropbox: no request
reached Dropbox. Against Dropbox, drop the `handler` and give the app's real tokens.)

## Errors

Dropbox's errors (spatie's `BadRequest`, its `error_summary`) are `DriveException`s; a path that
is not there (`path/not_found`) read is a `FileNotFoundException`; a temporary link asked for more
than four hours is a `DriveException` of operation `temporary_url`. A `refresh_token` without
`app_key` and `app_secret`, or no credential at all, is an `InvalidConfigException`.

> [!WARNING]
> Known defect: a refresh token Dropbox refuses (`invalid_grant`) makes every read a
> `FileNotFoundException` with no reason and `exists()` answer `false`, with PHP warnings from
> spatie's `BadRequest` (the token endpoint's body has no `error_summary`), where a refused
> credential is expected to be a `DriveException` naming it (seen in the harness on a recorded 400).

## Verified, and not

Verified (2026-10-24 and 2026-10-25, omnidrive's harness, PHP 8.4): the contract's tests against
`FakeDropboxApi`, Dropbox's HTTP API v2 as its documentation describes it, in memory; the refresh
token exchanged on the first request and again after a 401, every path under the prefix, the
temporary link's four hours. Not verified: Dropbox itself - no account here (`DropboxLiveTest`
runs the contract with `OMNIDRIVE_DROPBOX_REFRESH_TOKEN` and the app's key and secret, or
`OMNIDRIVE_DROPBOX_ACCESS_TOKEN`). spatie's client still calls `files/delete` and
`files/create_folder`, which Dropbox's documentation lists as deprecated for their `_v2`
versions; uploads in sessions; a team member or space.

## Responsibility

An independent, unofficial client, not affiliated with Dropbox or Spatie; Dropbox's terms apply to the account and the app. Whoever runs it is responsible for the data it stores and the
access it gives (a public or temporary address serves the file to whoever holds it); see the core's
Responsibility section ([omniscient/omnidrive](https://github.com/glitchr-studio/omnidrive#responsibility)).
