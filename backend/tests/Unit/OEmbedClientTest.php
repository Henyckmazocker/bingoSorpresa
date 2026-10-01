<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Infrastructure\Youtube\OEmbedClient;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/** oEmbed de YouTube con un handler mock de Guzzle: ni red ni YouTube en los tests. */
class OEmbedClientTest extends TestCase
{
    private array $history = [];

    private function client(Response|\Throwable ...$responses): OEmbedClient
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));
        return new OEmbedClient(new Client(['handler' => $stack]));
    }

    public function test200DaTituloYCanalYPideLaUrlDelPlan(): void
    {
        $body = json_encode(['title' => 'Never Gonna Give You Up', 'author_name' => 'Rick Astley', 'type' => 'video']);
        $info = $this->client(new Response(200, ['Content-Type' => 'application/json'], $body))->lookup('dQw4w9WgXcQ');

        $this->assertSame(['reason' => null, 'title' => 'Never Gonna Give You Up', 'authorName' => 'Rick Astley'], $info);

        $req = $this->history[0]['request'];
        $this->assertSame('GET', $req->getMethod());
        $this->assertSame('https://www.youtube.com/oembed', (string) $req->getUri()->withQuery(''));
        parse_str($req->getUri()->getQuery(), $q);
        $this->assertSame(['url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'format' => 'json'], $q);
        $this->assertSame(5, $this->history[0]['options']['timeout']);
    }

    public function test401Y403SonNoIncrustables(): void
    {
        $c = $this->client(new Response(401, [], 'Unauthorized'), new Response(403, [], 'Forbidden'));
        $this->assertSame(['reason' => 'not_embeddable'], $c->lookup('5f-JlzBuUUU'));
        $this->assertSame(['reason' => 'not_embeddable'], $c->lookup('5f-JlzBuUUU'));
    }

    public function test404Y400SonNoEncontrados(): void
    {
        $c = $this->client(new Response(404, [], 'Not Found'), new Response(400, [], 'Bad Request'));
        $this->assertSame(['reason' => 'not_found'], $c->lookup('abcdefghijk'));
        $this->assertSame(['reason' => 'not_found'], $c->lookup('aaaaaaaaaaa'));
    }

    public function testErrorDeRedOJsonRotoSonNoDisponibles(): void
    {
        $c = $this->client(
            new ConnectException('timeout', new Request('GET', 'https://www.youtube.com/oembed')),
            new Response(500),
            new Response(200, [], 'no es json')
        );
        $this->assertSame(['reason' => 'unavailable'], $c->lookup('dQw4w9WgXcQ'));
        $this->assertSame(['reason' => 'unavailable'], $c->lookup('dQw4w9WgXcQ'));
        $this->assertSame(['reason' => 'unavailable'], $c->lookup('dQw4w9WgXcQ'));
    }
}
