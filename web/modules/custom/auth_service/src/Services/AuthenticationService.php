<?php

namespace Drupal\auth_service\Services;

use Drupal\Component\Utility\Crypt;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Site\Settings;
use Drupal\user\UserAuthInterface;
use Drupal\user\UserInterface;
use GuzzleHttp\ClientInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Owns account registration, JWT sessions, and password reset behavior.
 */
final class AuthenticationService {

  private const PASSWORD_RESET_TTL = 3600;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly UserAuthInterface $userAuth,
    private readonly TimeInterface $time,
    private readonly Settings $settings,
    private readonly KeyValueExpirableFactoryInterface $keyValueExpirableFactory,
    private readonly MailManagerInterface $mailManager,
    private readonly LanguageManagerInterface $languageManager,
    private readonly RequestStack $requestStack,
    private readonly ClientInterface $httpClient,
  ) {}

  /**
   * Creates an active Drupal user and returns a JWT session payload.
   */
  public function register(array $input): array {
    $email = strtolower(trim((string) ($input['email'] ?? '')));
    $password = (string) ($input['password'] ?? '');
    $name = trim((string) ($input['name'] ?? ''));

    $this->validateEmail($email);
    $this->validatePassword($password);

    if ($this->loadUserByEmail($email) instanceof UserInterface) {
      throw new \InvalidArgumentException('An account with this email already exists.');
    }

    $account = $this->userStorage()->create([
      'name' => $this->createUniqueUsername($name ?: $email),
      'mail' => $email,
      'pass' => $password,
      'status' => 1,
    ]);
    $account->enforceIsNew();
    $account->save();

    return $this->buildSession($account);
  }

  /**
   * Authenticates a username/email plus password and returns a JWT session.
   */
  public function login(string $identifier, string $password): ?array {
    $identifier = trim($identifier);
    if ($identifier === '' || $password === '') {
      return NULL;
    }

    $account = str_contains($identifier, '@')
      ? $this->loadUserByEmail(strtolower($identifier))
      : $this->loadUserByUsername($identifier);

    if (!$account instanceof UserInterface || !$account->isActive()) {
      return NULL;
    }

    $uid = $this->userAuth->authenticate($account->getAccountName(), $password);
    return $uid ? $this->buildSession($account) : NULL;
  }

  /**
   * Sends a password reset email and optionally exposes the token for local dev.
   */
  public function requestPasswordReset(string $email): array {
    $email = strtolower(trim($email));
    $generic = [
      'message' => 'If an active account exists for that email, password reset instructions have been sent.',
    ];

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
      return $generic;
    }

    $account = $this->loadUserByEmail($email);
    if (!$account instanceof UserInterface || !$account->isActive()) {
      return $generic;
    }

    $token = Crypt::randomBytesBase64(48);
    $expires = $this->time->getRequestTime() + self::PASSWORD_RESET_TTL;
    $this->resetStore()->setWithExpire($this->resetKey((int) $account->id()), [
      'hash' => hash('sha256', $token),
      'uid' => (int) $account->id(),
      'expires' => $expires,
    ], self::PASSWORD_RESET_TTL);

    $reset_url = $this->buildFrontendResetUrl((int) $account->id(), $token);
    $this->sendPasswordResetEmail($email, $reset_url);

    if ((bool) $this->settings->get('auth_api_expose_reset_token', FALSE)) {
      $generic['uid'] = (int) $account->id();
      $generic['resetToken'] = $token;
      $generic['resetUrl'] = $reset_url;
    }

    return $generic;
  }

  /**
   * Verifies a reset token and stores a new Drupal password hash.
   */
  public function resetPassword(int $uid, string $token, string $password): void {
    $this->validatePassword($password);
    $record = $this->resetStore()->get($this->resetKey($uid));

    if (!is_array($record) || empty($record['hash']) || !hash_equals((string) $record['hash'], hash('sha256', $token))) {
      throw new \InvalidArgumentException('The password reset token is invalid or expired.');
    }

    $account = $this->userStorage()->load($uid);
    if (!$account instanceof UserInterface || !$account->isActive()) {
      throw new \InvalidArgumentException('The password reset token is invalid or expired.');
    }

    $account->setPassword($password);
    $account->save();
    $this->resetStore()->delete($this->resetKey($uid));
  }

  /**
   * Resolves an Authorization: Bearer header into an active Drupal user.
   */
  public function accountFromAuthorizationHeader(?string $authorizationHeader): ?UserInterface {
    if (!preg_match('/^Bearer\s+(?<token>.+)$/i', (string) $authorizationHeader, $matches)) {
      return NULL;
    }

    try {
      $payload = $this->decodeJwt($matches['token']);
    }
    catch (\Throwable) {
      return NULL;
    }

    $account = isset($payload['sub']) ? $this->userStorage()->load((int) $payload['sub']) : NULL;
    return $account instanceof UserInterface && $account->isActive() ? $account : NULL;
  }

  /**
   * Shapes public user data consistently for all auth responses.
   */
  public function userView(UserInterface $account): array {
    return [
      'id' => (int) $account->id(),
      'name' => $account->getDisplayName(),
      'email' => $account->getEmail(),
      'roles' => array_values($account->getRoles(TRUE)),
    ];
  }

  private function buildSession(UserInterface $account): array {
    $issued_at = $this->time->getRequestTime();
    $expires_at = $issued_at + (int) $this->setting('auth_api_jwt_ttl', 3600);
    $payload = [
      'iss' => (string) $this->setting('auth_api_jwt_issuer', 'drupal-auth-api'),
      'sub' => (string) $account->id(),
      'uid' => (int) $account->id(),
      'name' => $account->getDisplayName(),
      'mail' => $account->getEmail(),
      'iat' => $issued_at,
      'nbf' => $issued_at,
      'exp' => $expires_at,
    ];

    return [
      'tokenType' => 'Bearer',
      'accessToken' => $this->encodeJwt($payload),
      'expiresAt' => $expires_at,
      'user' => $this->userView($account),
    ];
  }

  private function encodeJwt(array $payload): string {
    $header = ['alg' => 'HS256', 'typ' => 'JWT'];
    $segments = [
      $this->base64UrlEncode(json_encode($header, JSON_THROW_ON_ERROR)),
      $this->base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR)),
    ];
    $signature = hash_hmac('sha256', implode('.', $segments), $this->jwtSecret(), TRUE);
    $segments[] = $this->base64UrlEncode($signature);

    return implode('.', $segments);
  }

  private function decodeJwt(string $token): array {
    $segments = explode('.', $token);
    if (count($segments) !== 3) {
      throw new \InvalidArgumentException('Malformed JWT.');
    }

    [$encoded_header, $encoded_payload, $encoded_signature] = $segments;
    $header = json_decode($this->base64UrlDecode($encoded_header), TRUE, flags: JSON_THROW_ON_ERROR);
    $payload = json_decode($this->base64UrlDecode($encoded_payload), TRUE, flags: JSON_THROW_ON_ERROR);

    if (($header['alg'] ?? '') !== 'HS256') {
      throw new \InvalidArgumentException('Unsupported JWT algorithm.');
    }

    $expected_signature = hash_hmac('sha256', $encoded_header . '.' . $encoded_payload, $this->jwtSecret(), TRUE);
    if (!hash_equals($expected_signature, $this->base64UrlDecode($encoded_signature))) {
      throw new \InvalidArgumentException('Invalid JWT signature.');
    }

    $now = $this->time->getRequestTime();
    if (($payload['nbf'] ?? 0) > $now + 30 || ($payload['exp'] ?? 0) < $now - 30) {
      throw new \InvalidArgumentException('Expired or inactive JWT.');
    }

    return $payload;
  }

  private function base64UrlEncode(string $value): string {
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
  }

  private function base64UrlDecode(string $value): string {
    $decoded = base64_decode(strtr($value, '-_', '+/'), TRUE);
    if ($decoded === FALSE) {
      throw new \InvalidArgumentException('Invalid base64url value.');
    }

    return $decoded;
  }

  private function validateEmail(string $email): void {
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
      throw new \InvalidArgumentException('Enter a valid email address.');
    }
  }

  private function validatePassword(string $password): void {
    if (strlen($password) < 12) {
      throw new \InvalidArgumentException('Password must be at least 12 characters.');
    }
  }

  private function createUniqueUsername(string $seed): string {
    $base = strtolower(preg_replace('/[^a-zA-Z0-9_.-]+/', '.', $seed) ?: 'user');
    $base = trim($base, '.-_') ?: 'user';
    $candidate = substr($base, 0, 50);
    $counter = 1;

    while ($this->loadUserByUsername($candidate) instanceof UserInterface) {
      $suffix = '-' . $counter++;
      $candidate = substr($base, 0, 50 - strlen($suffix)) . $suffix;
    }

    return $candidate;
  }

  private function loadUserByEmail(string $email): ?UserInterface {
    $accounts = $this->userStorage()->loadByProperties(['mail' => $email]);
    $account = reset($accounts);
    return $account instanceof UserInterface ? $account : NULL;
  }

  private function loadUserByUsername(string $username): ?UserInterface {
    $accounts = $this->userStorage()->loadByProperties(['name' => $username]);
    $account = reset($accounts);
    return $account instanceof UserInterface ? $account : NULL;
  }

  private function userStorage(): EntityStorageInterface {
    return $this->entityTypeManager->getStorage('user');
  }

  private function resetStore() {
    return $this->keyValueExpirableFactory->get('auth_service.password_reset');
  }

  private function resetKey(int $uid): string {
    return 'uid:' . $uid;
  }

  private function buildFrontendResetUrl(int $uid, string $token): string {
    $base = rtrim((string) $this->setting('auth_api_frontend_reset_url', 'http://127.0.0.1:5173/reset-password.html'), '?&');
    $separator = str_contains($base, '?') ? '&' : '?';
    return $base . $separator . http_build_query(['uid' => $uid, 'token' => $token]);
  }

  private function sendPasswordResetEmail(string $email, string $resetUrl): void {
    $site_name = (string) $this->setting('site_name', 'Drupal Auth Demo');
    $expires_minutes = (string) (self::PASSWORD_RESET_TTL / 60);
    $subject = "Reset your {$site_name} password";
    $body = implode("\n\n", [
      'A password reset was requested for your account.',
      "Use this secure link to choose a new password: {$resetUrl}",
      "This link expires in {$expires_minutes} minutes. If you did not request it, you can ignore this email.",
    ]);

    if ($this->sendViaNotificationService($email, $subject, $body, $resetUrl)) {
      return;
    }

    $this->mailManager->mail('auth_service', 'password_reset', $email, $this->languageManager->getDefaultLanguage()->getId(), [
      'site_name' => $site_name,
      'reset_url' => $resetUrl,
      'expires_minutes' => $expires_minutes,
    ]);
  }

  private function sendViaNotificationService(string $email, string $subject, string $body, string $resetUrl): bool {
    $endpoint = (string) $this->setting('auth_api_notification_service_url', 'http://127.0.0.1:8090/api/notifications/email');
    $api_key = (string) $this->setting('auth_api_notification_service_key', 'local-notification-api-key-change-me');

    if ($endpoint === '' || $api_key === '') {
      return FALSE;
    }

    try {
      $response = $this->httpClient->request('POST', $endpoint, [
        'headers' => [
          'Content-Type' => 'application/json',
          'X-Notification-Key' => $api_key,
        ],
        'json' => [
          'to' => $email,
          'subject' => $subject,
          'body' => $body,
          'metadata' => [
            'source' => 'drupal-auth-backend',
            'template' => 'password_reset',
            'resetUrl' => $resetUrl,
          ],
        ],
        'timeout' => 3,
      ]);

      if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
        return FALSE;
      }

      $payload = json_decode((string) $response->getBody(), TRUE);
      return is_array($payload) && ($payload['status'] ?? NULL) === 'accepted';
    }
    catch (\Throwable) {
      return FALSE;
    }
  }

  private function jwtSecret(): string {
    $secret = (string) $this->setting('auth_api_jwt_secret', 'local-demo-jwt-secret-change-me-32-chars');
    if (strlen($secret) < 32) {
      throw new \RuntimeException('Configure settings.php $settings[\'auth_api_jwt_secret\'] with at least 32 random characters.');
    }
    return $secret;
  }

  private function setting(string $name, mixed $default): mixed {
    $env_name = strtoupper($name);
    $env_value = getenv($env_name);
    if ($env_value !== FALSE && $env_value !== '') {
      return $env_value;
    }

    return $this->settings->get($name, $default);
  }

}
