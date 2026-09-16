<?php

namespace App\Lighting\Drivers;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Throwable;

/** Selected-device API only; no redirects, automatic retries, or secret logging. */
class TuyaCloudClient
{
    private array $settings;

    private Closure $clock;

    private Closure $nonce;

    private ?string $token = null;

    private int $tokenExpiresAt = 0;

    private bool $credentialsLoaded = false;

    private const ENDPOINTS = [
        'https://openapi.tuyaeu.com', 'https://openapi-weaz.tuyaeu.com',
        'https://openapi.tuyaus.com', 'https://openapi-ueaz.tuyaus.com',
        'https://openapi.tuyacn.com', 'https://openapi.tuyain.com', 'https://openapi-sg.iotbing.com',
    ];

    public function __construct(?array $settings = null, private ?Factory $http = null, ?Closure $clock = null, ?Closure $nonce = null)
    {
        $this->settings = $settings ?? config('lighting.cloud', []);
        $this->clock = $clock ?? static fn (): int => (int) floor(microtime(true) * 1000);
        $this->nonce = $nonce ?? static fn (): string => bin2hex(random_bytes(16));
    }

    public function deviceId(): string
    {
        $id = $this->settings['device_id'] ?? '';
        if (! is_string($id) || ! preg_match('/\A[A-Za-z0-9_-]{6,128}\z/D', $id)) {
            throw new TuyaCloudException('configuration_error');
        }

        return $id;
    }

    public function isOnline(): bool
    {
        $data = $this->request('GET', '/v1.1/iot-03/devices/'.$this->deviceId());
        if (($data['result']['id'] ?? null) !== $this->deviceId() || ! is_bool($data['result']['online'] ?? null)) {
            throw new TuyaCloudException('configuration_error');
        }

        return $data['result']['online'];
    }

    /** These are cloud-reported stored values, not a live physical output read. */
    public function readProperties(): array
    {
        $data = $this->request('GET', '/v2.0/cloud/thing/'.$this->deviceId().'/shadow/properties');
        if (! is_array($data['result']['properties'] ?? null) || ! is_int($data['t'] ?? null)) {
            throw new TuyaCloudException('configuration_error');
        }

        return ['properties' => $data['result']['properties'], 'serverTime' => $data['t']];
    }

    /** One API write attempt. Even an expired token or timeout is never replayed. */
    public function sendCommands(array $commands): array
    {
        $allowed = ['switch_led', 'work_mode', 'bright_value_v2', 'temp_value_v2', 'control_data', 'scene_data_v2'];
        if (! array_is_list($commands) || count($commands) < 1 || count($commands) > 4) {
            throw new TuyaCloudException('configuration_error');
        }
        $seen = [];
        foreach ($commands as $command) {
            if (! is_array($command) || count($command) !== 2 || ! isset($command['code'])
                || ! in_array($command['code'], $allowed, true) || ! array_key_exists('value', $command)
                || isset($seen[$command['code']])) {
                throw new TuyaCloudException('configuration_error');
            }
            $seen[$command['code']] = true;
        }
        $deviceId = $this->deviceId();
        // A token refresh can take seconds; freshness starts only after it.
        $this->accessToken();
        $sentAt = ($this->clock)();
        $data = $this->request('POST', '/v1.0/iot-03/devices/'.$deviceId.'/commands', ['commands' => $commands]);
        if (($data['result'] ?? null) !== true) {
            throw new TuyaCloudException('transport_timeout', writeOutcomeUnknown: true);
        }

        return ['sentAt' => $sentAt, 'acceptedAt' => $data['t'] ?? null];
    }

    private function accessToken(): string
    {
        if ($this->token !== null && ($this->clock)() < $this->tokenExpiresAt) {
            return $this->token;
        }
        $data = $this->request('GET', '/v1.0/token?grant_type=1', tokenRequest: true);
        $token = $data['result']['access_token'] ?? null;
        $expires = $data['result']['expire_time'] ?? null;
        if (! is_string($token) || ! preg_match('/\A[A-Za-z0-9_-]{8,512}\z/D', $token)
            || ! is_int($expires) || $expires < 60 || $expires > 86400) {
            throw new TuyaCloudException('configuration_error');
        }
        $this->token = $token;
        $this->tokenExpiresAt = ($this->clock)() + ($expires - 30) * 1000;

        return $token;
    }

