<?php

namespace App\Lighting\Drivers;

use Closure;
use GuzzleHttp\Utils;
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

    private ?array $model = null;

    // Share the connection pool, while keeping each request's signature,
    // headers and Laravel middleware isolated. Reconnecting for every fade
    // frame adds a TLS round trip between otherwise continuous targets.
    private ?Closure $transportHandler = null;

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

    /** Read-only compatibility check for a newly configured cloud device ID. */
    public function readFunctions(): array
    {
        $data = $this->request('GET', '/v1.1/devices/'.$this->deviceId().'/specifications');
        $functions = $data['result']['functions'] ?? null;
        if (! is_array($functions) || ! array_is_list($functions)) {
            throw new TuyaCloudException('configuration_error');
        }

        return $functions;
    }

    /** Refresh only the selected device's native model; never an account list. */
    public function readModel(): array
    {
        $this->model = null;
        $data = $this->request('GET', '/v2.0/cloud/thing/'.$this->deviceId().'/model');
        $encoded = $data['result']['model'] ?? null;
        if (! is_string($encoded)) {
            throw new TuyaCloudException('configuration_error');
        }
        $model = CloudLightingFiles::object($encoded);
        $services = $model['services'] ?? null;
        if (! is_array($services) || ! array_is_list($services) || count($services) < 1 || count($services) > 64) {
            throw new TuyaCloudException('configuration_error');
        }
        foreach ($services as $service) {
            if (! is_array($service) || ! is_string($service['code'] ?? null)
                || ! is_array($service['properties'] ?? null) || ! array_is_list($service['properties'])) {
                throw new TuyaCloudException('configuration_error');
            }
        }

        return $this->model = $model;
    }

    /** Configure DP35 only. API acceptance does not prove any physical fade. */
    public function sendSwitchGradient(mixed $onMs, mixed $offMs): array
    {
        if (! is_int($onMs) || ! is_int($offMs) || $onMs < 0 || $onMs > 60000 || $offMs < 0 || $offMs > 60000) {
            throw new TuyaCloudException('configuration_error');
        }
        $deviceId = $this->deviceId();
        $model = $this->model ?? $this->readModel();
        $matches = 0;
        foreach ($model['services'] as $service) {
            foreach ($service['properties'] as $property) {
                if (! is_array($property)) {
                    throw new TuyaCloudException('configuration_error');
                }
                if (($property['abilityId'] ?? null) !== 35 && ($property['code'] ?? null) !== 'switch_gradient') {
                    continue;
                }
                if ($service['code'] !== '' || ($property['abilityId'] ?? null) !== 35
                    || ($property['code'] ?? null) !== 'switch_gradient' || ($property['accessMode'] ?? null) !== 'rw'
                    || ($property['typeSpec']['type'] ?? null) !== 'raw'
                    || ! is_int($property['typeSpec']['maxlen'] ?? null) || $property['typeSpec']['maxlen'] < 7) {
                    throw new TuyaCloudException('configuration_error');
                }
                $matches++;
            }
        }
        if ($matches !== 1) {
            throw new TuyaCloudException('configuration_error');
        }
        // Tuya DP35: version 00, then two unsigned big-endian 24-bit ms
        // durations. Native raw properties use base64, not LAN hex strings.
        // https://developer.tuya.com/cn/docs/iot/ceiling-light-function-definiton?id=K9tp11kysv22w
        $raw = "\0".substr(pack('N', $onMs), 1).substr(pack('N', $offMs), 1);
        $properties = json_encode(['switch_gradient' => base64_encode($raw)], JSON_THROW_ON_ERROR);
        $this->accessToken();
        $sentAt = ($this->clock)();
        // /issue sends immediately; /desired would persist deferred commands.
        // https://developer.tuya.com/en/docs/cloud/c057ad5cfd?id=Kcp2kxdzftp91
        $data = $this->request('POST', '/v2.0/cloud/thing/'.$deviceId.'/shadow/properties/issue', ['properties' => $properties]);
        if (($data['result'] ?? null) !== []) {
            throw new TuyaCloudException('transport_timeout', writeOutcomeUnknown: true);
        }

        return ['sentAt' => $sentAt, 'acceptedAt' => $data['t'] ?? null];
    }

    /** One API write attempt. Even an expired token or timeout is never replayed. */
    public function sendRealtime(array $channels): array
    {
        // Native DP28 avoids the incompatible 0..255 S/V schema exposed by
        // the standardized JSON command. Wire format is the same as LAN:
        // gradient flag + H4/S4/V4/white4/temperature4 (21 hex characters).
        // https://developer.tuya.com/en/docs/iot/product-function-definition?id=K9s9rhj576ypf
        $fields = ['h' => 360, 's' => 1000, 'v' => 1000, 'bright' => 1000, 'temperature' => 1000];
        if (count($channels) !== count($fields) || array_diff_key($channels, $fields) !== []) {
            throw new TuyaCloudException('configuration_error');
        }
        $payload = '1';
        foreach ($fields as $name => $max) {
            $value = $channels[$name] ?? null;
            if (! is_int($value) || $value < 0 || $value > $max) {
                throw new TuyaCloudException('configuration_error');
            }
            $payload .= sprintf('%04x', $value);
        }
        if ($channels['bright'] === 0 && $channels['v'] === 0) {
            throw new TuyaCloudException('unsupported_transition');
        }
        $model = $this->model ?? $this->readModel();
        $matches = 0;
        foreach ($model['services'] as $service) {
            foreach ($service['properties'] as $property) {
                if (! is_array($property)) {
                    throw new TuyaCloudException('configuration_error');
                }
                if (($property['abilityId'] ?? null) !== 28 && ($property['code'] ?? null) !== 'control_data') {
                    continue;
                }
                if ($service['code'] !== '' || ($property['abilityId'] ?? null) !== 28
                    || ($property['code'] ?? null) !== 'control_data'
                    || ! in_array($property['accessMode'] ?? null, ['wr', 'rw'], true)
                    || ($property['typeSpec']['type'] ?? null) !== 'string'
                    || ! is_int($property['typeSpec']['maxlen'] ?? null) || $property['typeSpec']['maxlen'] < 21) {
                    throw new TuyaCloudException('configuration_error');
                }
                $matches++;
            }
        }
        if ($matches !== 1) {
            throw new TuyaCloudException('configuration_error');
        }
        $this->accessToken();
        $sentAt = ($this->clock)();
        $data = $this->request('POST', '/v2.0/cloud/thing/'.$this->deviceId().'/shadow/properties/issue', [
            'properties' => json_encode(['control_data' => $payload], JSON_THROW_ON_ERROR),
        ]);
        if (($data['result'] ?? null) !== []) {
            throw new TuyaCloudException('transport_timeout', writeOutcomeUnknown: true);
        }

        return ['sentAt' => $sentAt, 'acceptedAt' => $data['t'] ?? null];
    }

    /** Stop scene playback at one explicit RGB value, without a power command. */
    public function sendStaticColour(array $channels): array
    {
        $fields = ['h' => 360, 's' => 1000, 'v' => 1000, 'bright' => 0, 'temperature' => 0];
        if (count($channels) !== count($fields) || array_diff_key($channels, $fields) !== []) {
            throw new TuyaCloudException('configuration_error');
        }
        foreach ($fields as $field => $maximum) {
            if (! is_int($channels[$field] ?? null) || $channels[$field] < ($field === 'v' ? 10 : 0)
                || $channels[$field] > $maximum) {
                throw new TuyaCloudException('configuration_error');
            }
        }
        $deviceId = $this->deviceId();
        $model = $this->model ?? $this->readModel();
        $matches = [21 => 0, 24 => 0];
        foreach ($model['services'] as $service) {
            foreach ($service['properties'] as $property) {
                if (! is_array($property)) {
                    throw new TuyaCloudException('configuration_error');
                }
                foreach ([21 => 'work_mode', 24 => 'colour_data'] as $id => $code) {
                    if (($property['abilityId'] ?? null) !== $id && ($property['code'] ?? null) !== $code) {
                        continue;
                    }
                    if ($service['code'] !== '' || ($property['abilityId'] ?? null) !== $id
                        || ($property['code'] ?? null) !== $code || ($property['accessMode'] ?? null) !== 'rw') {
                        throw new TuyaCloudException('configuration_error');
                    }
                    $spec = $property['typeSpec'] ?? [];
                    if ($id === 24) {
                        if (($spec['type'] ?? null) !== 'string' || ! is_int($spec['maxlen'] ?? null) || $spec['maxlen'] < 12) {
                            throw new TuyaCloudException('configuration_error');
                        }
                    } elseif (($spec['type'] ?? null) !== 'enum' || ! is_array($spec['range'] ?? null)
                        || ! array_is_list($spec['range']) || ! in_array('colour', $spec['range'], true)) {
                        throw new TuyaCloudException('configuration_error');
                    }
                    $matches[$id]++;
                }
            }
        }
        if ($matches !== [21 => 1, 24 => 1]) {
            throw new TuyaCloudException('configuration_error');
        }
        $this->accessToken();
        $sentAt = ($this->clock)();
        $data = $this->request('POST', '/v2.0/cloud/thing/'.$deviceId.'/shadow/properties/issue', [
            'properties' => json_encode(['colour_data' => sprintf('%04x%04x%04x', $channels['h'], $channels['s'], $channels['v']),
                'work_mode' => 'colour'], JSON_THROW_ON_ERROR),
        ]);
        if (($data['result'] ?? null) !== []) {
            throw new TuyaCloudException('transport_timeout', writeOutcomeUnknown: true);
        }

        return ['sentAt' => $sentAt, 'acceptedAt' => $data['t'] ?? null];
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
                ->setHandler($this->transportHandler ??= Closure::fromCallable(Utils::chooseHandler()))
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
