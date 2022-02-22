<?php

declare(strict_types=1);

namespace PhpHttpPlugin;

use Closure;
use Http\Client\Common\Plugin\RetryPlugin;
use Http\Client\Common\PluginClient;
use Http\Client\Exception\NetworkException;
use Http\Client\Exception\RequestException;
use Http\Mock\Client;
use Nyholm\Psr7\Factory\HttplugFactory;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;

class SetRandomHostPluginTest extends TestCase
{
    private Client $mockClient;

    protected function setUp(): void
    {
        $this->mockClient = new Client(new HttplugFactory());
    }

    public function testUriReplacements(): void
    {
        $this->createClient(['hosts' => ['https://foo.bar.baz:800']])->sendRequest(new Request('PUT', '/foo'));

        $uri = $this->mockClient->getLastRequest()->getUri();
        $this->assertSame('https', $uri->getScheme());
        $this->assertSame('foo.bar.baz', $uri->getHost());
        $this->assertSame(800, $uri->getPort());
        $this->assertSame('/foo', $uri->getPath());
    }

    public function testStickiness(): void
    {
        $client = $this->createClient(['hosts' => ['http://foo.bar.baz', 'https://bar.baz.foo']]);

        $client->sendRequest(new Request('GET', '/foo'));
        $client->sendRequest(new Request('GET', '/bar'));
        $requests = $this->mockClient->getRequests();
        $this->assertCount(2, $requests);
        $this->assertEquals(
            $requests[0]->getUri()->getHost(),
            $requests[1]->getUri()->getHost(),
            'Host has been changed between (successful) requests',
        );
    }

    public function testSwapAttemptWithSingleHostIsErrorFree(): void
    {
        $this->mockClient->addResponse(new Response(500));

        $this->createClient(['hosts' => ['http://foo.bar.baz']], true)->sendRequest(new Request('GET', '/foo'));
        $requests = $this->mockClient->getRequests();
        $this->assertCount(2, $requests);
        $this->assertEquals($requests[0]->getUri(), $requests[1]->getUri());
    }

    public function testNoSwapOnRequestExceptions(): void
    {
        $request = new Request('GET', '/foo');
        $this->mockClient->addException(new RequestException('foo', $request));

        $this->createClient(['hosts' => ['http://f.com', 'https://b.com,https://c.com']], true)->sendRequest($request);
        $requests = $this->mockClient->getRequests();
        $this->assertCount(2, $requests);
        $this->assertEquals($requests[0]->getUri(), $requests[1]->getUri());
    }

    /**
     * @dataProvider errorProvider
     */
    public function testSwappingWithMultipleHosts(Closure $errorProvider): void
    {
        $errorProvider($this->mockClient);

        $this->createClient(['hosts' => ['http://f.com', 'https://b.com']], true)->sendRequest(new Request('GET', '/'));

        $requests = $this->mockClient->getRequests();
        $this->assertCount(2, $requests);
        $this->assertNotEquals($requests[0]->getUri()->getHost(), $requests[1]->getUri()->getHost());
    }

    public function testItDoesNotCareAboutIndices(): void
    {
        $this->expectNotToPerformAssertions();
        $this->createClient(['hosts' => ['foo' => 'https://foo.com']]);
    }

    public function testThrowsExceptionWithoutHostsKey(): void
    {
        $this->expectExceptionMessage('The required option "hosts" is missing.');
        $this->createClient([]);
    }

    public function testThrowsExceptionWithoutArray(): void
    {
        $this->expectExceptionMessage('https://foo.com" is expected to be of type "string[]", but is of type "string"');
        $this->createClient(['hosts' => 'https://foo.com']);
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

    /** @param array{hosts?: string} $config */
    private function createClient(array $config, bool $shouldRetry = false): ClientInterface
    {
        $plugins = $shouldRetry ? [new RetryPlugin(['error_response_delay' => fn () => 0])] : [];

        return new PluginClient($this->mockClient, [...$plugins, new SetRandomHostPlugin(new Psr17Factory(), $config)]);
    }
}
