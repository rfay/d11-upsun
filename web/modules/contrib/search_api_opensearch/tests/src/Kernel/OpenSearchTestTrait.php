<?php

declare(strict_types=1);

namespace Drupal\Tests\search_api_opensearch\Kernel;

use Drupal\search_api\Entity\Index;
use Drupal\search_api\Entity\Server;
use Drupal\search_api\IndexInterface;
use Drupal\search_api_opensearch\Plugin\search_api\backend\OpenSearchBackend;
use OpenSearch\Common\Exceptions\NoNodesAvailableException;

/**
 * Provides trait methods for OpenSearch Kernel tests.
 */
trait OpenSearchTestTrait {

  /**
   * Check if the server is available.
   */
  protected function serverAvailable(): bool {
    try {
      if ($this->getBackend()->isAvailable()) {
        return TRUE;
      }
    }
    catch (NoNodesAvailableException) {
      // Ignore.
    }
    return FALSE;
  }

  /**
   * Gets the search server.
   */
  protected function getServer(): ?Server {
    return Server::load($this->serverId);
  }

  /**
   * Retrieves the search backend used by this test.
   */
  protected function getBackend(): ?OpenSearchBackend {
    return $this->getServer()?->getBackend();
  }

  /**
   * Retrieves the search index used by this test.
   */
  protected function getIndex(): ?IndexInterface {
    return Index::load($this->indexId);
  }

  /**
   * Re-creates the index.
   *
   * @throws \Drupal\search_api\SearchApiException
   */
  protected function recreateIndex() {
    $backend = $this->getBackend();
    $index = $this->getIndex();
    if (!isset($index)) {
      $this->fail("Failed to load index");
    }
    $client = $backend->getBackendClient();
    if ($client->indexExists($index)) {
      $client->removeIndex($index);
    }
    $client->addIndex($index);
  }

  /**
   * Refreshes the indices on the server.
   *
   * This ensures all indexed data is available to searches.
   */
  protected function refreshIndices(): void {
    $this->getBackend()
      ->getClient()
      ->indices()
      ->refresh();
  }

}
