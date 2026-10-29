<?php

namespace Omnidrive\Dropbox;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
use Spatie\Dropbox\RefreshableTokenProvider;

/**
 * Dropbox's short-lived access tokens (four hours since 2021), renewed from a
 * refresh token and the app's key and secret: asked on the first request, again
 * a minute before it expires, and when Dropbox answers 401. A refresh token
 * Dropbox refuses, or its token endpoint out of reach, is a TokenRefusedException
 * naming why (every drive operation then fails with it, as a DriveException).
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
        try {
            $response = $this->http->request('POST', self::TOKEN_URL, ['form_params' => [
                'grant_type' => 'refresh_token',
                'refresh_token' => $this->refreshToken,
                'client_id' => $this->appKey,
                'client_secret' => $this->appSecret,
            ]]);
        } catch (BadResponseException $e) {
            // {"error": "invalid_grant", "error_description": "refresh token is malformed"}
            $error = json_decode((string) $e->getResponse()->getBody(), true);
            $reason = \is_array($error) ? trim(implode(': ', array_filter([\is_string($error['error'] ?? null) ? $error['error'] : null, \is_string($error['error_description'] ?? null) ? $error['error_description'] : null]))) : '';
            throw new TokenRefusedException(\sprintf('Dropbox refused the refresh token (HTTP %d%s).', $e->getResponse()->getStatusCode(), '' !== $reason ? ', '.$reason : ''), 0, $e);
        } catch (GuzzleException $e) {
            throw new TokenRefusedException('Dropbox refused the refresh token: its token endpoint cannot be reached ('.$e->getMessage().').', 0, $e);
        }
        /** @var array{access_token?: string, expires_in?: int} $body */
        $body = json_decode((string) $response->getBody(), true) ?: [];
        if (!isset($body['access_token'])) {
            throw new TokenRefusedException('Dropbox refused the refresh token: its answer holds no access token.');
        }
        $this->token = $body['access_token'];
        $this->expiresAt = time() + (int) ($body['expires_in'] ?? 14400);
    }
}
