<?php

namespace Drupal\makerspace_kiosk;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\State\StateInterface;

/**
 * Which physical screen shows which display.
 *
 * Each screen Pi is identified by the last six hex digits of its hardware
 * serial number - the "Screen ID" it prints on the TV until it is assigned.
 * The Pi asks /display/screen/{id}/config at boot and on a pushed "reload";
 * this registry answers.
 *
 * Assignments live in State, not config, on purpose: they are operational
 * data that staff change on live. In config they would be overwritten by the
 * next config deploy, silently re-pointing every TV in the building.
 */
class ScreenRegistry {

  /**
   * State key holding assignments, keyed by screen ID.
   */
  const ASSIGNMENTS = 'makerspace_kiosk.screens';

  /**
   * State key holding when each screen last asked, keyed by screen ID.
   */
  const SEEN = 'makerspace_kiosk.screens_seen';

  /**
   * How many never-assigned screens to remember.
   *
   * The config endpoint is anonymous, so anyone can make up IDs; this caps
   * what that can grow to.
   */
  const SEEN_LIMIT = 30;

  /**
   * Skip rewriting "last seen" more often than this, in seconds.
   */
  const SEEN_WRITE_INTERVAL = 300;

  /**
   * Constructs a ScreenRegistry.
   */
  public function __construct(
    protected StateInterface $state,
    protected TimeInterface $time,
  ) {}

  /**
   * Returns the canonical form of a screen ID, or NULL if it is not one.
   */
  public static function normalizeId(string $id): ?string {
    $id = strtoupper(trim($id));
    return preg_match('/^[0-9A-F]{6}$/', $id) ? $id : NULL;
  }

  /**
   * Whether a display address is one a screen may be pointed at.
   *
   * A site path, or an https URL. Anything else (javascript:, file:, plain
   * http) is refused rather than handed to an unattended browser.
   */
  public static function isValidUrl(string $url): bool {
    $url = trim($url);
    if ($url === '' || preg_match('/\s/', $url)) {
      return FALSE;
    }
    if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
      return TRUE;
    }
    return str_starts_with($url, 'https://') && filter_var($url, FILTER_VALIDATE_URL) !== FALSE;
  }

  /**
   * All assignments, keyed by screen ID.
   *
   * @return array<string, array{label: string, url: string, rotation: int}>
   *   Assignments.
   */
  public function assignments(): array {
    return $this->state->get(self::ASSIGNMENTS, []);
  }

  /**
   * One screen's assignment, or NULL when it is unassigned.
   */
  public function assignment(string $id): ?array {
    return $this->assignments()[$id] ?? NULL;
  }

  /**
   * Replaces all assignments.
   *
   * @param array<string, array{label: string, url: string, rotation: int}> $assignments
   *   Assignments keyed by screen ID.
   */
  public function saveAssignments(array $assignments): void {
    ksort($assignments);
    $this->state->set(self::ASSIGNMENTS, $assignments);
  }

  /**
   * When each screen last asked for its assignment, keyed by screen ID.
   *
   * @return array<string, array{first: int, last: int, version: string}>
   *   Sightings.
   */
  public function seen(): array {
    return $this->state->get(self::SEEN, []);
  }

  /**
   * Notes that a screen just asked for its assignment.
   */
  public function recordSeen(string $id, string $version): void {
    $version = substr(preg_replace('/[^0-9A-Za-z.\-]/', '', $version), 0, 20);
    $now = $this->time->getRequestTime();
    $seen = $this->seen();
    $previous = $seen[$id] ?? NULL;
    if ($previous && $now - $previous['last'] < self::SEEN_WRITE_INTERVAL && $previous['version'] === $version) {
      return;
    }
    $seen[$id] = [
      'first' => $previous['first'] ?? $now,
      'last' => $now,
      'version' => $version,
    ];
    $this->state->set(self::SEEN, $this->prune($seen));
  }

  /**
   * Forgets a screen entirely: its assignment and its sightings.
   */
  public function forget(string $id): void {
    $assignments = $this->assignments();
    unset($assignments[$id]);
    $this->saveAssignments($assignments);
    $seen = $this->seen();
    unset($seen[$id]);
    $this->state->set(self::SEEN, $seen);
  }

  /**
   * Keeps every assigned screen plus the most recent unassigned ones.
   */
  protected function prune(array $seen): array {
    $assigned = $this->assignments();
    $unassigned = array_diff_key($seen, $assigned);
    if (count($unassigned) <= self::SEEN_LIMIT) {
      return $seen;
    }
    uasort($unassigned, fn($a, $b) => $b['last'] <=> $a['last']);
    $keep = array_slice($unassigned, 0, self::SEEN_LIMIT, TRUE);
    return array_intersect_key($seen, $assigned) + $keep;
  }

}
