<?php

namespace Omnidrive\Dropbox;

use League\Flysystem\ChecksumAlgoIsNotSupported;
use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\PathPrefixer;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToGenerateTemporaryUrl;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UrlGeneration\TemporaryUrlGenerator;
use Spatie\Dropbox\Client;
use Spatie\Dropbox\Exceptions\BadRequest;
use Spatie\FlysystemDropbox\DropboxAdapter as SpatieDropboxAdapter;

/**
 * spatie/flysystem-dropbox's adapter, where it parts from Flysystem's contract:
 *
 *   - deleting a file or a directory that is not there is no error (the
 *     adapter's is an exception, and for a directory not one of Flysystem's);
 *   - a file's visibility is "not kept" (an UnableToRetrieveMetadata), not an
 *     empty attribute that Flysystem's typed visibility() cannot return;
 *   - a checksum is the file's own (md5, sha1...: Flysystem reads the file and
 *     hashes it) - the adapter gave Dropbox's content_hash for sha256 and a hash
 *     of that hash for any other; "dropbox" asks for the content_hash itself;
 *   - a temporary address is Dropbox's temporary link (four hours), under the
 *     drive's prefix.
 *
 * Everything else is the adapter's.
 */
final class DropboxAdapter extends SpatieDropboxAdapter implements TemporaryUrlGenerator
{
    /** How long Dropbox's temporary link serves the file. */
    public const TEMPORARY_LINK_LIFETIME = 4 * 3600;

    private readonly PathPrefixer $paths;

    public function __construct(Client $client, string $prefix = '')
    {
        parent::__construct($client, $prefix);
        $this->paths = new PathPrefixer($prefix);
    }

    public function delete(string $path): void
    {
        try {
            parent::delete($path);
        } catch (UnableToDeleteFile $e) {
            if (!self::notFound($e->getPrevious())) {
                throw $e;
            }
        }
    }

    public function deleteDirectory(string $path): void
    {
        try {
            $this->getClient()->delete($this->location($path));
        } catch (BadRequest $e) {
            if (!self::notFound($e)) {
                throw UnableToDeleteDirectory::atLocation($path, $e->getMessage(), $e);
            }
        }
    }

    public function visibility(string $path): FileAttributes
    {
        throw UnableToRetrieveMetadata::visibility($path, 'Dropbox keeps no visibility per file.');
    }

    public function checksum(string $path, Config $config): string
    {
        if ('dropbox' !== $config->get('checksum_algo', 'md5')) {
            throw new ChecksumAlgoIsNotSupported();
        }

        return parent::checksum($path, $config->extend(['checksum_algo' => 'sha256']));
    }

    public function temporaryUrl(string $path, \DateTimeInterface $expiresAt, Config $config): string
    {
        if ($expiresAt->getTimestamp() > time() + self::TEMPORARY_LINK_LIFETIME) {
            throw new UnableToGenerateTemporaryUrl('Dropbox\'s temporary link serves the file four hours, not until '.$expiresAt->format(\DATE_ATOM), $path);
        }
        try {
            return $this->getClient()->getTemporaryLink($this->location($path));
        } catch (BadRequest $e) {
            throw new UnableToGenerateTemporaryUrl($e->getMessage(), $path, $e);
        }
    }

    private function location(string $path): string
    {
        return '/'.trim($this->paths->prefixPath($path), '/');
    }

    /** Dropbox's error_summary for a path that is not there: "path_lookup/not_found/..", "path/not_found/..". */
    private static function notFound(?\Throwable $e): bool
    {
        return $e instanceof BadRequest && str_contains($e->getMessage(), 'not_found');
    }
}
