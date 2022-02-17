<?php

declare(strict_types=1);

namespace Ostrolucky\RandomHostHttplugPlugin;

use Closure;
use Http\Client\Common\Plugin\RetryPlugin;
use Http\Client\Common\PluginClient;
use Http\Client\Exception\NetworkException;
use Http\Client\Exception\RequestException;
use Http\Mock\Client;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;

class RandomHostPluginTest extends TestCase
{
    public function testUriReplacements(): void
    {
        $mockClient = new Client();

        $client = new PluginClient(
            $mockClient,
            [new RandomHostPlugin(new Psr17Factory(), ['hosts' => 'https://foo.bar.baz:800'])],
        );

        $client->sendRequest(new Request('PUT', '/foo'));

        $uri = $mockClient->getLastRequest()->getUri();
        $this->assertSame('https', $uri->getScheme());
        $this->assertSame('foo.bar.baz', $uri->getHost());
        $this->assertSame(800, $uri->getPort());
        $this->assertSame('/foo', $uri->getPath());
    }

    public function testStickiness(): void
    {
        $mockClient = new Client();

        $client = new PluginClient(
            $mockClient,
            [new RandomHostPlugin(new Psr17Factory(), ['hosts' => 'http://foo.bar.baz,https://bar.baz.foo'])],
        );

        $client->sendRequest(new Request('GET', '/foo'));
        $client->sendRequest(new Request('GET', '/bar'));
        $requests = $mockClient->getRequests();
        $this->assertCount(2, $requests);
        $this->assertEquals(
            $requests[0]->getUri()->getHost(),
            $requests[1]->getUri()->getHost(),
            'Host has been changed between (successful) requests',
        );
    }

    public function testSwapAttemptWithSingleHostIsErrorFree(): void
    {
        $mockClient = new Client();
        $mockClient->addResponse(new Response(500));

        $client = new PluginClient(
            $mockClient,
            [
                new RetryPlugin(['error_response_delay' => fn () => 0]),
                new RandomHostPlugin(new Psr17Factory(), ['hosts' => 'http://foo.bar.baz']),
            ],
        );

        $request = new Request('GET', '/foo');
        $client->sendRequest($request);
        $requests = $mockClient->getRequests();
        $this->assertCount(2, $requests);
        $this->assertEquals($requests[0]->getUri(), $requests[1]->getUri());
    }

    public function testNoSwapOnRequestExceptions(): void
    {
        $request = new Request('GET', '/foo');
        $mockClient = new Client();
        $mockClient->addException(new RequestException('foo', $request));

        $client = new PluginClient(
            $mockClient,
            [
                new RetryPlugin(['error_response_delay' => fn () => 0]),
                new RandomHostPlugin(new Psr17Factory(), ['hosts' => 'http://foo.com,https://bar.com,https://baz.com']),
            ],
        );

        $client->sendRequest($request);
        $requests = $mockClient->getRequests();
        $this->assertCount(2, $requests);
        $this->assertEquals($requests[0]->getUri(), $requests[1]->getUri());
    }

    /**
     * @dataProvider errorProvider
     */
    public function testSwappingWithMultipleHosts(Closure $errorProvider): void
    {
        $mockClient = new Client();
        $errorProvider($mockClient);

        $client = new PluginClient(
            $mockClient,
            [
                new RetryPlugin(['error_response_delay' => fn () => 0]),
                new RandomHostPlugin(new Psr17Factory(), ['hosts' => 'http://foo.com,https://bar.com,https://baz.com']),
            ],
        );

        $request = new Request('GET', '/foo');
        $client->sendRequest($request);
        $requests = $mockClient->getRequests();
        $this->assertCount(2, $requests);
        $this->assertNotEquals($requests[0]->getUri()->getHost(), $requests[1]->getUri()->getHost());
    }

    /**
     * @return callable[][]
     */
    public function errorProvider(): array
    {
        return [
            [fn (Client $client) => $client->addResponse(new Response(500))],
            [fn (Client $client) => $client->addException(new NetworkException('Foo', new Request('GET', '')))],
        ];
    }
}
