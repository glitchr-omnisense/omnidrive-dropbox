<?php

namespace Omnidrive\Dropbox\Tests\Fixtures;

use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

/**
 * Dropbox's HTTP API v2 as its documentation describes it
 * (https://www.dropbox.com/developers/documentation/http/documentation), in
 * memory, as a Guzzle handler: the endpoints spatie/dropbox-api calls for a
 * drive, their JSON, their 409 errors ("path/not_found/..") - case-insensitive
 * paths, folders made by a file's upload. A stand-in written from the docs, not
 * responses recorded from Dropbox.
 */
final class FakeDropboxApi
{
    /** @var array<string, array{display: string, bytes: string, modified: int}> lower path => file */
    private array $files = [];
    /** @var array<string, string> lower path => display path */
    private array $folders = [];
    /** @var list<array{url: string, authorization: string}> */
    public array $requests = [];
    public string $token = 'fake-token';

    /** @param array<mixed> $options */
    public function __invoke(RequestInterface $request, array $options): PromiseInterface
    {
        $this->requests[] = ['url' => (string) $request->getUri(), 'authorization' => $request->getHeaderLine('Authorization')];
        $url = (string) $request->getUri();
        if (str_ends_with($url, '/oauth2/token')) {
            parse_str((string) $request->getBody(), $form);

            return Create::promiseFor('refresh_token' === ($form['grant_type'] ?? null) && 'the-refresh-token' === ($form['refresh_token'] ?? null)
                ? self::json(['access_token' => $this->token, 'token_type' => 'bearer', 'expires_in' => 14400])
                : self::json(['error' => 'invalid_grant'], 400));
        }
        if ('Bearer '.$this->token !== $request->getHeaderLine('Authorization')) {
            return Create::promiseFor(self::json(['error_summary' => 'expired_access_token/', 'error' => ['.tag' => 'expired_access_token']], 401));
        }
        $endpoint = (string) preg_replace('~^https://(?:api|content)\.dropboxapi\.com/2/~', '', $url);
        $args = $request->hasHeader('Dropbox-API-Arg')
            ? json_decode($request->getHeaderLine('Dropbox-API-Arg'), true)
            : json_decode((string) $request->getBody(), true);
        /** @var array<string, mixed> $args */
        $args = \is_array($args) ? $args : [];

        return Create::promiseFor($this->answer($endpoint, $args, $request));
    }

    /** @param array<string, mixed> $a */
    private function answer(string $endpoint, array $a, RequestInterface $request): Response
    {
        $path = self::text($a, 'path');
        $key = strtolower($path);

        switch ($endpoint) {
            case 'files/upload':
                $this->files[$key] = ['display' => $path, 'bytes' => (string) $request->getBody(), 'modified' => time()];
                $this->parents($path);

                return self::json($this->fileMeta($key));
            case 'files/download':
                return isset($this->files[$key])
                    ? new Response(200, ['Dropbox-API-Result' => (string) json_encode($this->fileMeta($key))], $this->files[$key]['bytes'])
                    : self::notFound('path');
            case 'files/get_metadata':
                $meta = $this->meta($key);

                return null !== $meta ? self::json($meta) : self::notFound('path');
            case 'files/list_folder':
                if ('' !== $key && !isset($this->folders[$key])) {
                    return self::notFound('path');
                }
                $entries = [];
                if ($a['recursive'] && '' !== $key) {
                    $entries[] = $this->meta($key);
                }
                foreach ([...array_keys($this->folders), ...array_keys($this->files)] as $p) {
                    $p = (string) $p;
                    if (str_starts_with($p, $key.'/') && ($a['recursive'] || !str_contains(substr($p, \strlen($key) + 1), '/'))) {
                        $entries[] = $this->meta($p);
                    }
                }

                return self::json(['entries' => $entries, 'cursor' => 'c', 'has_more' => false]);
            case 'files/delete':
                $meta = $this->meta($key);
                if (null === $meta) {
                    return self::notFound('path_lookup');
                }
                foreach ([&$this->files, &$this->folders] as &$set) {
                    foreach (array_keys($set) as $p) {
                        if ($p === $key || str_starts_with((string) $p, $key.'/')) {
                            unset($set[$p]);
                        }
                    }
                }

                return self::json($meta);
            case 'files/create_folder':
                if ($this->meta($key)) {
                    return self::json(['error_summary' => 'path/conflict/folder/..', 'error' => ['.tag' => 'path', 'path' => ['.tag' => 'conflict']]], 409);
                }
                $this->folders[$key] = $path;
                $this->parents($path);

                return self::json($this->meta($key) ?? []);
            case 'files/copy_v2':
            case 'files/move_v2':
                $from = strtolower(self::text($a, 'from_path'));
                if (!isset($this->files[$from])) {
                    return self::notFound('from_lookup');
                }
                $to = self::text($a, 'to_path');
                $this->files[strtolower($to)] = ['display' => $to] + $this->files[$from];
                $this->parents($to);
                if ('files/move_v2' === $endpoint) {
                    unset($this->files[$from]);
                }

                return self::json(['metadata' => $this->fileMeta(strtolower($to))]);
            case 'files/get_temporary_link':
                return isset($this->files[$key])
                    ? self::json(['metadata' => $this->fileMeta($key), 'link' => 'https://uc.dl.dropboxusercontent.com/cd/0/get/'.rawurlencode(basename($path))])
                    : self::notFound('path');
        }

        return self::json(['error_summary' => 'unknown endpoint '.$endpoint], 400);
    }

    /** @param array<string, mixed> $a */
    private static function text(array $a, string $key): string
    {
        return \is_string($a[$key] ?? null) ? $a[$key] : '';
    }

    private function parents(string $path): void
    {
        $dir = \dirname($path);
        while ('/' !== $dir && '' !== $dir && '.' !== $dir) {
            $this->folders[strtolower($dir)] ??= $dir;
            $dir = \dirname($dir);
        }
    }

    /** @return array<string, mixed>|null */
    private function meta(string $key): ?array
    {
        if (isset($this->files[$key])) {
            return $this->fileMeta($key);
        }
        if (isset($this->folders[$key])) {
            return ['.tag' => 'folder', 'name' => basename($this->folders[$key]), 'path_lower' => $key, 'path_display' => $this->folders[$key], 'id' => 'id:'.md5($key)];
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function fileMeta(string $key): array
    {
        $f = $this->files[$key];

        return ['.tag' => 'file', 'name' => basename($f['display']), 'path_lower' => $key, 'path_display' => $f['display'], 'id' => 'id:'.md5($key),
            'client_modified' => gmdate('Y-m-d\TH:i:s\Z', $f['modified']), 'server_modified' => gmdate('Y-m-d\TH:i:s\Z', $f['modified']),
            'rev' => substr(md5($f['bytes']), 0, 9), 'size' => \strlen($f['bytes']), 'is_downloadable' => true, 'content_hash' => hash('sha256', $f['bytes'])];
    }

    private static function notFound(string $tag): Response
    {
        return self::json(['error_summary' => "$tag/not_found/..", 'error' => ['.tag' => $tag, $tag => ['.tag' => 'not_found']]], 409);
    }

    /** @param array<string, mixed> $body */
    private static function json(array $body, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], (string) json_encode($body));
    }
}
