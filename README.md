# Drupal Backend Auth API

Drupal 11 backend project for a separate TypeScript frontend. The custom implementation is split by responsibility:

- `auth_service`: account registration, credential checks, JWT creation/validation, password reset token storage, and reset email sending.
- `auth_api`: JSON controllers, routes, CORS handling, Swagger UI, and OpenAPI contract.

## Architecture

The backend follows a small service/API split so the domain logic is not coupled to HTTP controllers.

```text
backend/
  composer.json
  web/
    index.php
    core/
    sites/default/
      settings.php
      settings.local.php
      files/.ht.sqlite
    modules/custom/
      auth_service/
        auth_service.info.yml
        auth_service.install
        auth_service.module
        auth_service.services.yml
        src/Services/AuthenticationService.php
      auth_api/
        auth_api.info.yml
        auth_api.routing.yml
        auth_api.services.yml
        openapi.yml
        src/Controller/AuthApiController.php
        src/Controller/UserApiController.php
        src/Controller/DocsController.php
        src/EventSubscriber/CorsSubscriber.php
```

### Module Responsibilities

| Layer | Files | Responsibility |
| --- | --- | --- |
| Domain service | `auth_service/src/Services/AuthenticationService.php` | Owns registration, login, JWT issue/verify, password reset token storage, notification-service integration, and public user response shaping. |
| Install seed | `auth_service/auth_service.install` | Creates or refreshes the local demo user `demo@example.com`. |
| Mail hook | `auth_service/auth_service.module` | Builds password reset email content. |
| HTTP API | `auth_api/src/Controller/AuthApiController.php` | Handles public JSON endpoints for register, login, forgot password, and reset password. |
| Protected API | `auth_api/src/Controller/UserApiController.php` | Handles JWT-authenticated user endpoints such as `/api/user/me`. |
| API docs | `auth_api/src/Controller/DocsController.php` and `auth_api/openapi.yml` | Serves Swagger UI and the OpenAPI contract. |
| CORS | `auth_api/src/EventSubscriber/CorsSubscriber.php` | Allows the Vite frontend origin to call the Drupal JSON API. |

### Request Flow

```text
Frontend web component
  -> fetch JSON request
  -> auth_api route/controller
  -> auth_service.authentication service
  -> Drupal user entity storage / expirable key-value store
  -> notification-service email API for password reset messages
  -> JSON response with JWT or user data
```

JWTs are signed with HS256. For this local demo runtime, the secret is configured in `web/sites/default/settings.local.php`; production should use a real secret from environment-managed configuration.

## Swagger and API Docs

When the backend server is running, open:

```text
Swagger UI:   http://127.0.0.1:8088/api/docs
OpenAPI YAML: http://127.0.0.1:8088/api/docs/openapi.yml
```

Swagger can be used to inspect every auth endpoint and test requests manually. For protected endpoints, authorize with:

```text
Bearer <accessToken from /api/auth/login>
```

## Install

Prerequisites:

- PHP 8.3 or newer for Drupal 11.
- PHP `gd` extension enabled in the CLI and web SAPIs.
- Composer 2.x.

This workspace also includes a local portable PHP runtime at `.tools/php-8.5`, which avoids the older XAMPP PHP runtime.

```powershell
Set-Location C:\xampp\htdocs\drupal\backend
composer install
```

Install Drupal 11 normally with the document root pointed at `backend/web`. After Drupal is installed, enable the custom modules:

```powershell
vendor\bin\drush en auth_service auth_api -y
vendor\bin\drush cr
```

Enabling `auth_service` seeds a local demo account:

```text
Email or username: demo@example.com
Password: DemoPassword123!
```

If the module was already enabled before this seed was added, run updates once:

```powershell
vendor\bin\drush updb -y
vendor\bin\drush cr
```

If Drush is not installed, enable `Auth Service` and `Auth API` from Drupal's Extend page and clear caches from the admin UI.

## Run Without XAMPP

From the workspace root, dependencies and Drupal have been installed with portable PHP and SQLite. Start the backend with:

```powershell
Set-Location C:\xampp\htdocs\drupal\backend\web
..\..\.tools\php-8.5\php.exe -S 127.0.0.1:8088 .ht.router.php
```

API base URL:

```text
http://127.0.0.1:8088
```

## Required Settings

Add these values to `web/sites/default/settings.php` or `settings.local.php`. Keep the JWT secret outside version control.

