<?php

declare(strict_types=1);

namespace Drupal\search_api_opensearch\Connector;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\SubformState;
use Drupal\Core\Plugin\PluginFormInterface;

/**
 * Provides a trait for building the OpenSearch connector configuration form.
 *
 * This trait can be used in classes that need to build a configuration form
 * for an OpenSearch connector plugin.
 */
trait ConnectorFormTrait {

  /**
   * Gets the OpenSearch connector.
   *
   * @param string $pluginID
   *   The plugin ID.
   * @param array<string,mixed> $config
   *   The plugin configuration.
   *
   * @return \Drupal\search_api_opensearch\Connector\OpenSearchConnectorInterface
   *   The OpenSearch connector.
   *
   * @throws \Drupal\Component\Plugin\Exception\PluginException
   *   Thrown when a plugin error occurs.
   * @throws \Drupal\search_api_opensearch\Connector\InvalidConnectorException
   *   Thrown when a connector is invalid.
   */
  public function getConnector(string $pluginID, array $config): OpenSearchConnectorInterface {
    $connector = $this->connectorPluginManager->createInstance($pluginID, $config);
    if (!$connector instanceof OpenSearchConnectorInterface) {
      throw new InvalidConnectorException(sprintf("Invalid connector %s", $pluginID));
    }
    return $connector;
  }

  /**
   * Builds the OpenSearch Connector configuration form.
   *
   * @param array<string,mixed> $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   * @param array<string,mixed> $config
   *   The current configuration.
   *
   * @throws \Drupal\Component\Plugin\Exception\PluginException
   */
  public function buildConnectorConfigForm(array &$form, FormStateInterface $form_state, array $config): void {
    $options = $this->getConnectorOptions();
    $form['connector'] = [
      '#type' => 'radios',
      '#title' => $this->t('OpenSearch Connector'),
      '#description' => $this->t('Choose a connector to use for this OpenSearch server.'),
      '#options' => $options,
      '#default_value' => $form_state->getValue('connector') ?? $config['connector'] ?? 'standard',
      '#required' => TRUE,
      '#ajax' => [
        'callback' => [static::class, 'buildAjaxConnectorConfigForm'],
        'wrapper' => 'opensearch-connector-config-form',
        'method' => 'replaceWith',
        'effect' => 'fade',
      ],
    ];
    $form['connector_config'] = [];

    $connector_id = $form_state->getValue('connector') ?? $config['connector'];
    if (isset($connector_id)) {
      $pluginConfig = $form_state->getValue('connector_config') ?? $config['connector_config'] ?? [];
      $connector = $this->connectorPluginManager->createInstance($connector_id, $pluginConfig);
      if ($connector instanceof PluginFormInterface) {
        $form_state->set('connector', $connector_id);
        // Attach the OpenSearch connector plugin configuration form.
        $connector_form_state = SubformState::createForSubform($form['connector_config'], $form, $form_state);
        $form['connector_config'] = $connector->buildConfigurationForm($form['connector_config'], $connector_form_state);

        // Modify the backend plugin configuration container element.
        $form['connector_config']['#type'] = 'details';
        $form['connector_config']['#title'] = $this->t('Configure %plugin OpenSearch connector', ['%plugin' => $connector->getLabel()]);
        $form['connector_config']['#description'] = $connector->getDescription();
        $form['connector_config']['#open'] = TRUE;
      }
    }
    $form['connector_config'] += ['#type' => 'container'];
    $form['connector_config']['#attributes'] = [
      'id' => 'opensearch-connector-config-form',
    ];
    $form['connector_config']['#tree'] = TRUE;
  }

  /**
   * Gets a list of connectors for use in an HTML options list.
   *
   * @return array<string,string>
   *   An associative array of plugin id => label.
   */
  protected function getConnectorOptions(): array {
    $options = [];
    foreach ($this->connectorPluginManager->getDefinitions() as $plugin_id => $plugin_definition) {
      $options[$plugin_id] = $plugin_definition['label'];
    }
    return $options;
  }

  /**
   * Validates the OpenSearch Connector configuration form.
   *
   * @param array<string,mixed> $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   * @param array<string,mixed> $config
   *   The current configuration.
   *
   * @throws \Drupal\Component\Plugin\Exception\PluginException
   */
  public function validateConnectorConfigForm(array &$form, FormStateInterface $form_state, array $config): void {
    // Check if the OpenSearch connector plugin changed.
    if (!isset($config['connector']) || $form_state->getValue('connector') != $config['connector']) {
      $connector_id = $form_state->getValue('connector');
      $new_connector = $this->connectorPluginManager->createInstance($connector_id);
      if (!$new_connector instanceof PluginFormInterface) {
        $form_state->setError($form['connector'], $this->t('The connector could not be activated.'));
        return;
      }
      return;
    }

    // Check before loading the backend plugin so we don't throw an exception.
    $connector = $this->getConnector($form_state->getValue('connector'), $form_state->getValue('connector_config'));
    if (!$connector instanceof PluginFormInterface) {
      $form_state->setError($form['connector'], $this->t('The connector could not be activated.'));
      return;
    }
    $connector_form_state = SubformState::createForSubform($form['connector_config'], $form, $form_state);
    $connector->validateConfigurationForm($form['connector_config'], $connector_form_state);
  }

  /**
   * Submits the OpenSearch Connector configuration form.
   *
   * @param array<string,mixed> $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   *
   * @throws \Drupal\Component\Plugin\Exception\PluginException
   */
  public function submitConnectorConfigForm(array &$form, FormStateInterface $form_state): void {
    $connector = $this->getConnector($form_state->getValue('connector'), $form_state->getValue('connector_config'));
    if ($connector instanceof PluginFormInterface) {
      $connector_form_state = SubformState::createForSubform($form['connector_config'], $form, $form_state);
      $connector->submitConfigurationForm($form['connector_config'], $connector_form_state);
      // Overwrite the form values with type casted values.
      $form_state->setValue('connector_config', $connector->getConfiguration());
    }
  }

  /**
   * Ajax callback for when a connector is selected.
   */
  abstract public static function buildAjaxConnectorConfigForm(array $form, FormStateInterface $form_state): array;

}
