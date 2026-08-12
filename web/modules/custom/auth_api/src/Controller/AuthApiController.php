<?php

namespace Drupal\auth_api\Controller;

use Drupal\auth_service\Services\AuthenticationService;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Handles public authentication API requests.
 */
final class AuthApiController extends ControllerBase {

  public function __construct(private readonly AuthenticationService $authenticationService) {}

  public static function create(ContainerInterface $container): self {
    return new self($container->get('auth_service.authentication'));
  }

  public function register(Request $request): JsonResponse {
    try {
      return $this->json($this->authenticationService->register($this->payload($request)), 201);
    }
    catch (\InvalidArgumentException $exception) {
      return $this->error($exception->getMessage(), 422);
    }
  }

  public function login(Request $request): JsonResponse {
    $payload = $this->payload($request);
    $session = $this->authenticationService->login((string) ($payload['identifier'] ?? ''), (string) ($payload['password'] ?? ''));

    if ($session === NULL) {
      return $this->error('Invalid username/email or password.', 401);
    }

    return $this->json($session);
  }

  public function forgotPassword(Request $request): JsonResponse {
    $payload = $this->payload($request);
    return $this->json($this->authenticationService->requestPasswordReset((string) ($payload['email'] ?? '')));
  }

  public function resetPassword(Request $request): JsonResponse {
    $payload = $this->payload($request);

    try {
      $this->authenticationService->resetPassword((int) ($payload['uid'] ?? 0), (string) ($payload['token'] ?? ''), (string) ($payload['password'] ?? ''));
      return $this->json(['message' => 'Password updated. You can now sign in.']);
    }
    catch (\InvalidArgumentException $exception) {
      return $this->error($exception->getMessage(), 422);
    }
  }

  private function payload(Request $request): array {
    $payload = Json::decode($request->getContent() ?: '{}');
    return is_array($payload) ? $payload : [];
  }

  private function json(array $data, int $status = 200): JsonResponse {
    return new JsonResponse($data, $status);
  }

  private function error(string $message, int $status): JsonResponse {
    return $this->json(['error' => ['message' => $message]], $status);
  }

}
