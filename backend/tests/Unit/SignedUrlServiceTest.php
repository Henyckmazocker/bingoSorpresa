<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Infrastructure\Auth\SignedUrlService;
use PHPUnit\Framework\TestCase;

class SignedUrlServiceTest extends TestCase
{
    protected function setUp(): void
    {
        $_ENV['JWT_SECRET'] = 'test-secret-at-least-32-characters-long!!';
        $_ENV['STREAM_URL_TTL'] = '3600';
    }

    public function test_sign_then_verify_round_trips(): void
    {
        $svc = new SignedUrlService();
        $token = $svc->sign('stream', 42, 7);
        $payload = $svc->verify($token);

        $this->assertNotNull($payload);
        $this->assertSame('stream', $payload['k']);
        $this->assertSame(42, $payload['id']);
        $this->assertSame(7, $payload['uid']);
    }

    public function test_tampered_token_is_rejected(): void
    {
        $svc = new SignedUrlService();
        $token = $svc->sign('stream', 42, 7);
        $tampered = $token . 'x';

        $this->assertNull($svc->verify($tampered));
    }

    public function test_expired_token_is_rejected(): void
    {
        $svc = new SignedUrlService();
        $token = $svc->sign('stream', 1, 1, -10); // already expired

        $this->assertNull($svc->verify($token));
    }

    public function test_wrong_secret_does_not_verify(): void
    {
        $svc = new SignedUrlService();
        $token = $svc->sign('cover', 5, 3);

        $_ENV['JWT_SECRET'] = 'a-completely-different-secret-32-characters';
        $other = new SignedUrlService();

        $this->assertNull($other->verify($token));
    }

    public function test_stream_url_is_relative_and_carries_token(): void
    {
        $svc = new SignedUrlService();
        $url = $svc->streamUrl(99, 1);

        $this->assertStringStartsWith('/stream.php?token=', $url);
    }
}
