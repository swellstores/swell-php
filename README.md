## Swell API library for PHP

Build and scale ecommerce with Swell.

## Example

```php
require_once("/path/to/swell-php/lib/Swell.php");

$client = new Swell\Client('my-store', 'secret-key');

$products = $client->get('/products', [
	'category' => 't-shirts'
]);

print_r($products);
```

or with [Composer](https://getcomposer.org/doc/05-repositories.md#vcs)

__composer.json__
```json
"require": {
	"swellstores/swell-php" : "dev-master"
},
 "repositories": [
	{
		"type" : "vcs",
		"url"  : "git@github.com:swellstores/swell-php.git"
	}
]
```

Then run `composer update` to download and install the library

```php
require __DIR__ . '/vendor/autoload.php';

$client = new Swell\Client('my-store', 'secret-key');

$products = $client->get('/products', [
	'category' => 't-shirts'
]);

print_r($products);

```




## Documentation

Coming soon!

## Contributing

Pull requests are welcome

## License

MIT
