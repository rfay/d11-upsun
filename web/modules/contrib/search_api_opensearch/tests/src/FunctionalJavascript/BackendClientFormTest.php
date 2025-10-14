<?php

declare(strict_types=1);

namespace Drupal\Tests\search_api_opensearch_ai\FunctionalJavascript;

use Drupal\FunctionalJavascriptTests\WebDriverTestBase;

/**
 * Tests the backend client form.
 *
 * @group search_api_opensearch
 */
class BackendClientFormTest extends WebDriverTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'search_api_opensearch',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Create an admin user.
    $admin_user = $this->drupalCreateUser([
      'access administration pages',
      'access site reports',
      'administer search_api',
    ]);
    $this->drupalLogin($admin_user);
  }

  /**
   * Tests the backend client configuration form.
   */
  public function testBackendConfigForm(): void {
    // Navigate to the OpenSearch configuration form.
    // @todo replace with a route.
    $url = '/admin/config/search/search-api/add-server';
    $this->drupalGet($url);

    // Assert the form loads without errors.
    $this->assertSession()->pageTextContains('Add search server');

    // Choose Opensearch as a backend.
    $this->getSession()->getPage()->selectFieldOption('backend', 'opensearch');
    $this->assertSession()->waitForElementVisible('css', '#search-api-backend-config-form');

    // Check the standard connector configuration displays by default.
    $this->assertSession()->pageTextContains('A standard connector without authentication');

    // Select the basic_auth connector and check the form reloads.
    $this->getSession()->getPage()->selectFieldOption('backend_config[connector]', 'basicauth');
    $this->assertSession()->assertWaitOnAjaxRequest();
    $this->assertSession()->pageTextContains('OpenSearch connector with HTTP Basic Auth.');

  }

}
