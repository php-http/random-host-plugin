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
use Symfony\Component\OptionsResolver\Exception\InvalidOptionsException;
use Symfony\Component\OptionsResolver\OptionsResolver;

use function array_rand;
use function array_values;
use function sprintf;

final class SetRandomHostPlugin implements Plugin
{
    /**
     * A list of base URLs
     *
     * Only protocol, host and port are used. Paths should not be set and are ignored.
     *
     * @var list<string>
     */
    private array $hosts = [];
    private int $currentHostIndex;

    /**
     * @param array{hosts: non-empty-array<string>} $config
     */
    public function __construct(UriFactoryInterface $uriFactory, array $config)
    {
        $resolver = new OptionsResolver();
        $resolver->setRequired('hosts');
        $resolver->setAllowedTypes('hosts', 'string[]');

        if (!$hosts = $resolver->resolve($config)['hosts']) {
            throw new InvalidOptionsException('List of hosts must not be empty');
        }

        foreach ($hosts as $host) {
            $this->hosts[] = $uri = $uriFactory->createUri($host);
            if (!$uri->getHost()) {
                throw new InvalidOptionsException(
                    sprintf('URL "%s" is not valid (doesn\'t contain host or scheme?)', $host),
                );
            }
        }

        $this->currentHostIndex = array_rand($this->hosts);
    }

    public function handleRequest(RequestInterface $request, callable $next, callable $first): Promise
    {
        $uri = $this->hosts[$this->currentHostIndex];

        $request = $request->withUri($request->getUri()
            ->withHost($uri->getHost())
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
