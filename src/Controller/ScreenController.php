<?php

namespace Drupal\makerspace_kiosk\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\PageCache\ResponsePolicy\KillSwitch;
use Drupal\makerspace_kiosk\ScreenRegistry;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tells a screen Pi what it should show.
 */
class ScreenController extends ControllerBase {

  /**
   * Constructs the screen controller.
   */
  public function __construct(
    protected ScreenRegistry $registry,
    protected KillSwitch $pageCacheKillSwitch,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('makerspace_kiosk.screen_registry'),
      $container->get('page_cache_kill_switch'),
    );
  }

  /**
   * Returns a screen's assignment as JSON.
   *
   * Anonymous on purpose: the Pi holds no credentials. The answer only says
   * which public display page to open. A screen asks at boot and when told to
   * reload, so this is a handful of requests per screen per day.
   */
  public function screenConfig(string $screen_id, Request $request): JsonResponse {
    // An assignment changed on live must reach the screen on its next ask,
    // so neither Drupal's page cache nor the edge may keep this answer.
    $this->pageCacheKillSwitch->trigger();

    $id = ScreenRegistry::normalizeId($screen_id);
    if ($id === NULL) {
      return $this->noStore(new JsonResponse(['error' => 'Not a screen ID.'], 400));
    }
    $this->registry->recordSeen($id, (string) $request->query->get('agent', ''));

    $assignment = $this->registry->assignment($id);
    if (!$assignment) {
      return $this->noStore(new JsonResponse(['assigned' => FALSE, 'id' => $id]));
    }
    return $this->noStore(new JsonResponse([
      'assigned' => TRUE,
      'id' => $id,
      'label' => $assignment['label'],
      'url' => $assignment['url'],
      'rotation' => (int) $assignment['rotation'],
    ]));
  }

  /**
   * Marks a response as never cacheable anywhere.
   */
  protected function noStore(JsonResponse $response): JsonResponse {
    $response->setPrivate();
    $response->setMaxAge(0);
    $response->headers->addCacheControlDirective('no-store');
    return $response;
  }

}
