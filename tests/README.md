# Tests

This plugin uses PHPUnit, installed via Composer.

```bash
composer install
composer test
```

The suite runs against a lightweight WordPress shim, so it needs no database and
no full WordPress core test installation.

Files:

- `phpunit.xml.dist` PHPUnit configuration
- `tests/bootstrap.php` lightweight WordPress shim and plugin includes
- `tests/*Test.php` test cases

Current coverage focuses on:

- conditional visibility logic
- wizard step navigation
- field validation
- submission payload flattening
- shared settings defaults and sanitization

If you later want full WordPress integration tests, the next step would be adding the official WordPress PHPUnit test suite alongside or instead of this lightweight setup.
