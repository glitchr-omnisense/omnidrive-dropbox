# omnidrive/dropbox

A folder of Dropbox as an [omnidrive](https://github.com/glitchr-omnisense/omnidrive) drive, through
spatie/flysystem-dropbox (spatie/dropbox-api, Guzzle).

```sh
composer require omnisense/omnidrive omnidrive/dropbox
```

```php
use Omnidrive\Dropbox\DropboxDriveFactory;
use Omnidrive\Registry;

$drives = new Registry([new DropboxDriveFactory()], [
    'dropbox' => ['factory' => 'dropbox', 'options' => ['refresh_token' => getenv('DROPBOX_REFRESH_TOKEN'), 'app_key' => getenv('DROPBOX_APP_KEY'), 'app_secret' => getenv('DROPBOX_APP_SECRET'), 'prefix' => 'site']],
]);
```

| Option | Default | |
|---|---|---|
| `refresh_token`, `app_key`, `app_secret` | | the access token renewed on its own (`RefreshTokenProvider`): Dropbox's expire in four hours |
| `access_token` | | instead: used as it is, until it expires |
| `prefix` | | every path under it (an "app folder" app is already confined to its folder) |
| `team_member_id` | | a team's member the requests act as |
| `namespace_id` | | a team space's root |
| `chunk_size` | 150 MB | bytes per request of a large upload |
| `public_url` | | Dropbox serves no public address of its own |

`DropboxAdapter` is spatie's, where it parts from Flysystem's contract: a missing file or directory
deleted is no error; a visibility is "not kept" rather than an empty attribute; a checksum is the
file's own (md5 by default: Flysystem reads the file and hashes it - spatie's gave Dropbox's
`content_hash` for sha256 and a hash of that hash for any other), `checksum($path, 'dropbox')` is the
`content_hash`; a temporary address is Dropbox's temporary link under the drive's prefix, which
serves the file four hours - asked for longer, it is refused. `capabilities()`: not local, public
addresses with `public_url`, temporary addresses. Nothing is sent until the first operation.

## Verified, and not

- Verified (2026-10-24, omnidrive's harness, PHP 8.4): the contract's tests against
  `Tests/Fixtures/FakeDropboxApi` - Dropbox's HTTP API v2 as its documentation describes it, in
  memory, not responses recorded from Dropbox; the refresh token exchanged on the first request and
  again after a 401, every path under the prefix, the temporary link's four hours.
- Not verified: Dropbox itself - no account here. `DropboxLiveTest` runs the contract against an
  account with `OMNIDRIVE_DROPBOX_REFRESH_TOKEN` (and the app's key and secret) or
  `OMNIDRIVE_DROPBOX_ACCESS_TOKEN`. spatie's client still calls `files/delete` and
  `files/create_folder`, which Dropbox's documentation lists as deprecated for their `_v2`
  versions; uploads in sessions (above `chunk_size`); a team member or space.

## Responsibility

Unofficial; not affiliated with Dropbox or Spatie. Dropbox keeps its own terms (its API's, its branding guidelines); whoever runs this package complies with them, and answers for the access a temporary link gives. MIT licence.
