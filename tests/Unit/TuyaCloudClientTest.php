<?php

namespace Tests\Unit;

use App\Lighting\Drivers\TuyaCloudClient;
use App\Lighting\Drivers\TuyaCloudException;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TuyaCloudClientTest extends TestCase
{
    private const ENDPOINT = 'https://openapi.tuyaeu.com';

    private const DEVICE = 'fixture_device_123456';

    private const CLIENT = 'fixture_client_123456';

    private const SECRET = 'fixture-secret-0123456789';

    private const TOKEN = 'fixture_token_123456';

    private const NOW = 1700000000123;

    private const NONCE = '000102030405060708090a0b0c0d0e0f';

    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            unlink($path);
        }
        parent::tearDown();
    }

    private function client(array $settings = [], ?Closure $clock = null): TuyaCloudClient
    {
        return new TuyaCloudClient(array_merge([
            'endpoint' => self::ENDPOINT, 'device_id' => self::DEVICE,
            'client_id' => self::CLIENT, 'client_secret' => self::SECRET,
        ], $settings), clock: $clock ?? static fn () => self::NOW, nonce: static fn () => self::NONCE);
    }

    private function token(int $expires = 3600): array
    {
        return ['success' => true, 'result' => ['access_token' => self::TOKEN, 'expire_time' => $expires]];
    }

    private function commands(): array
    {
        return [['code' => 'switch_led', 'value' => true]];
    }

    private function failure(Closure $operation): TuyaCloudException
    {
        try {
            $operation();
        } catch (TuyaCloudException $error) {
            return $error;
        }
        $this->fail('Expected a sanitized TuyaCloudException.');
    }

    public function test_known_hmac_vectors_sign_the_exact_token_shadow_and_unicode_json_bodies(): void
    {
        $requests = [];
        Http::fake(function (Request $request) use (&$requests) {
            $requests[] = $request;

            return Http::response(str_contains($request->url(), '/token?') ? $this->token() : (
                $request->method() === 'GET'
                    ? ['success' => true, 't' => self::NOW, 'result' => ['properties' => []]]
                    : ['success' => true, 't' => self::NOW, 'result' => true]
            ));
        });
        $client = $this->client();
        $this->assertSame(['properties' => [], 'serverTime' => self::NOW], $client->readProperties());
        $scene = '{"scene_num":7,"scene_units":[{"h":240,"s":1000,"v":550}],"label":"Сцена/a"}';
        $client->sendCommands([['code' => 'work_mode', 'value' => 'scene'], ['code' => 'scene_data_v2', 'value' => $scene]]);
        $this->assertCount(3, $requests);
        // Independent Python hashlib/hmac vectors, with a frozen timestamp and nonce.
        $this->assertSame([
            '7C7E540799C365556DDF8453650EC2E7995904BA8F338D3C1F02E94A567C72CB',
            'BE72CD1075C6DD298DA3414971419C635B4735E4EE0201206732226D8D2AD1CB',
            '17097D9A5B08E94FFBBBEC26F3846B24722255A67AD24240901CA3D8B12EED51',
        ], array_map(fn ($request) => $request->header('sign')[0], $requests));
        $this->assertSame('', $requests[0]->body());
        $this->assertSame('', $requests[1]->body());
        $this->assertSame('{"commands":[{"code":"work_mode","value":"scene"},{"code":"scene_data_v2","value":"{\"scene_num\":7,\"scene_units\":[{\"h\":240,\"s\":1000,\"v\":550}],\"label\":\"Сцена/a\"}"}]}', $requests[2]->body());
        $this->assertSame([], $requests[0]->header('access_token'));
        foreach ($requests as $request) {
            $this->assertSame([self::CLIENT], $request->header('client_id'));
            $this->assertSame([self::NONCE], $request->header('nonce'));
            $this->assertSame([(string) self::NOW], $request->header('t'));
            $this->assertSame(['HMAC-SHA256'], $request->header('sign_method'));
            $this->assertStringNotContainsString(self::SECRET, $request->body().json_encode($request->headers()));
        }
        $this->assertSame([self::TOKEN], $requests[1]->header('access_token'));
        $this->assertSame([self::TOKEN], $requests[2]->header('access_token'));
    }

    public function test_only_selected_device_paths_are_used_and_receipt_starts_after_token_fetch(): void
    {
        $now = self::NOW;
        $requests = [];
        Http::fake(function (Request $request) use (&$now, &$requests) {
            $requests[] = $request;
            if (str_contains($request->url(), '/token?')) {
                $now += 1500;

                return Http::response($this->token());
            }

            return Http::response(['success' => true, 't' => $now + 20, 'result' => $request->method() === 'POST'
                ? true : (str_contains($request->url(), '/shadow/') ? ['properties' => []] : ['id' => self::DEVICE, 'online' => true])]);
        });
        $client = $this->client(clock: static function () use (&$now) {
            return $now;
        });
        $receipt = $client->sendCommands($this->commands());
        $this->assertTrue($client->isOnline());
        $client->readProperties();
        $this->assertSame(self::NOW + 1500, $receipt['sentAt']);
        $this->assertSame(self::NOW + 1520, $receipt['acceptedAt']);
        $this->assertSame([
            self::ENDPOINT.'/v1.0/token?grant_type=1',
            self::ENDPOINT.'/v1.0/iot-03/devices/'.self::DEVICE.'/commands',
            self::ENDPOINT.'/v1.1/iot-03/devices/'.self::DEVICE,
            self::ENDPOINT.'/v2.0/cloud/thing/'.self::DEVICE.'/shadow/properties',
        ], array_map(fn ($request) => $request->url(), $requests));
    }

    public function test_cached_token_refreshes_only_before_an_independent_request_at_expiry(): void
    {
        $now = self::NOW;
        $tokenCalls = 0;
        Http::fake(function (Request $request) use (&$tokenCalls) {
            if (str_contains($request->url(), '/token?')) {
                $tokenCalls++;

                return Http::response($this->token(90));
            }

            return Http::response(['success' => true, 'result' => ['id' => self::DEVICE, 'online' => true]]);
        });
        $client = $this->client(clock: static function () use (&$now) {
            return $now;
        });
        $client->isOnline();
        $now += 59999;
        $client->isOnline();
        $this->assertSame(1, $tokenCalls);
        $now++;
        $client->isOnline();
        $this->assertSame(2, $tokenCalls);
        Http::assertSentCount(5);
    }

    public static function writeFailures(): array
    {
        return [
            'expired token' => [['success' => false, 'code' => '1010', 'msg' => self::SECRET], 200, 'configuration_error', '1010'],
            'unsafe vendor text' => [['success' => false, 'code' => self::SECRET, 'msg' => self::TOKEN], 200, 'configuration_error', null],
            'rate limit' => [['msg' => self::SECRET], 429, 'transport_timeout', null],
            'server error' => [['msg' => self::SECRET], 500, 'offline', null],
            'redirect' => [['msg' => self::SECRET], 302, 'offline', null],
            'malformed JSON' => [self::SECRET, 200, 'configuration_error', null],
            'rejected result' => [['success' => true, 'result' => false], 200, 'transport_timeout', null],
        ];
    }

    #[DataProvider('writeFailures')]
    public function test_write_failures_are_sanitized_and_never_retried(array|string $body, int $status, string $category, ?string $vendor): void
    {
        $calls = [];
        Http::fake(function (Request $request) use (&$calls, $body, $status) {
            $calls[] = $request->method().' '.$request->url();

            return str_contains($request->url(), '/token?') ? Http::response($this->token())
                : Http::response($body, $status, ['Location' => 'https://untrusted.invalid/redirect']);
        });
        $error = $this->failure(fn () => $this->client()->sendCommands($this->commands()));
        $this->assertSame($category, $error->getMessage());
        $this->assertSame($vendor, $error->vendorCode);
        $this->assertTrue($error->writeOutcomeUnknown);
        $this->assertNull($error->getPrevious());
        $this->assertCount(2, $calls);
        $this->assertSame('POST '.self::ENDPOINT.'/v1.0/iot-03/devices/'.self::DEVICE.'/commands', $calls[1]);
    }

    public function test_connection_timeout_never_replays_write_or_exposes_exception_details(): void
    {
        $attempts = 0;
        Http::fake(function (Request $request) use (&$attempts) {
            if (str_contains($request->url(), '/token?')) {
                return Http::response($this->token());
            }
            $attempts++;
            throw new ConnectionException('Fixture authorization '.$request->header('access_token')[0].' '.self::SECRET);
        });
        $error = $this->failure(fn () => $this->client()->sendCommands($this->commands()));
        $this->assertSame('transport_timeout', $error->getMessage());
        $this->assertSame(1, $attempts);
        $this->assertTrue($error->writeOutcomeUnknown);
        $this->assertNull($error->getPrevious());
    }

    public function test_expired_write_token_is_refreshed_for_the_next_read_without_replaying_old_write(): void
    {
        $tokens = 0;
        $writes = 0;
        Http::fake(function (Request $request) use (&$tokens, &$writes) {
            if (str_contains($request->url(), '/token?')) {
                $tokens++;

                return Http::response($this->token());
            }
            if ($request->method() === 'POST') {
                $writes++;

                return Http::response(['success' => false, 'code' => '1010']);
            }

            return Http::response(['success' => true, 'result' => ['id' => self::DEVICE, 'online' => false]]);
        });
        $client = $this->client();
        $this->failure(fn () => $client->sendCommands($this->commands()));
        $this->assertSame(1, $tokens);
        $this->assertSame(1, $writes);
        $this->assertFalse($client->isOnline());
        $this->assertSame(2, $tokens);
        $this->assertSame(1, $writes);
    }

    public function test_private_credentials_file_is_loaded_without_overriding_selected_device_or_being_modified(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'eh-cloud-credentials-test-');
        $this->temporaryFiles[] = $path;
        $contents = json_encode(['endpoint' => self::ENDPOINT, 'client_id' => self::CLIENT,
            'client_secret' => self::SECRET, 'device_id' => 'unselected_fixture_device']);
        file_put_contents($path, $contents);
        chmod($path, 0600);
        Http::fake([
            self::ENDPOINT.'/v1.0/token?grant_type=1' => Http::response($this->token()),
            self::ENDPOINT.'/v1.1/iot-03/devices/'.self::DEVICE => Http::response(['success' => true, 'result' => ['id' => self::DEVICE, 'online' => true]]),
        ]);
        $client = $this->client(['credentials_file' => $path, 'client_id' => '', 'client_secret' => '']);
        $this->assertTrue($client->isOnline());
        $this->assertSame(self::DEVICE, $client->deviceId());
        $this->assertSame($contents, file_get_contents($path));
        $this->assertSame(0600, fileperms($path) & 0777);
        Http::assertSentCount(2);
    }

    public function test_group_readable_credentials_are_rejected_before_any_http_request(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'eh-cloud-credentials-test-');
        $this->temporaryFiles[] = $path;
        file_put_contents($path, json_encode(['client_id' => self::CLIENT, 'client_secret' => self::SECRET]));
        chmod($path, 0640);
        Http::fake();
        $error = $this->failure(fn () => $this->client(['credentials_file' => $path])->isOnline());
        $this->assertSame('configuration_error', $error->getMessage());
        $this->assertFalse($error->writeOutcomeUnknown);
        Http::assertNothingSent();
    }

    public function test_invalid_endpoint_device_or_duplicate_commands_never_reach_http(): void
    {
        Http::fake();
        foreach ([['endpoint' => 'https://untrusted.invalid'], ['device_id' => '../another-device']] as $settings) {
            $this->assertSame('configuration_error', $this->failure(fn () => $this->client($settings)->isOnline())->getMessage());
        }
        foreach ([[], [['code' => 'countdown_1', 'value' => 1]], array_merge($this->commands(), $this->commands())] as $commands) {
            $this->assertSame('configuration_error', $this->failure(fn () => $this->client()->sendCommands($commands))->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_online_response_for_another_device_is_rejected(): void
    {
        Http::fake([
            self::ENDPOINT.'/v1.0/token?grant_type=1' => Http::response($this->token()),
            '*' => Http::response(['success' => true, 'result' => ['id' => 'another_fixture_device', 'online' => true]]),
        ]);
        $error = $this->failure(fn () => $this->client()->isOnline());
        $this->assertSame('configuration_error', $error->getMessage());
        $this->assertFalse($error->writeOutcomeUnknown);
        Http::assertSentCount(2);
    }

    private function nativeModel(array $property = []): array
    {
        return ['modelId' => 'fixture_model', 'services' => [['code' => '', 'properties' => [array_replace([
            'abilityId' => 35, 'code' => 'switch_gradient', 'accessMode' => 'rw', 'typeSpec' => ['type' => 'raw', 'maxlen' => 255],
        ], $property)]]]];
    }

    public function test_native_gradient_uses_selected_model_and_exact_seven_byte_base64_without_generic_property_access(): void
    {
        $requests = [];
        $now = self::NOW;
        Http::fake(function (Request $request) use (&$requests, &$now) {
            $requests[] = $request;
            if (str_contains($request->url(), '/token?')) {
                $now += 1000;

                return Http::response($this->token());
            }
            if (str_ends_with($request->url(), '/model')) {
                $now += 500;

                return Http::response(['success' => true, 'result' => ['model' => json_encode($this->nativeModel())]]);
            }

            return Http::response(['success' => true, 't' => $now + 20, 'result' => (object) []]);
        });
        $client = $this->client(clock: static function () use (&$now) {
            return $now;
        });
        $this->assertSame($this->nativeModel(), $client->readModel());
        $receipt = $client->sendSwitchGradient(800, 2000);
        $this->assertSame(['sentAt' => self::NOW + 1500, 'acceptedAt' => self::NOW + 1520], $receipt);
        $this->assertCount(3, $requests);
        $this->assertSame(self::ENDPOINT.'/v2.0/cloud/thing/'.self::DEVICE.'/model', $requests[1]->url());
        $write = $requests[2];
        $path = '/v2.0/cloud/thing/'.self::DEVICE.'/shadow/properties/issue';
        $this->assertSame('POST', $write->method());
        $this->assertSame(self::ENDPOINT.$path, $write->url());
        $body = json_decode($write->body(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(['properties'], array_keys($body));
        $this->assertIsString($body['properties']);
        $value = json_decode($body['properties'], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(['switch_gradient'], array_keys($value));
        $bytes = base64_decode($value['switch_gradient'], true);
        $this->assertSame('000003200007d0', bin2hex($bytes));
        $this->assertSame(7, strlen($bytes));
        $signed = 'POST'."\n".hash('sha256', $write->body())."\n\n".$path;
        $this->assertSame(strtoupper(hash_hmac('sha256', self::CLIENT.self::TOKEN.(self::NOW + 1500).self::NONCE.$signed, self::SECRET)), $write->header('sign')[0]);
        // A second intentional setting/restoration reuses the validated model.
        $client->sendSwitchGradient(0, 60000);
        $this->assertCount(4, $requests);
        $second = json_decode(json_decode($requests[3]->body(), true)['properties'], true);
        $this->assertSame('0000000000ea60', bin2hex(base64_decode($second['switch_gradient'], true)));
    }

    public function test_invalid_native_gradient_durations_are_rejected_without_even_reading_model(): void
    {
        Http::fake();
        foreach ([[-1, 0], [0, 60001], [60001, 0], [0, -1], ['800', 2000], [800, 2000.5], [true, 0], [null, 0]] as [$on, $off]) {
            $this->assertSame('configuration_error', $this->failure(fn () => $this->client()->sendSwitchGradient($on, $off))->getMessage());
        }
        Http::assertNothingSent();
    }

    public static function invalidNativeProperties(): array
    {
        return [
            'read only' => [['accessMode' => 'ro']],
            'wrong id' => [['abilityId' => 34]],
            'wrong code' => [['code' => 'another_property']],
            'wrong type' => [['typeSpec' => ['type' => 'string', 'maxlen' => 255]]],
            'too short' => [['typeSpec' => ['type' => 'raw', 'maxlen' => 6]]],
            'invalid maximum type' => [['typeSpec' => ['type' => 'raw', 'maxlen' => '255']]],
        ];
    }

    #[DataProvider('invalidNativeProperties')]
    public function test_native_gradient_requires_the_exact_writable_raw_model_property_before_post(array $property): void
    {
        Http::fake([
            self::ENDPOINT.'/v1.0/token?grant_type=1' => Http::response($this->token()),
            self::ENDPOINT.'/v2.0/cloud/thing/'.self::DEVICE.'/model' => Http::response([
                'success' => true, 'result' => ['model' => json_encode($this->nativeModel($property))],
            ]),
        ]);
        $error = $this->failure(fn () => $this->client()->sendSwitchGradient(800, 2000));
        $this->assertSame('configuration_error', $error->getMessage());
        $this->assertFalse($error->writeOutcomeUnknown);
        Http::assertSentCount(2);
        Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
    }

    public function test_missing_ambiguous_namespaced_and_malformed_native_models_never_write(): void
    {
        $missing = $this->nativeModel();
        $missing['services'][0]['properties'] = [];
        $duplicate = $this->nativeModel();
        $duplicate['services'][0]['properties'][] = $duplicate['services'][0]['properties'][0];
        $namespaced = $this->nativeModel();
        $namespaced['services'][0]['code'] = 'another_service';
        foreach ([$missing, $duplicate, $namespaced, ['services' => 'invalid'], null] as $model) {
            Http::fake([
                self::ENDPOINT.'/v1.0/token?grant_type=1' => Http::response($this->token()),
                self::ENDPOINT.'/v2.0/cloud/thing/'.self::DEVICE.'/model' => Http::response([
                    'success' => true, 'result' => ['model' => json_encode($model)],
                ]),
            ]);
            $this->assertSame('configuration_error', $this->failure(fn () => $this->client()->sendSwitchGradient(800, 2000))->getMessage());
            Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
        }
    }

    #[DataProvider('nativeWriteFailures')]
    public function test_native_issue_write_uncertainty_never_retries_or_exposes_payloads(string $failure): void
    {
        $writes = 0;
        Http::fake(function (Request $request) use (&$writes, $failure) {
            if (str_contains($request->url(), '/token?')) {
                return Http::response($this->token());
            }
            if (str_ends_with($request->url(), '/model')) {
                return Http::response(['success' => true, 'result' => ['model' => json_encode($this->nativeModel())]]);
            }
            $writes++;
            if ($failure === 'timeout') {
                throw new ConnectionException(self::SECRET.self::TOKEN);
            }

            return Http::response($failure === 'expired' ? ['success' => false, 'code' => '1010', 'msg' => self::SECRET]
                : ['success' => true, 'result' => self::SECRET]);
        });
        $error = $this->failure(fn () => $this->client()->sendSwitchGradient(800, 2000));
        $this->assertSame(1, $writes);
        $this->assertTrue($error->writeOutcomeUnknown);
        $this->assertNull($error->getPrevious());
        $this->assertSame($failure === 'expired' ? 'configuration_error' : 'transport_timeout', $error->getMessage());
        $this->assertStringNotContainsString(self::SECRET, $error->getMessage());
    }

    public static function nativeWriteFailures(): array
    {
        return [['timeout'], ['expired'], ['unexpected result']];
    }

    public function test_failed_model_refresh_discards_previously_usable_schema_cache(): void
    {
        $models = 0;
        Http::fake(function (Request $request) use (&$models) {
            if (str_contains($request->url(), '/token?')) {
                return Http::response($this->token());
            }
            $models++;

            return Http::response(['success' => true, 'result' => ['model' => $models === 1 ? json_encode($this->nativeModel()) : '{invalid']]);
        });
        $client = $this->client();
        $client->readModel();
        $this->failure(fn () => $client->readModel());
        $this->assertSame('configuration_error', $this->failure(fn () => $client->sendSwitchGradient(800, 2000))->getMessage());
        $this->assertSame(3, $models);
        Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
    }
}