    private function request(string $method, string $path, ?array $payload = null, bool $tokenRequest = false): array
    {
        if (! $this->credentialsLoaded) {
            if (isset($this->settings['credentials_file'])) {
                if (! is_string($this->settings['credentials_file'])) {
                    throw new TuyaCloudException('configuration_error');
                }
                $saved = CloudLightingFiles::readJson($this->settings['credentials_file']);
                $this->settings = array_merge($this->settings, array_intersect_key($saved, array_flip(['endpoint', 'client_id', 'client_secret'])));
            }
            $this->credentialsLoaded = true;
        }
        $endpoint = $this->settings['endpoint'] ?? '';
        $clientId = $this->settings['client_id'] ?? '';
        $secret = $this->settings['client_secret'] ?? '';
        if (! in_array($endpoint, self::ENDPOINTS, true) || ! is_string($clientId)
            || ! preg_match('/\A[A-Za-z0-9_-]{8,128}\z/D', $clientId)
            || ! is_string($secret) || strlen($secret) < 16 || strlen($secret) > 256) {
            throw new TuyaCloudException('configuration_error');
        }
        $write = $method === 'POST';
        $token = $tokenRequest ? '' : $this->accessToken();
        try {
            $body = $payload === null ? '' : json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $timestamp = (string) ($this->clock)();
            $nonce = ($this->nonce)();
            if (! is_string($nonce) || ! preg_match('/\A[0-9a-f]{32}\z/D', $nonce)) {
                throw new TuyaCloudException('configuration_error');
            }
            // Tuya's current HMAC signing scheme signs the exact transmitted body.
            // https://developer.tuya.com/en/docs/iot/singnature?id=Ka43a5mtx1gsc
            $toSign = $method."\n".hash('sha256', $body)."\n\n".$path;
            $headers = ['client_id' => $clientId, 't' => $timestamp, 'nonce' => $nonce,
                'sign_method' => 'HMAC-SHA256', 'sign' => strtoupper(hash_hmac('sha256', $clientId.$token.$timestamp.$nonce.$toSign, $secret))];
            if (! $tokenRequest) {
                $headers['access_token'] = $token;
            }
            $factory = $this->http ?? Http::getFacadeRoot();
            $response = $factory->withHeaders($headers)->acceptJson()->contentType('application/json')
                ->timeout(max(0.5, min(5, ($this->settings['timeout_ms'] ?? 3000) / 1000)))
                ->connectTimeout(max(0.2, min(2, ($this->settings['connect_timeout_ms'] ?? 1000) / 1000)))
                ->withoutRedirecting()->send($method, $endpoint.$path, ['body' => $body]);
            if (! $response->successful()) {
                throw new TuyaCloudException($response->status() === 429 ? 'transport_timeout' : 'offline', writeOutcomeUnknown: $write);
            }
            if (strlen($response->body()) > 1048576) {
                throw new TuyaCloudException('configuration_error', writeOutcomeUnknown: $write);
            }
            $data = $response->json();
            if (! is_array($data) || ($data['success'] ?? null) !== true) {
                $code = $data['code'] ?? null;
                $code = (is_int($code) || is_string($code)) && preg_match('/\A[0-9]{1,8}\z/D', (string) $code) ? (string) $code : null;
                if (in_array($code, ['1010', '1011', '1012'], true)) {
                    $this->token = null;
                    $this->tokenExpiresAt = 0;
                }
                throw new TuyaCloudException('configuration_error', $code, $write);
            }

            return $data;
        } catch (TuyaCloudException $exception) {
            throw $exception;
        } catch (ConnectionException) {
            throw new TuyaCloudException('transport_timeout', writeOutcomeUnknown: $write);
        } catch (Throwable) {
            // Never chain an HTTP/library exception: it can contain authorization
            // headers, endpoint payloads or device credentials in its message.
            throw new TuyaCloudException('configuration_error', writeOutcomeUnknown: $write);
        }
    }
}
