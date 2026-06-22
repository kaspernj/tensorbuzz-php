# tensorbuzz-php

PHP client for [TensorBuzz](https://tensorbuzz.com) error reporting. The PHP
counterpart of the JavaScript [`tensorbuzz-api`](https://www.npmjs.com/package/tensorbuzz-api)
package: capture your application's exceptions and fatal errors and report them to
TensorBuzz.

- Works on **PHP 5.6+** (incl. legacy apps).
- No runtime dependencies beyond `ext-curl` and `ext-json`.
- Sensitive values (passwords, tokens, secrets…) are redacted before sending.
- Fail-safe: a reporting/network failure is logged and swallowed — it never
  crashes your application.

## Installation

```bash
composer require tensorbuzz/tensorbuzz-php
```

## Quick start

Capture uncaught exceptions and fatal errors automatically:

```php
use Tensorbuzz\BugReporting;

$bugReporting = new BugReporting(getenv('TENSORBUZZ_BUG_REPORT_AUTH_TOKEN'));

$bugReporting->collectEnvironment(function () {
    return array(
        'application' => 'my-php-app',
        'environment' => getenv('APP_ENV'),
        'phpVersion' => PHP_VERSION,
    );
});

$bugReporting->connect();
```

`connect()` registers a `set_exception_handler` (uncaught exceptions) and a
shutdown handler (fatal errors). It does **not** report warnings/notices by
default; call `$bugReporting->connectErrorHandler()` if you also want those
reported as `ErrorException`s.

## Reporting manually

From a `try/catch` or a framework's exception handler:

```php
try {
    doRiskyThing();
} catch (\Throwable $e) { // \Exception on PHP 5.6
    $bugReporting->report($e, array(
        'parameters' => array('orderId' => $orderId),
    ));

    throw $e;
}
```

`report()` returns the decoded TensorBuzz response (with a `url` to the created
bug report) or `null` if reporting failed or the account's bug-report quota is
exhausted.

## Configuration

```php
new BugReporting($authToken, array(
    'hostname' => 'app.example.com',   // for domain-restricted tokens
    'postUrl' => 'https://server.tensorbuzz.com/errors/reports', // default
    'runtimeEnvironment' => 'php',     // default
    'timeoutSeconds' => 5,             // cURL transport timeout
    'transport' => $customTransport,   // any Tensorbuzz\Transport\TransportInterface
));
```

- `collectEnvironment(callable)` / `collectParams(callable)` — register callbacks
  returning associative arrays merged into every report's `environment` /
  `parameters`. Per-report `parameters`/`environment` can also be passed to
  `report()`.
- `url`, `user_agent`, and `hostname` are auto-filled from `$_SERVER` when
  available, and can be overridden per report.

## Redaction

All `parameters` and `environment` data goes through `Tensorbuzz\PayloadSanitizer`
before being sent. It redacts keys matching
`authorization|contentBase64|password|secret|sessionToken|token` (case-insensitive),
truncates long strings, caps array sizes, summarizes binary/resource values
without their bytes, guards against circular references, and compacts the payload
if it is still too large. You can also call it directly:

```php
use Tensorbuzz\PayloadSanitizer;

$safe = PayloadSanitizer::sanitize($requestData);
```

## Framework notes

- **Plain PHP**: call `connect()` once during bootstrap.
- **Laravel**: in `bootstrap/app.php`'s exception handler (or `App\Exceptions\Handler::report()`),
  call `$bugReporting->report($e)`.
- **Symfony**: subscribe to the `kernel.exception` event and call
  `$bugReporting->report($event->getThrowable())`.

## Custom transport

Implement `Tensorbuzz\Transport\TransportInterface` to send via a PSR-18 client,
a queue, etc.:

```php
use Tensorbuzz\Transport\TransportInterface;

class MyTransport implements TransportInterface
{
    public function send($postUrl, array $postData)
    {
        // POST json_encode($postData) with Content-Type: application/json.
        // Return the response body, or null on failure (never throw).
    }
}
```

## Development

```bash
composer install
composer test      # phpunit
composer phpcs     # PHP 5.6+ compatibility check (PHPCompatibility)
```

## License

MIT
