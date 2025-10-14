<?php

namespace Drupal\search_api_opensearch\Plugin\search_api\backend;

use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Plugin\PluginDependencyTrait;
use Drupal\Core\Plugin\PluginFormInterface;
use Drupal\Core\Url;
use Drupal\search_api\Backend\BackendPluginBase;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Query\QueryInterface;
use Drupal\search_api_opensearch\Connector\ConnectorFormTrait;
use Drupal\search_api_opensearch\Connector\ConnectorPluginManager;
use Drupal\search_api_opensearch\Connector\OpenSearchConnectorInterface;
use Drupal\search_api_opensearch\Event\SupportsDataTypeEvent;
use Drupal\search_api_opensearch\SearchAPI\BackendClientFactory;
use Drupal\search_api_opensearch\SearchAPI\BackendClientInterface;
use OpenSearch\Client;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Provides an OpenSearch backend for Search API.
 *
 * @SearchApiBackend(
 *   id = "opensearch",
 *   label = @Translation("OpenSearch"),
 *   description = @Translation("Provides an OpenSearch backend.")
 * )
 */
class OpenSearchBackend extends BackendPluginBase implements PluginFormInterface {

  use DependencySerializationTrait {
    __sleep as traitSleep;
  }
  use PluginDependencyTrait;
  use ConnectorFormTrait;

  /**
   * Auto fuzziness setting.
   *
   * @see https://opensearch.org/docs/latest/opensearch/query-dsl/full-text/#options
   */
  const FUZZINESS_AUTO = 'auto';

  /**
   * The client factory.
   *
   * @var \Drupal\search_api_opensearch\Connector\ConnectorPluginManager
   */
  protected $connectorPluginManager;

  /**
   * The OpenSearch backend client factory.
   *
   * @var \Drupal\search_api_opensearch\SearchAPI\BackendClientFactory
   */
  protected $backendClientFactory;

  /**
   * The OpenSearch Search API client.
   *
   * @var \Drupal\search_api_opensearch\SearchAPI\BackendClient
   */
  protected $backendClient;

