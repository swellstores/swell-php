## Swell API library for PHP

[Swell](https://www.swell.is) is a customizable, API-first platform for powering modern B2C/B2B shopping experiences and marketplaces. Build and connect anything using your favorite technologies, and provide admins with an easy to use dashboard.

## Example

```php
require_once("/path/to/swell-php/lib/Swell.php");

$swell = new Swell\Client('my-store', 'secret-key');

$products = $swell->get('/products', [
  'category' => 't-shirts'
]);

print_r($products);
```

or with [Composer](https://getcomposer.org/doc/05-repositories.md#vcs)

**composer.json**

```json
"require": {
  "swellstores/swell-php": "dev-master"
},
"repositories": [
	{
		"type": "vcs",
		"url": "git@github.com:swellstores/swell-php.git"
	}
]
```

Then run `composer update` to download and install the library

```php
require __DIR__ . '/vendor/autoload.php';

$swell = new Swell\Client('my-store', 'secret-key');

$products = $swell->get('/products', [
  'category' => 't-shirts'
]);

print_r($products);

```

## Caching

This library provides in-memory caching enabled by default, using a version protocol that means you don't have to worry about stale cache. Records that don't change too frequently, such as products, will always return from cache when possible.

To disable caching behavior, use the option `cache: false`.

```php
$swell = new Swell\Client('my-store', 'secret-key', [
	'cache' => false
]);
```

## Documentation

API reference: https://developers.swell.is/backend-api/

Universal JavaScript client for Swell's Frontend API (Swell.js): https://github.com/swellstores/swell-js

## CA certificates

TLS certificate verification uses the bundled Mozilla CA certificate store at
`data/ca-certificates.crt` via `lib/Connection.php`. Keep this file current
before publishing releases that may be used with `verify_cert` enabled.

To update it, download the current PEM bundle from
https://curl.se/docs/caextract.html and replace `data/ca-certificates.crt`,
preserving the filename because it is referenced directly by the client.

## Contributing

Pull requests are welcome

## License

MIT
