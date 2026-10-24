<?php

namespace Omnidrive\Dropbox;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ClientException;
use Spatie\Dropbox\RefreshableTokenProvider;

/**
 * Dropbox's short-lived access tokens (four hours since 2021), renewed from a
 * refresh token and the app's key and secret: asked on the first request, again
 * a minute before it expires, and when Dropbox answers 401.
 */
final class RefreshTokenProvider implements RefreshableTokenProvider
{
    public const TOKEN_URL = 'https://api.dropboxapi.com/oauth2/token';

    private ?string $token = null;
    private int $expiresAt = 0;
    private readonly ClientInterface $http;

    public function __construct(
        private readonly string $refreshToken,
        private readonly string $appKey,
        private readonly string $appSecret,
        ?ClientInterface $http = null,
    ) {
        $this->http = $http ?? new Client();
    }

    public function getToken(): string
    {
        if (null === $this->token || time() >= $this->expiresAt - 60) {
            $this->renew();
        }

        return (string) $this->token;
    }

    public function refresh(ClientException $exception): bool
    {
        if (401 !== $exception->getResponse()->getStatusCode()) {
            return false;
        }
        $this->renew();

        return true;
    }

    private function renew(): void
    {
        $response = $this->http->request('POST', self::TOKEN_URL, ['form_params' => [
            'grant_type' => 'refresh_token',
            'refresh_token' => $this->refreshToken,
            'client_id' => $this->appKey,
            'client_secret' => $this->appSecret,
        ]]);
        /** @var array{access_token?: string, expires_in?: int} $body */
        $body = json_decode((string) $response->getBody(), true) ?: [];
        if (!isset($body['access_token'])) {
            throw new \RuntimeException('Dropbox gave no access token for the refresh token.');
        }
        $this->token = $body['access_token'];
        $this->expiresAt = time() + (int) ($body['expires_in'] ?? 14400);
    }
}
