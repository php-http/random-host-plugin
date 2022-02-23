<?php

declare(strict_types=1);

namespace Http\Client\Common\Plugin;

use Http\Client\Common\Plugin;
use Http\Promise\Promise;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriFactoryInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

use function array_rand;
use function array_values;

final class SetRandomHostPlugin implements Plugin
{
    private UriFactoryInterface $uriFactory;
    /**
     * A list of hostnames / IP addresses.
     *
     * Only protocol, host and port are used. Paths should not be set and are ignored.
     *
     * @var list<string>
     */
    private array $hosts;
    private int $currentHostIndex;

    /**
     * @param array{hosts: non-empty-array<string>} $config
     */
    public function __construct(UriFactoryInterface $uriFactory, array $config)
    {
        $this->uriFactory = $uriFactory;
        $resolver = new OptionsResolver();
        $resolver->setRequired('hosts');
        $resolver->setAllowedTypes('hosts', 'string[]');
        $resolver->setAllowedValues('hosts', fn (array $hosts) => (bool) $hosts);

        $this->hosts = array_values($resolver->resolve($config)['hosts']);
        $this->currentHostIndex = array_rand($this->hosts);
    }

    public function handleRequest(RequestInterface $request, callable $next, callable $first): Promise
    {
        // would it make sense to cache this? either build all uri in the constructor, or just the one we currently use (in the happy case we will keep using the same)
        // doing in the constructor would have the added benefit that we validate that the host names can be parsed.
        $uri = $this->uriFactory->createUri($this->hosts[$this->currentHostIndex]);

        $request = $request->withUri($request->getUri()
            ->withHost($uri->getHost())
            // what happens if the uri does not specify a scheme or port?
            ->withScheme($uri->getScheme())
            ->withPort($uri->getPort()));

        return $next($request)->then(
            function (ResponseInterface $response) {
                if ($response->getStatusCode() >= 500) {
                    $this->swapCurrentHost();
                }

                return $response;
            },
            function (ClientExceptionInterface $exception) {
                if ($exception instanceof NetworkExceptionInterface) {
                    $this->swapCurrentHost();
                }

                throw $exception;
            },
        );
    }

    private function swapCurrentHost(): void
    {
        $previousHost = $this->hosts[$this->currentHostIndex];
        unset($this->hosts[$this->currentHostIndex]); // Temporarily remove the previous host to be sure to select a different host
        $this->hosts = array_values($this->hosts); // Making sure $hosts is a list to avoid gaps and issues with internal array pointer
        $this->currentHostIndex = $this->hosts ? array_rand($this->hosts) : 0;
        $this->hosts[] = $previousHost;
    }
}