  /**
   * The OpenSearch client.
   *
   * @var \OpenSearch\Client
   */
  protected $client;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    array $plugin_definition,
    ConnectorPluginManager $connectorPluginManager,
    BackendClientFactory $sapiClientFactory,
    protected EventDispatcherInterface $eventDispatcher,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->connectorPluginManager = $connectorPluginManager;
    $this->backendClientFactory = $sapiClientFactory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('plugin.manager.search_api_opensearch.connector'),
      $container->get('search_api_opensearch.backend_client_factory'),
      $container->get('event_dispatcher')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getSupportedFeatures() {
    return [
      'search_api_facets',
      'search_api_facets_operator_or',
      'search_api_mlt',
      'search_api_spellcheck',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'connector' => 'standard',
      'connector_config' => [],
      'advanced' => [
        'fuzziness' => self::FUZZINESS_AUTO,
        'prefix' => NULL,
        'synonyms' => [],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $completeForm = $form_state->getCompleteFormState();
    $this->buildConnectorConfigForm($form, $completeForm, $this->configuration);

    $form['advanced'] = [
      '#type' => 'details',
      '#title' => $this->t('Advanced'),
    ];

    $fuzzinessOptions = [
      '0' => $this->t('- Disabled -'),
      self::FUZZINESS_AUTO => self::FUZZINESS_AUTO,
    ];
    $fuzzinessOptions += array_combine(range(1, 5), range(1, 5));
    $form['advanced']['fuzziness'] = [
      '#type' => 'select',
      '#title' => $this->t('Fuzziness'),
      '#required' => TRUE,
      '#options' => $fuzzinessOptions,
      '#default_value' => $this->configuration['advanced']['fuzziness'] ?? 0,
      '#description' => $this->t('Some queries and APIs support parameters to allow inexact fuzzy matching, using the fuzziness parameter. See <a href="https://opensearch.org/docs/latest/opensearch/query-dsl/full-text/#options">Fuzziness</a> for more information.'),
    ];
    $form['advanced']['prefix'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Index prefix'),
      '#description' => $this->t('Using an index prefix can be useful for using the same server for different projects or environments.'),
      '#default_value' => $this->configuration['advanced']['prefix'] ?? '',
    ];
    $form['advanced']['synonyms'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Synonyms'),
      '#description' => $this->t('Enter synonyms in <a href=":url">Solr synonyms.txt format</a>', [
        ':url' => 'https://www.elastic.co/guide/en/elasticsearch/reference/current/analysis-synonym-tokenfilter.html#_solr_synonyms',
      ]),
      '#default_value' => implode(\PHP_EOL, (array) $this->configuration['advanced']['synonyms'] ?? []),
    ];
    $form['advanced']['max_ngram_diff'] = [
      '#type' => 'number',
      '#title' => $this->t('Max ngram diff'),
      '#description' => $this->t('The maximum allowed difference between min_gram and max_gram for ngram tokenizers.'),
      '#default_value' => $this->configuration['advanced']['max_ngram_diff'] ?? 1,
      '#min' => 1,
    ];

    return $form;
  }

  /**
   * Handles switching the selected connector plugin.
   */
  public static function buildAjaxConnectorConfigForm(array $form, FormStateInterface $form_state): array {
    // The work is already done in form(), where we rebuild the entity according
    // to the current form values and then create the backend configuration form
    // based on that. So we just need to return the relevant part of the form
    // here.
    return $form['backend_config']['connector_config'];
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    $this->validateConnectorConfigForm($form, $form_state, []);
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();
    $values['advanced']['synonyms'] = explode(\PHP_EOL, $form_state->getValue([
      'advanced',
      'synonyms',
    ], ''));
    $this->setConfiguration($values);
    $this->submitConnectorConfigForm($form, $form_state);
  }

  /**
   * Gets the OpenSearch client.
   *
   * @return \OpenSearch\Client
   *   The OpenSearch client.
   */
  public function getClient(): Client {
    if (!isset($this->client)) {
      $this->client = $this->doGetConnector()->getClient();
    }
    return $this->client;
  }

  /**
   * Gets the OpenSearch Search API client.
   *
   * @return \Drupal\search_api_opensearch\SearchAPI\BackendClientInterface
   *   The OpenSearch Search API client.
   */
  public function getBackendClient(): BackendClientInterface {
    if (!isset($this->backendClient)) {
      $settings = [
        'prefix' => $this->getPrefix(),
        'fuzziness' => $this->getFuzziness(),
      ];
      $this->backendClient = $this->backendClientFactory->create($this->getClient(), $settings);
    }
    return $this->backendClient;
  }

  /**
   * Get the configured index prefix.
   *
   * @return string
   *   The configured prefix.
   */
  protected function getPrefix(): string {
    return $this->configuration['advanced']['prefix'] ?? '';
  }

  /**
   * Get the configured fuzziness value.
   *
   * @return string
   *   The configured fuzziness value.
   */
  public function getFuzziness(): string {
    return $this->configuration['advanced']['fuzziness'] ?? 'auto';
  }

  /**
   * {@inheritdoc}
   */
  public function viewSettings(): array {
    $info = [];

    $connector = $this->doGetConnector();
    $url = $connector->getUrl();
    $info[] = [
      'label' => $this->t('OpenSearch cluster URL'),
      'info' => Link::fromTextAndUrl($url, Url::fromUri($url)),
    ];

    if ($this->server->status()) {
      // If the server is enabled, check whether OpenSearch can be reached.
      $ping = $this->server->isAvailable();
      if ($ping) {
        $msg = $this->t('The OpenSearch cluster was reached successfully');
      }
      else {
        $msg = $this->t('The OpenSearch cluster could not be reached. Further data is therefore unavailable.');
      }
      $info[] = [
        'label' => $this->t('Connection'),
        'info' => $msg,
        'status' => $ping ? 'ok' : 'error',
      ];
    }

    return $info;
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies() {
    $this->calculatePluginDependencies($this->doGetConnector());
  }

  /**
   * {@inheritdoc}
   */
  public function isAvailable(): bool {
    return $this->getBackendClient()->isAvailable();
  }

  /**
   * {@inheritdoc}
   */
  public function addIndex(IndexInterface $index) {
    $this->getBackendClient()->addIndex($index);
  }

  /**
   * {@inheritdoc}
   */
  public function removeIndex($index) {
    $this->getBackendClient()->removeIndex($index);
  }

  /**
   * {@inheritdoc}
   */
  public function updateIndex(IndexInterface $index) {
    $this->getBackendClient()->updateIndex($index);
  }

  /**
   * {@inheritdoc}
   */
  public function indexItems(IndexInterface $index, array $items): array {
    return $this->getBackendClient()->indexItems($index, $items);
  }

  /**
   * {@inheritdoc}
   */
  public function deleteItems(IndexInterface $index, array $item_ids) {
    $this->getBackendClient()->deleteItems($index, $item_ids);
  }

  /**
   * {@inheritdoc}
   */
  public function deleteAllIndexItems(IndexInterface $index, $datasource_id = NULL) {
    $this->getBackendClient()->clearIndex($index, $datasource_id);
  }

  /**
   * {@inheritdoc}
   */
  public function search(QueryInterface $query) {
    $this->getBackendClient()->search($query);
  }

  /**
   * {@inheritdoc}
   *
   * Make sure that the client does not get serialized.
   */
  public function __sleep(): array {
    $vars = $this->traitSleep();
    unset($vars[array_search('client', $vars)]);
    return $vars;
  }

  /**
   * {@inheritdoc}
   *
   * @todo Method overriding is to support return types in 10.x. Remove
   * once drupal:10.x support is dropped.
   */
  // phpcs:ignore
  public function __wakeup(): void {
    parent::__wakeup();
  }

  /**
   * {@inheritdoc}
   */
  public function supportsDataType($type) {
    if (str_starts_with($type, 'search_api_opensearch_')) {
      return TRUE;
    }
    $event = new SupportsDataTypeEvent($type);
    $this->eventDispatcher->dispatch($event);
    return $event->isSupported() || parent::supportsDataType($type);
  }

  /**
   * Gets the configured connector.
   */
  private function doGetConnector(): OpenSearchConnectorInterface {
    return $this->getConnector($this->configuration['connector'], $this->configuration['connector_config']);
  }

}
