# Culprit for Laravel

Report exceptions from your Laravel 12 or 13 app to [Culprit](https://useculprit.com).

## Installation

1. Install the package. It isn't on Packagist yet, so add the GitHub repository to your app's `composer.json` first:

   ```json
   "repositories": [
       { "type": "vcs", "url": "https://github.com/TheAaronLawrence/laravel-culprit" }
   ]
   ```

   ```bash
   composer require theaaronlawrence/laravel-culprit:dev-main
   ```

2. Add `Culprit::handles($exceptions);` inside `withExceptions` in `bootstrap/app.php`:

   ```php
   use TheAaronLawrence\Culprit\Facades\Culprit;

   ->withExceptions(function (Exceptions $exceptions): void {
       Culprit::handles($exceptions);
   })
   ```

   Laravel still logs exceptions as usual.

3. Add your project's key to `.env`. `CULPRIT_URL` is only needed to point somewhere other than the default endpoint.

   ```dotenv
   CULPRIT_KEY=your-project-key
   ```

4. Run `php artisan culprit:test` and see the error arrive in Culprit.

With no `CULPRIT_KEY` (or an empty `CULPRIT_URL`), nothing is reported and no HTTP calls are made.

### Older app structure

Apps upgraded from before Laravel 11 may still have `app/Exceptions/Handler.php` and `app/Http/Kernel.php`. They have no `withExceptions`.

In `Handler::register()`, call `handles()` with a new `Exceptions` object:

```php
use Illuminate\Foundation\Configuration\Exceptions;
use TheAaronLawrence\Culprit\Facades\Culprit;

public function register(): void
{
    Culprit::handles(new Exceptions($this));
}
```

HTTP errors are sent after the response, using deferred callbacks. Check that `\Illuminate\Foundation\Http\Middleware\InvokeDeferredCallbacks::class` is in the global `$middleware` list in `app/Http/Kernel.php`. Add it if it is missing. Without it, HTTP errors are never sent, even though `culprit:test` works.

## Configuration

Publish the config file with `php artisan vendor:publish --tag=culprit-config` if you need to change the redaction lists.

| Env key | Default | What it does |
| --- | --- | --- |
| `CULPRIT_KEY` | none | The project key, sent as a bearer token. Required: nothing is sent without it. |
| `CULPRIT_URL` | `http://useculprit.test/api/errors` | The ingest endpoint. Nothing is sent when it is empty. |
| `CULPRIT_SEND_IP` | `false` | Send the client IP address with HTTP context. |
| `CULPRIT_SEND_USER_DETAILS` | `false` | Send the authenticated user's name and email (the ID is always sent). |
| `CULPRIT_STRIP_QUERY_BINDINGS` | `true` | Remove bound values from `QueryException` messages. |
| `CULPRIT_VENDOR_SOURCE` | `false` | Send source snippets for vendor frames as well as your own code. |

## How it behaves

Reporting never breaks your app: every failure is swallowed, HTTP requests are sent after the response has gone out, the same error is sent once per 10 seconds, and 413 and 429 responses pause sending.

Every report carries an `X-Culprit-Report` header, and an error raised while handling a request with that header is never reported. That lets a Culprit tracker report its own errors without looping when its ingest endpoint fails.

## License

MIT.
