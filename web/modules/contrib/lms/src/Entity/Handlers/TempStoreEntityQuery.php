<?php

declare(strict_types=1);

namespace Drupal\lms\Entity\Handlers;

/**
 * Minimal entity query that searches tempstore instead of SQL.
 *
 * Used by AdaptiveLmsStatusStorage::getQuery() for anonymous users so that
 * TrainingManager code (which uses getQuery()->condition()->execute()) can
 * find entities stored in the private tempstore rather than the database.
 *
 * Only the methods actually called by TrainingManager are implemented.
 */
final class TempStoreEntityQuery {

  /**
   * Callback that returns all tempstore entities as [id => entity].
   */
  private mixed $entityLoader;

  /**
   * Callback fn(entity, field, value, operator): bool for condition matching.
   */
  private mixed $matcher;

  /**
   * Accumulated conditions as [field, value, operator] triples.
   */
  private array $conditions = [];

  /**
   * Accumulated sort directives as [field, direction] pairs.
   */
  private array $sorts = [];

  /**
   * Range start offset, or NULL for no offset.
   */
  private ?int $rangeStart = NULL;

  /**
   * Range length limit, or NULL for no limit.
   */
  private ?int $rangeLength = NULL;

  /**
   * Whether execute() should return a count instead of an ID map.
   */
  private bool $countMode = FALSE;

  /**
   * Binds the storage-specific callbacks after the service is retrieved.
   *
   * @param \Closure $entityLoader
   *   Returns all tempstore entity data arrays as [id => data].
   * @param \Closure $matcher
   *   fn(data, field, value, operator): bool
   */
  public function configure(\Closure $entityLoader, \Closure $matcher): void {
    $this->entityLoader = $entityLoader;
    $this->matcher = $matcher;
  }

  public function accessCheck(bool $access_check = TRUE): static {
    return $this;
  }

  public function addTag(string $tag): static {
    return $this;
  }

  public function hasTag(string $tag): bool {
    return FALSE;
  }

  public function addMetaData(string $key, mixed $object): static {
    return $this;
  }

  public function condition(string $field, mixed $value = NULL, ?string $operator = NULL): static {
    $this->conditions[] = [$field, $value, $operator ?? '='];
    return $this;
  }

  public function sort(string $field, string $direction = 'ASC'): static {
    $this->sorts[] = [$field, strtoupper($direction)];
    return $this;
  }

  public function range(?int $start = NULL, ?int $length = NULL): static {
    $this->rangeStart = $start;
    $this->rangeLength = $length;
    return $this;
  }

  /**
   * Switches to count mode — execute() returns an int instead of an array.
   */
  public function count(): static {
    $this->countMode = TRUE;
    return $this;
  }

  /**
   * Executes the query and returns a map of IDs or a count.
   *
   * @return array<string, string>|int
   */
  public function execute(): array|int {
    $all = ($this->entityLoader)();

    $matching = array_filter($all, fn(array $data) => $this->matchesAll($data));

    if ($this->sorts !== []) {
      uasort($matching, fn(array $a, array $b) => $this->compareData($a, $b));
    }

    if ($this->countMode) {
      return count($matching);
    }

    if ($this->rangeStart !== NULL || $this->rangeLength !== NULL) {
      $matching = array_slice($matching, $this->rangeStart ?? 0, $this->rangeLength, TRUE);
    }

    $ids = array_keys($matching);
    return array_combine($ids, $ids);
  }

  private function matchesAll(array $data): bool {
    foreach ($this->conditions as [$field, $value, $operator]) {
      if (!($this->matcher)($data, $field, $value, $operator)) {
        return FALSE;
      }
    }
    return TRUE;
  }

  private function compareData(array $a, array $b): int {
    foreach ($this->sorts as [$field, $direction]) {
      $aVal = $this->getRawFieldValue($a, $field);
      $bVal = $this->getRawFieldValue($b, $field);
      $cmp = $aVal <=> $bVal;
      if ($cmp !== 0) {
        return $direction === 'DESC' ? -$cmp : $cmp;
      }
    }
    return 0;
  }

  private function getRawFieldValue(array $data, string $field): mixed {
    $items = $data[$field] ?? [];
    if (\count($items) === 0) {
      return NULL;
    }
    return $items[0]['value'] ?? $items[0]['target_id'] ?? NULL;
  }

}
