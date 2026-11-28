<?php

namespace App\Services\Storage\Drivers;

use App\Services\Storage\StorageException;
use Illuminate\Http\Client\PendingRequest;

/**
 * Authorization-code flow with a refresh token, shared by Google Drive, OneDrive and Dropbox.
 * The app keys live in config/services.php (.env); the tokens live encrypted in
 * storage_connections.credentials: {access_token, refresh_token, expires_at}.
 */
abstract class OAuthDriver extends Driver
{
    /** Key in config/services.php. */
    abstract public static function configKey(): string;

    abstract protected function authorizeEndpoint(): string;

    abstract protected function tokenEndpoint(): string;

    /** @return array<string, string> extra query parameters for the consent screen */
    abstract protected function authorizeParams(): array;

    /** Scope sent with token requests (only some providers need it). */
    protected function tokenScope(): ?string
    {
        return null;
    }

    public static function configured(): bool
    {
        return filled(config('services.'.static::configKey().'.client_id')) && filled(config('services.'.static::configKey().'.client_secret'));
    }

    protected function clientId(): string
    {
        return (string) config('services.'.static::configKey().'.client_id');
    }

    protected function clientSecret(): string
    {
        return (string) config('services.'.static::configKey().'.client_secret');
    }

    public function authorizeUrl(string $state, string $redirectUri): string
    {
        return $this->authorizeEndpoint().'?'.http_build_query([
            'client_id' => $this->clientId(),
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'state' => $state,
        ] + $this->authorizeParams(), '', '&', PHP_QUERY_RFC3986);
    }

    /** Trade the code from the consent screen for tokens and keep them on the connection. */
    public function exchange(string $code, string $redirectUri): void
    {
        $r = $this->check($this->call(fn () => $this->http()->asForm()->post($this->tokenEndpoint(), array_filter([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'scope' => $this->tokenScope(),
        ]))), 'فشل الحصول على رمز الدخول');

        if (! $r->json('refresh_token')) {
            throw new StorageException('لم تُرسل الخدمة رمز تحديث (refresh token). أزل صلاحية التطبيق من حسابك ثم أعد الربط.');
        }
        $this->storeTokens($r->json());
    }

    /** A valid access token, refreshed when it is about to expire. */
    protected function token(): string
    {
        $c = $this->connection->credentials ?? [];
        if (empty($c['refresh_token'])) {
            throw new StorageException('الحساب غير مربوط. اربطه من إعدادات التخزين.');
        }
        if (empty($c['access_token']) || (int) ($c['expires_at'] ?? 0) - 60 <= time()) {
            $r = $this->check($this->call(fn () => $this->http()->asForm()->post($this->tokenEndpoint(), array_filter([
                'grant_type' => 'refresh_token',
                'refresh_token' => $c['refresh_token'],
                'client_id' => $this->clientId(),
                'client_secret' => $this->clientSecret(),
                'scope' => $this->tokenScope(),
            ]))), 'انتهت صلاحية الربط ولم يمكن تجديده؛ أعد ربط الحساب');
            $c = $this->storeTokens($r->json());
        }

        return $c['access_token'];
    }

    /** @param array<string, mixed> $json @return array<string, mixed> */
    private function storeTokens(array $json): array
    {
        $c = array_merge($this->connection->credentials ?? [], array_filter([
            'access_token' => $json['access_token'] ?? null,
            'refresh_token' => $json['refresh_token'] ?? null, // some providers rotate it
            'expires_at' => time() + (int) ($json['expires_in'] ?? 3600),
        ]));
        $this->connection->credentials = $c;
        if ($this->connection->exists) {
            $this->connection->save();
        }

        return $c;
    }

    protected function api(): PendingRequest
    {
        return $this->http()->withToken($this->token());
    }
}
