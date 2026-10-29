<?php

namespace Omnidrive\Dropbox;

use League\Flysystem\FilesystemException;

/**
 * Dropbox did not give an access token for the refresh token: refused (revoked, malformed, the
 * app's key or secret wrong: {"error": "invalid_grant"...}), or its token endpoint not reached.
 * A Flysystem exception, so every drive operation turns it into a DriveException that names it.
 */
final class TokenRefusedException extends \RuntimeException implements FilesystemException
{
}
