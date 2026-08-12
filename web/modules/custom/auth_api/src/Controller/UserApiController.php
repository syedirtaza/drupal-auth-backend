<?php

namespace Drupal\auth_api\Controller;

use Drupal\auth_service\Services\AuthenticationService;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Handles authenticated user API requests.
 */
final class UserApiController extends ControllerBase {

  public function __construct(private readonly AuthenticationService $authenticationService) {}

  public static function create(ContainerInterface $container): self {
    return new self($container->get('auth_service.authentication'));
  }

  public function me(Request $request): JsonResponse {
    $account = $this->authenticationService->accountFromAuthorizationHeader($request->headers->get('Authorization'));

    if ($account === NULL) {
      return new JsonResponse(['error' => ['message' => 'Missing or invalid bearer token.']], 401);
    }

    return new JsonResponse(['user' => $this->authenticationService->userView($account)]);
  }

}
