<?php

declare(strict_types=1);

namespace Drupal\search_api_opensearch\Event;

use Drupal\search_api\IndexInterface;

/**
 * Event fired before an index is created.
 *
 * This event allows altering the settings before the index is created.
 */
class BeforeIndexCreateEvent {

  public function __construct(
    protected array $settings,
    protected IndexInterface $index,
  ) {}

  /**
   * Get the settings.
   *
   * @return array<string,mixed>
   *   The settings.
   */
  public function getSettings(): array {
    return $this->settings;
  }

  /**
   * Set the settings.
   *
   * @param array<string,mixed> $settings
   *   The settings to set.
   */
  public function setSettings(array $settings): void {
    $this->settings = $settings;
  }

  /**
   * Get the index.
   */
  public function getIndex(): IndexInterface {
    return $this->index;
  }

}
