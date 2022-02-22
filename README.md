#  phphttp-plugin/use-random-host

You might have found yourself in strange situation where instead of people using load balancer,
they supply you list of hosts you should try to call. They also don't like it when you pick one of them
and use that, instead of going through trouble of doing distribution on client side.

If you want to go through this trouble, you can use this HTTPlug plugin.
It picks one of the hosts randomly at beginning and then keeps using it (sticky session), until there is an server
or network error - in that case host that's being used is swapped for something else from the list.
This ensures same host is never used twice in a row in case you use some retry mechanism.

## Install

Via [Composer](https://getcomposer.org/doc/00-intro.md)

```bash
composer require phphttp-plugin/use-random-host
```
## Usage

```php
new \PhpHttpPlugin\SetRandomHostPlugin(
    $psr17Factory,
    ['hosts' => ['https://host1.example','https://host2.example']],
);
```

## Licensing

MIT license. Please see [License File](LICENSE) for more information.