```php
$settings['auth_api_jwt_secret'] = getenv('AUTH_JWT_SECRET') ?: 'replace-with-at-least-32-random-characters';
$settings['auth_api_jwt_issuer'] = getenv('AUTH_JWT_ISSUER') ?: 'drupal-auth-api';
$settings['auth_api_jwt_ttl'] = (int) (getenv('AUTH_JWT_TTL') ?: 3600);
$settings['auth_api_frontend_reset_url'] = getenv('AUTH_FRONTEND_RESET_URL') ?: 'http://localhost:5173/reset-password.html';
$settings['auth_api_expose_reset_token'] = (bool) (getenv('AUTH_EXPOSE_RESET_TOKEN') ?: false);
$settings['auth_api_notification_service_url'] = getenv('AUTH_NOTIFICATION_SERVICE_URL') ?: 'http://127.0.0.1:8090/api/notifications/email';
$settings['auth_api_notification_service_key'] = getenv('AUTH_NOTIFICATION_SERVICE_KEY') ?: 'replace-with-notification-service-api-key';

$origins = getenv('AUTH_CORS_ALLOWED_ORIGINS');
$settings['auth_api_cors_allowed_origins'] = $origins
  ? array_map('trim', explode(',', $origins))
  : ['http://localhost:5173', 'http://127.0.0.1:5173'];
```

For local frontend testing, set `auth_api_expose_reset_token` to `true` only if email delivery is not configured. Leave it disabled outside local development.

Password reset email is sent to the standalone notification service first. The service expects the `X-Notification-Key` header to match its `notification_service_api_key` setting. If the notification service is down or reports a failed send, the backend falls back to its local Drupal mail hook.

## API Endpoints

| Method | Path | Auth | Purpose |
| --- | --- | --- | --- |
| `POST` | `/api/auth/register` | Public | Create an active Drupal user and return a JWT session. |
| `POST` | `/api/auth/login` | Public | Authenticate by username or email and return a JWT session. |
| `POST` | `/api/auth/forgot-password` | Public | Send password reset instructions. Response is intentionally generic. |
| `POST` | `/api/auth/reset-password` | Public | Update password with `uid`, `token`, and a new password. |
| `GET` | `/api/user/me` | Bearer JWT | Return the current authenticated user. |
| `GET` | `/api/docs` | Public | Swagger UI for the API. |
| `GET` | `/api/docs/openapi.yml` | Public | OpenAPI 3.0 contract. |

## Notification Service Integration

Run the notification service beside the backend when testing password reset delivery:

```powershell
Set-Location C:\xampp\htdocs\drupal\notification-service\web
..\..\.tools\php-8.5\php.exe -S 127.0.0.1:8090 .ht.router.php
```

Useful URLs:

```text
Health:       http://127.0.0.1:8090/api/notifications/health
Swagger UI:   http://127.0.0.1:8090/api/docs
OpenAPI YAML: http://127.0.0.1:8090/api/docs/openapi.yml
```

The local PHP runtime may record notification attempts as `failed` until a real mail transport such as SMTP, SES, SendGrid, or Mailgun is configured in Drupal.

## Example Requests

Register:

```powershell
$base = 'http://127.0.0.1:8088'
Invoke-RestMethod "$base/api/auth/register" -Method Post -ContentType 'application/json' -Body (@{
  name = 'Ada Lovelace'
  email = 'ada@example.com'
  password = 'DemoPassword123!'
} | ConvertTo-Json)
```

Login and call the dashboard API:

```powershell
$session = Invoke-RestMethod "$base/api/auth/login" -Method Post -ContentType 'application/json' -Body (@{
  identifier = 'demo@example.com'
  password = 'DemoPassword123!'
} | ConvertTo-Json)

Invoke-RestMethod "$base/api/user/me" -Headers @{ Authorization = "Bearer $($session.accessToken)" }
```

Forgot password:

```powershell
Invoke-RestMethod "$base/api/auth/forgot-password" -Method Post -ContentType 'application/json' -Body (@{
  email = 'ada@example.com'
} | ConvertTo-Json)
```

Reset password:

```powershell
Invoke-RestMethod "$base/api/auth/reset-password" -Method Post -ContentType 'application/json' -Body (@{
  uid = 2
  token = 'token-from-email-or-local-dev-response'
  password = 'new-correct-horse'
} | ConvertTo-Json)
```

## Security Notes

- Use HTTPS before sending JWTs over a network.
- Use a high-entropy JWT secret of at least 32 characters.
- Configure real Drupal mail delivery for password reset in shared environments.
- The local reset-token exposure setting is intentionally opt-in and should stay off outside local development.
- JWTs are stateless. To revoke issued tokens before expiry, reduce TTLs or add a token denylist store.
