<?php

declare(strict_types=1);

namespace PhpHttpPlugin;

use Http\Client\Common\Plugin;
use Http\Client\Exception\NetworkException;
use Http\Promise\Promise;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriFactoryInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

use function array_rand;
use function array_values;
use function explode;

final class SetRandomHostPlugin implements Plugin
{
    private UriFactoryInterface $uriFactory;
    /** @var list<string> */
    private array $hosts;
    private int $currentHostIndex;

    /**
     * @param array{hosts: string} $config
     */
    public function __construct(UriFactoryInterface $uriFactory, array $config)
    {
        $this->uriFactory = $uriFactory;
        $resolver = new OptionsResolver();
        $resolver->setDefined('hosts');
        $resolver->setAllowedTypes('hosts', 'string');
        $hosts = $resolver->resolve($config)['hosts'];

        $this->hosts = explode(',', $hosts);
        $this->currentHostIndex = array_rand($this->hosts);
    }

    public function handleRequest(RequestInterface $request, callable $next, callable $first): Promise
    {
        $uri = $this->uriFactory->createUri($this->hosts[$this->currentHostIndex]);

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
                if ($exception instanceof NetworkException) {
                    $this->swapCurrentHost();
                }

                throw $exception;
            },
        );
    }

    private function swapCurrentHost(): void
    {
        $oldHost = $this->hosts[$this->currentHostIndex];
        unset($this->hosts[$this->currentHostIndex]); // Making sure it's impossible to select same host 2 times in a row
        $this->hosts = array_values($this->hosts); // Making sure $hosts is a list to avoid gaps and issues with internal array pointer
        $this->currentHostIndex = $this->hosts ? array_rand($this->hosts) : 0;
        $this->hosts[] = $oldHost;
    }
}
