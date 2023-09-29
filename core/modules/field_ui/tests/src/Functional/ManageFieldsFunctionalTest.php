<?php

namespace Drupal\Tests\field_ui\Functional;

use Behat\Mink\Exception\ElementNotFoundException;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;

/**
 * Tests the Field UI "Manage fields" screen.
 *
 * @group field_ui
 * @group #slow
 */
class ManageFieldsFunctionalTest extends ManageFieldsFunctionalTestBase {

  /**
   * Runs the field CRUD tests.
   *
   * In order to act on the same fields, and not create the fields over and over
   * again the following tests create, update and delete the same fields.
   */
  public function testCRUDFields() {
    $this->manageFieldsPage();
    $this->createField();
    $this->updateField();
    $this->addExistingField();
    $this->cardinalitySettings();
    $this->fieldListAdminPage();
    $this->deleteField();
    $this->addPersistentFieldStorage();
  }

  /**
   * Tests the manage fields page.
   *
   * @param string $type
   *   (optional) The name of a content type.
   */
  protected function manageFieldsPage($type = '') {
    $type = empty($type) ? $this->contentType : $type;
    $this->drupalGet('admin/structure/types/manage/' . $type . '/fields');
    // Check all table columns.
    $table_headers = ['Label', 'Machine name', 'Field type', 'Operations'];
    foreach ($table_headers as $table_header) {
      // We check that the label appear in the table headings.
      $this->assertSession()->responseContains($table_header . '</th>');
    }

    // Test the "Create a new field" action link.
    $this->assertSession()->linkExists('Create a new field');

    // Assert entity operations for all fields.
    $number_of_links = 3;
    $number_of_links_found = 0;
    $operation_links = $this->xpath('//ul[@class = "dropbutton"]/li/a');
    $url = base_path() . "admin/structure/types/manage/$type/fields/node.$type.body";

    foreach ($operation_links as $link) {
      switch ($link->getAttribute('title')) {
        case 'Edit field settings.':
          $this->assertSame($url, $link->getAttribute('href'));
          $number_of_links_found++;
          break;

        case 'Edit storage settings.':
          $this->assertSame("$url/storage", $link->getAttribute('href'));
          $number_of_links_found++;
          break;

        case 'Delete field.':
          $this->assertSame("$url/delete", $link->getAttribute('href'));
          $number_of_links_found++;
          break;
      }
    }

    $this->assertEquals($number_of_links, $number_of_links_found);
  }

  /**
   * Tests adding a new field.
   *
   * @todo Assert properties can be set in the form and read back in
   * $field_storage and $fields.
   */
  protected function createField() {
    // Create a test field.
    $this->fieldUIAddNewField('admin/structure/types/manage/' . $this->contentType, $this->fieldNameInput, $this->fieldLabel);
  }

  /**
   * Tests editing an existing field.
   */
  protected function updateField() {
    $field_id = 'node.' . $this->contentType . '.' . $this->fieldName;
    // Go to the field edit page.
    $this->drupalGet('admin/structure/types/manage/' . $this->contentType . '/fields/' . $field_id . '/storage');
    $this->assertSession()->assertEscaped($this->fieldLabel);

    // Populate the field settings with new settings.
    $string = 'updated dummy test string';
    $edit = [
      'settings[test_field_storage_setting]' => $string,
    ];
    $this->submitForm($edit, 'Save');

    // Go to the field edit page.
    $this->drupalGet('admin/structure/types/manage/' . $this->contentType . '/fields/' . $field_id);
    $edit = [
      'settings[test_field_setting]' => $string,
    ];
    $this->assertSession()->pageTextContains('Default value');
    $this->submitForm($edit, 'Save settings');

    // Assert the field settings are correct.
    $this->assertFieldSettings($this->contentType, $this->fieldName, $string);

    // Assert redirection back to the "manage fields" page.
    $this->assertSession()->addressEquals('admin/structure/types/manage/' . $this->contentType . '/fields');
  }

  /**
   * Tests adding an existing field in another content type.
   */
  protected function addExistingField() {
    // Check "Re-use existing field" appears.
    $this->drupalGet('admin/structure/types/manage/page/fields');
    $this->assertSession()->pageTextContains('Re-use an existing field');
    $this->clickLink('Re-use an existing field');
    // Check that fields of other entity types (here, the 'comment_body' field)
    // do not show up in the "Re-use existing field" list.
    $this->assertSession()->elementNotExists('css', '.js-reuse-table [data-field-id="comment_body"]');
    // Validate the FALSE assertion above by also testing a valid one.
    $this->assertSession()->elementExists('css', ".js-reuse-table [data-field-id='{$this->fieldName}']");
    $new_label = $this->fieldLabel . '_2';
    // Add a new field based on an existing field.
    $this->fieldUIAddExistingField("admin/structure/types/manage/page", $this->fieldName, $new_label);
  }

  /**
   * Tests the cardinality settings of a field.
   *
   * We do not test if the number can be submitted with anything else than a
   * numeric value. That is tested already in FormTest::testNumber().
   */
  protected function cardinalitySettings() {
    $field_edit_path = 'admin/structure/types/manage/article/fields/node.article.body/storage';

    // Assert the cardinality other field cannot be empty when cardinality is
    // set to 'number'.
    $edit = [
      'cardinality' => 'number',
      'cardinality_number' => '',
    ];
    $this->drupalGet($field_edit_path);
    $this->submitForm($edit, 'Save');
    $this->assertSession()->pageTextContains('Number of values is required.');

    // Submit a custom number.
    $edit = [
      'cardinality' => 'number',
      'cardinality_number' => 6,
    ];
    $this->drupalGet($field_edit_path);
    $this->submitForm($edit, 'Save');
    $this->drupalGet($field_edit_path);
    $this->assertSession()->fieldValueEquals('cardinality', 'number');
    $this->assertSession()->fieldValueEquals('cardinality_number', 6);

    // Check that tabs displayed.
    $this->assertSession()->linkExists('Edit');
    $this->assertSession()->linkByHrefExists('admin/structure/types/manage/article/fields/node.article.body');
    $this->assertSession()->linkExists('Field settings');
    $this->assertSession()->linkByHrefExists($field_edit_path);

    // Add two entries in the body.
    $edit = ['title[0][value]' => 'Cardinality', 'body[0][value]' => 'Body 1', 'body[1][value]' => 'Body 2'];
    $this->drupalGet('node/add/article');
    $this->submitForm($edit, 'Save');

    // Assert that you can't set the cardinality to a lower number than the
    // highest delta of this field.
    $edit = [
      'cardinality' => 'number',
      'cardinality_number' => 1,
    ];
    $this->drupalGet($field_edit_path);
    $this->submitForm($edit, 'Save');
    $this->assertSession()->pageTextContains("There is 1 entity with 2 or more values in this field");

    // Create a second entity with three values.
    $edit = ['title[0][value]' => 'Cardinality 3', 'body[0][value]' => 'Body 1', 'body[1][value]' => 'Body 2', 'body[2][value]' => 'Body 3'];
    $this->drupalGet('node/add/article');
    $this->submitForm($edit, 'Save');

    // Set to unlimited.
    $edit = [
      'cardinality' => FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED,
    ];
    $this->drupalGet($field_edit_path);
    $this->submitForm($edit, 'Save');
    $this->drupalGet($field_edit_path);
    $this->assertSession()->fieldValueEquals('cardinality', FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED);
    $this->assertSession()->fieldValueEquals('cardinality_number', 1);

    // Assert that you can't set the cardinality to a lower number then the
    // highest delta of this field but can set it to the same.
    $edit = [
      'cardinality' => 'number',
      'cardinality_number' => 1,
    ];
    $this->drupalGet($field_edit_path);
    $this->submitForm($edit, 'Save');
    $this->assertSession()->pageTextContains("There are 2 entities with 2 or more values in this field");

    $edit = [
      'cardinality' => 'number',
      'cardinality_number' => 2,
    ];
    $this->drupalGet($field_edit_path);
    $this->submitForm($edit, 'Save');
    $this->assertSession()->pageTextContains("There is 1 entity with 3 or more values in this field");

    $edit = [
      'cardinality' => 'number',
      'cardinality_number' => 3,
    ];
    $this->drupalGet($field_edit_path);
    $this->submitForm($edit, 'Save');

    // Test the cardinality validation is not access sensitive.

    // Remove the cardinality limit 4 so we can add a node the user doesn't have access to.
    $edit = [
      'cardinality' => (string) FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED,
    ];
    $this->drupalGet($field_edit_path);
    $this->submitForm($edit, 'Save');
    $node = $this->drupalCreateNode([
      'private' => TRUE,
      'uid' => 0,
      'type' => 'article',
    ]);
    $node->body->appendItem('body 1');
    $node->body->appendItem('body 2');
    $node->body->appendItem('body 3');
    $node->body->appendItem('body 4');
    $node->save();

    // Assert that you can't set the cardinality to a lower number then the
    // highest delta of this field (including inaccessible entities) but can
    // set it to the same.
    $this->drupalGet($field_edit_path);
    $edit = [
      'cardinality' => 'number',
      'cardinality_number' => 2,
    ];
    $this->drupalGet($field_edit_path);
    $this->submitForm($edit, 'Save');
    $this->assertSession()->pageTextContains("There are 2 entities with 3 or more values in this field");
    $edit = [
      'cardinality' => 'number',
      'cardinality_number' => 3,
    ];
    $this->drupalGet($field_edit_path);
    $this->submitForm($edit, 'Save');
    $this->assertSession()->pageTextContains("There is 1 entity with 4 or more values in this field");
    $edit = [
      'cardinality' => 'number',
      'cardinality_number' => 4,
    ];
    $this->drupalGet($field_edit_path);
    $this->submitForm($edit, 'Save');
  }

  /**
   * Tests deleting a field from the field edit form.
   */
  protected function deleteField() {
    // Delete the field.
    $field_id = 'node.' . $this->contentType . '.' . $this->fieldName;
    $this->drupalGet('admin/structure/types/manage/' . $this->contentType . '/fields/' . $field_id);
    $this->clickLink('Delete');
    $this->assertSession()->statusCodeEquals(200);
  }

  /**
   * Tests that persistent field storage appears in the field UI.
   */
  protected function addPersistentFieldStorage() {
    $field_storage = FieldStorageConfig::loadByName('node', $this->fieldName);
    // Persist the field storage even if there are no fields.
    $field_storage->set('persist_with_no_fields', TRUE)->save();
    // Delete all instances of the field.
    foreach ($field_storage->getBundles() as $node_type) {
      // Delete all the body field instances.
      $this->drupalGet('admin/structure/types/manage/' . $node_type . '/fields/node.' . $node_type . '.' . $this->fieldName);
      $this->clickLink('Delete');
      $this->submitForm([], 'Delete');
    }
    // Check "Re-use existing field" appears.
    $this->drupalGet('admin/structure/types/manage/page/fields');
    $this->assertSession()->pageTextContains('Re-use an existing field');

    // Ensure that we test with a label that contains HTML.
    $label = $this->randomString(4) . '<br/>' . $this->randomString(4);
    // Add a new field for the orphaned storage.
    $this->fieldUIAddExistingField("admin/structure/types/manage/page", $this->fieldName, $label);
  }

  /**
   * Asserts field settings are as expected.
   *
   * @param string $bundle
   *   The bundle name for the field.
   * @param string $field_name
   *   The field name for the field.
   * @param string $string
   *   The settings text.
   * @param string $entity_type
   *   The entity type for the field.
   *
   * @internal
   */
  protected function assertFieldSettings(string $bundle, string $field_name, string $string = 'dummy test string', string $entity_type = 'node'): void {
    // Assert field storage settings.
    $field_storage = FieldStorageConfig::loadByName($entity_type, $field_name);
    $this->assertSame($string, $field_storage->getSetting('test_field_storage_setting'), 'Field storage settings were found.');

    // Assert field settings.
    $field = FieldConfig::loadByName($entity_type, $bundle, $field_name);
    $this->assertSame($string, $field->getSetting('test_field_setting'), 'Field settings were found.');
  }

  /**
   * Tests that the 'field_prefix' setting works on Field UI.
   */
  public function testFieldPrefix() {
    // Change default field prefix.
    $field_prefix = $this->randomMachineName(10);
    $this->config('field_ui.settings')->set('field_prefix', $field_prefix)->save();

    // Create a field input and label exceeding the new maxlength, which is 22.
    $field_exceed_max_length_label = $this->randomString(23);
    $field_exceed_max_length_input = $this->randomMachineName(23);

    // Try to create the field.
    $edit = [
      'label' => $field_exceed_max_length_label,
      'field_name' => $field_exceed_max_length_input,
    ];
    $this->drupalGet('admin/structure/types/manage/' . $this->contentType . '/fields/add-field');
    $this->submitForm($edit, 'Continue');
    $this->assertSession()->pageTextContains('Machine-readable name cannot be longer than 22 characters but is currently 23 characters long.');

    // Create a valid field.
    $this->fieldUIAddNewField('admin/structure/types/manage/' . $this->contentType, $this->fieldNameInput, $this->fieldLabel);
    $this->drupalGet('admin/structure/types/manage/' . $this->contentType . '/fields/node.' . $this->contentType . '.' . $field_prefix . $this->fieldNameInput);
    $this->assertSession()->pageTextContains($this->fieldLabel . ' settings for ' . $this->contentType);
  }

  /**
   * Tests that default value is correctly validated and saved.
   */
  public function testDefaultValue() {
    // Create a test field storage and field.
    $field_name = 'test';
    FieldStorageConfig::create([
      'field_name' => $field_name,
      'entity_type' => 'node',
      'type' => 'test_field',
    ])->save();
    $field = FieldConfig::create([
      'field_name' => $field_name,
      'entity_type' => 'node',
      'bundle' => $this->contentType,
    ]);
    $field->save();

    /** @var \Drupal\Core\Entity\EntityDisplayRepositoryInterface $display_repository */
    $display_repository = \Drupal::service('entity_display.repository');

    $display_repository->getFormDisplay('node', $this->contentType)
      ->setComponent($field_name)
      ->save();

    $admin_path = 'admin/structure/types/manage/' . $this->contentType . '/fields/' . $field->id();
    $element_id = "edit-default-value-input-$field_name-0-value";
    $element_name = "default_value_input[{$field_name}][0][value]";
    $this->drupalGet($admin_path);
    $this->assertSession()->fieldValueEquals($element_id, '');

    // Check that invalid default values are rejected.
    $edit = [$element_name => '-1', 'set_default_value' => '1'];
    $this->drupalGet($admin_path);
    $this->submitForm($edit, 'Save settings');
    $this->assertSession()->pageTextContains("$field_name does not accept the value -1");

    // Check that the default value is saved.
    $edit = [$element_name => '1', 'set_default_value' => '1'];
    $this->drupalGet($admin_path);
    $this->submitForm($edit, 'Save settings');
    $this->assertSession()->pageTextContains("Saved $field_name configuration");
    $field = FieldConfig::loadByName('node', $this->contentType, $field_name);
    $this->assertEquals([['value' => 1]], $field->getDefaultValueLiteral(), 'The default value was correctly saved.');

    // Check that the default value shows up in the form.
    $this->drupalGet($admin_path);
    $this->assertSession()->fieldValueEquals($element_id, '1');

    // Check that the default value is left empty when "Set default value"
    // checkbox is not checked.
    $edit = [$element_name => '1', 'set_default_value' => '0'];
    $this->drupalGet($admin_path);
    $this->submitForm($edit, 'Save settings');
    $this->assertSession()->pageTextContains("Saved $field_name configuration");
    $field = FieldConfig::loadByName('node', $this->contentType, $field_name);
    $this->assertEquals([], $field->getDefaultValueLiteral(), 'The default value was removed.');

    // Check that the default value can be emptied.
    $this->drupalGet($admin_path);
    $edit = [$element_name => ''];
    $this->submitForm($edit, 'Save settings');
    $this->assertSession()->pageTextContains("Saved $field_name configuration");
    $field = FieldConfig::loadByName('node', $this->contentType, $field_name);
    $this->assertEquals([], $field->getDefaultValueLiteral(), 'The default value was correctly saved.');

    // Check that the default value can be empty when the field is marked as
    // required and can store unlimited values.
    $field_storage = FieldStorageConfig::loadByName('node', $field_name);
    $field_storage->setCardinality(FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED);
    $field_storage->save();

    $this->drupalGet($admin_path);
    $edit = [
      'required' => 1,
    ];
    $this->submitForm($edit, 'Save settings');

    $this->drupalGet($admin_path);
    $this->submitForm([], 'Save settings');
    $this->assertSession()->pageTextContains("Saved $field_name configuration");
    $field = FieldConfig::loadByName('node', $this->contentType, $field_name);
    $this->assertEquals([], $field->getDefaultValueLiteral(), 'The default value was correctly saved.');

    // Check that the default widget is used when the field is hidden.
    $display_repository->getFormDisplay($field->getTargetEntityTypeId(), $field->getTargetBundle())
      ->removeComponent($field_name)
      ->save();
    $this->drupalGet($admin_path);
    $this->assertSession()->fieldValueEquals($element_id, '');
  }

  /**
   * Tests that deletion removes field storages and fields as expected.
   */
  public function testDeleteField() {
    // Create a new field.
    $bundle_path1 = 'admin/structure/types/manage/' . $this->contentType;
    $this->fieldUIAddNewField($bundle_path1, $this->fieldNameInput, $this->fieldLabel);

    // Create an additional node type.
    $type_name2 = $this->randomMachineName(8) . '_test';
    $type2 = $this->drupalCreateContentType(['name' => $type_name2, 'type' => $type_name2]);
    $type_name2 = $type2->id();

    // Add a field to the second node type.
    $bundle_path2 = 'admin/structure/types/manage/' . $type_name2;
    $this->fieldUIAddExistingField($bundle_path2, $this->fieldName, $this->fieldLabel);

    // Delete the first field.
    $this->fieldUIDeleteField($bundle_path1, "node.$this->contentType.$this->fieldName", $this->fieldLabel, $this->contentType);

    // Check that the field was deleted.
    $this->assertNull(FieldConfig::loadByName('node', $this->contentType, $this->fieldName), 'Field was deleted.');
    // Check that the field storage was not deleted.
    $this->assertNotNull(FieldStorageConfig::loadByName('node', $this->fieldName), 'Field storage was not deleted.');

    // Delete the second field.
    $this->fieldUIDeleteField($bundle_path2, "node.$type_name2.$this->fieldName", $this->fieldLabel, $type_name2);

    // Check that the field was deleted.
    $this->assertNull(FieldConfig::loadByName('node', $type_name2, $this->fieldName), 'Field was deleted.');
    // Check that the field storage was deleted too.
    $this->assertNull(FieldStorageConfig::loadByName('node', $this->fieldName), 'Field storage was deleted.');
  }

  /**
   * Tests that Field UI respects disallowed field names.
   */
  public function testDisallowedFieldNames() {
    // Reset the field prefix so we can test properly.
    $this->config('field_ui.settings')->set('field_prefix', '')->save();

    $label = 'Disallowed field';
    $edit = [
      'label' => $label,
      'new_storage_type' => 'test_field',
    ];

    // Try with an entity key.
    $edit['field_name'] = 'title';
    $bundle_path = 'admin/structure/types/manage/' . $this->contentType;
    $this->drupalGet("{$bundle_path}/fields/add-field");
    $this->submitForm($edit, 'Continue');
    $this->assertSession()->pageTextContains('The machine-readable name is already in use. It must be unique.');

    // Try with a base field.
    $edit['field_name'] = 'sticky';
    $bundle_path = 'admin/structure/types/manage/' . $this->contentType;
    $this->drupalGet("{$bundle_path}/fields/add-field");
    $this->submitForm($edit, 'Continue');
    $this->assertSession()->pageTextContains('The machine-readable name is already in use. It must be unique.');
  }

  /**
   * Tests that Field UI respects locked fields.
   */
  public function testLockedField() {
    // Create a locked field and attach it to a bundle. We need to do this
    // programmatically as there's no way to create a locked field through UI.
    $field_name = $this->randomMachineName(8);
    $field_storage = FieldStorageConfig::create([
      'field_name' => $field_name,
      'entity_type' => 'node',
      'type' => 'test_field',
      'cardinality' => 1,
      'locked' => TRUE,
    ]);
    $field_storage->save();
    FieldConfig::create([
      'field_storage' => $field_storage,
      'bundle' => $this->contentType,
    ])->save();
    \Drupal::service('entity_display.repository')
      ->getFormDisplay('node', $this->contentType)
      ->setComponent($field_name, [
        'type' => 'test_field_widget',
      ])
      ->save();

    // Check that the links for edit and delete are not present.
    $this->drupalGet('admin/structure/types/manage/' . $this->contentType . '/fields');
    $locked = $this->xpath('//tr[@id=:field_name]/td[4]', [':field_name' => $field_name]);
    $this->assertSame('Locked', $locked[0]->getHtml(), 'Field is marked as Locked in the UI');
    $this->drupalGet('admin/structure/types/manage/' . $this->contentType . '/fields/node.' . $this->contentType . '.' . $field_name . '/delete');
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * Tests that Field UI respects the 'no_ui' flag in the field type definition.
   */
  public function testHiddenFields() {
    // Check that the field type is not available in the 'add new field' row.
    $this->drupalGet('admin/structure/types/manage/' . $this->contentType . '/fields/add-field');
    $this->assertSession()->elementNotExists('css', "[name='new_storage_type'][value='hidden_test_field']");
    $this->assertSession()->elementExists('css', "[name='new_storage_type'][value='shape']");

    // Create a field storage and a field programmatically.
    $field_name = 'hidden_test_field';
    FieldStorageConfig::create([
      'field_name' => $field_name,
      'entity_type' => 'node',
      'type' => $field_name,
    ])->save();
    $field = [
      'field_name' => $field_name,
      'bundle' => $this->contentType,
      'entity_type' => 'node',
      'label' => 'Hidden field',
    ];
    FieldConfig::create($field)->save();
    \Drupal::service('entity_display.repository')
      ->getFormDisplay('node', $this->contentType)
      ->setComponent($field_name)
      ->save();
    $this->assertInstanceOf(FieldConfig::class, FieldConfig::load('node.' . $this->contentType . '.' . $field_name));

    // Check that the newly added field appears on the 'Manage Fields'
    // screen.
    $this->drupalGet('admin/structure/types/manage/' . $this->contentType . '/fields');
    $this->assertSession()->elementTextContains('xpath', '//table[@id="field-overview"]//tr[@id="hidden-test-field"]//td[1]', $field['label']);

    // Check that the field does not appear in the 're-use existing field' row
    // on other bundles.
    $this->drupalGet('admin/structure/types/manage/page/fields/reuse');
    $this->assertSession()->elementNotExists('css', ".js-reuse-table [data-field-id='{$field_name}']");
    $this->assertSession()->elementExists('css', '.js-reuse-table [data-field-id="field_tags"]');

    // Check that non-configurable fields are not available.
    $field_types = \Drupal::service('plugin.manager.field.field_type')->getDefinitions();
    $this->drupalGet('admin/structure/types/manage/page/fields/add-field');
    foreach ($field_types as $field_type => $definition) {
      if (empty($definition['no_ui'])) {
        try {
          $this->assertSession()
            ->elementExists('css', "[name='new_storage_type'][value='$field_type']");
        }
        catch (ElementNotFoundException) {
          if ($this->getFieldFromGroup($field_type)) {
            $this->assertSession()
              ->elementExists('css', "[name='group_field_options_wrapper'][value='$field_type']");
          }
        }
      }
      else {
        $this->assertSession()->elementNotExists('css', "[name='new_storage_type'][value='$field_type']");
      }
    }
  }

  /**
   * Tests that a duplicate field name is caught by validation.
   */
  public function testDuplicateFieldName() {
    // field_tags already exists, so we're expecting an error when trying to
    // create a new field with the same name.
    $url = 'admin/structure/types/manage/' . $this->contentType . '/fields/add-field';
    $this->drupalGet($url);
    $edit = [
      'label' => $this->randomMachineName(),
      'field_name' => 'tags',
      'new_storage_type' => 'boolean',
    ];
    $this->submitForm($edit, 'Continue');

    $this->assertSession()->pageTextContains('The machine-readable name is already in use. It must be unique.');
    $this->assertSession()->addressEquals($url);
  }

  /**
   * Tests that external URLs in the 'destinations' query parameter are blocked.
   */
  public function testExternalDestinations() {
    $options = [
      'query' => ['destinations' => ['http://example.com']],
    ];
    $this->drupalGet('admin/structure/types/manage/article/fields/node.article.body/storage', $options);
    $this->submitForm([], 'Save');
    // The external redirect should not fire.
    $this->assertSession()->addressEquals('admin/structure/types/manage/article/fields/node.article.body/storage?destinations%5B0%5D=http%3A//example.com');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseContains('Attempt to update field <em class="placeholder">Body</em> failed: <em class="placeholder">The internal path component &#039;http://example.com&#039; is external. You are not allowed to specify an external URL together with internal:/.</em>.');
  }

  /**
   * Tests that deletion removes field storages and fields as expected for a term.
   */
  public function testDeleteTaxonomyField() {
    // Create a new field.
    $bundle_path = 'admin/structure/taxonomy/manage/tags/overview';

    $this->fieldUIAddNewField($bundle_path, $this->fieldNameInput, $this->fieldLabel);

    // Delete the field.
    $this->fieldUIDeleteField($bundle_path, "taxonomy_term.tags.$this->fieldName", $this->fieldLabel, 'Tags');

    // Check that the field was deleted.
    $this->assertNull(FieldConfig::loadByName('taxonomy_term', 'tags', $this->fieldName), 'Field was deleted.');
    // Check that the field storage was deleted too.
    $this->assertNull(FieldStorageConfig::loadByName('taxonomy_term', $this->fieldName), 'Field storage was deleted.');
  }

  /**
   * Tests that help descriptions render valid HTML.
   */
  public function testHelpDescriptions() {
    // Create an image field.
    FieldStorageConfig::create([
      'field_name' => 'field_image',
      'entity_type' => 'node',
      'type' => 'image',
    ])->save();

    FieldConfig::create([
      'field_name' => 'field_image',
      'entity_type' => 'node',
      'label' => 'Image',
      'bundle' => 'article',
    ])->save();

    \Drupal::service('entity_display.repository')
      ->getFormDisplay('node', 'article')
      ->setComponent('field_image')
      ->save();

    $edit = [
      'description' => '<strong>Test with an upload field.',
    ];
    $this->drupalGet('admin/structure/types/manage/article/fields/node.article.field_image');
    $this->submitForm($edit, 'Save settings');

    // Check that hook_field_widget_single_element_form_alter() does believe
    // this is the default value form.
    $this->drupalGet('admin/structure/types/manage/article/fields/node.article.field_tags');
    $this->assertSession()->pageTextContains('From hook_field_widget_single_element_form_alter(): Default form is true.');

    $edit = [
      'description' => '<em>Test with a non upload field.',
    ];
    $this->drupalGet('admin/structure/types/manage/article/fields/node.article.field_tags');
    $this->submitForm($edit, 'Save settings');

    $this->drupalGet('node/add/article');
    $this->assertSession()->responseContains('<strong>Test with an upload field.</strong>');
    $this->assertSession()->responseContains('<em>Test with a non upload field.</em>');
  }

  /**
   * Tests that the field list administration page operates correctly.
   */
  public function fieldListAdminPage() {
    $this->drupalGet('admin/reports/fields');
    $this->assertSession()->pageTextContains($this->fieldName);
    $this->assertSession()->linkByHrefExists('admin/structure/types/manage/' . $this->contentType . '/fields');
  }

  /**
   * Tests the "preconfigured field" functionality.
   *
   * @see \Drupal\Core\Field\PreconfiguredFieldUiOptionsInterface
   */
  public function testPreconfiguredFields() {
    $this->drupalGet('admin/structure/types/manage/article/fields/add-field');

    // Check that the preconfigured field option exist alongside the regular
    // field type option.
    $this->assertSession()->elementExists('css', "[name='new_storage_type'][value='field_ui:test_field_with_preconfigured_options:custom_options']");
    $this->assertSession()->elementExists('css', "[name='new_storage_type'][value='test_field_with_preconfigured_options']");

    // Add a field with every possible preconfigured value.
    $this->fieldUIAddNewField(NULL, 'test_custom_options', 'Test label', 'field_ui:test_field_with_preconfigured_options:custom_options');
    $field_storage = FieldStorageConfig::loadByName('node', 'field_test_custom_options');
    $this->assertEquals(FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED, $field_storage->getCardinality());
    $this->assertEquals('preconfigured_storage_setting', $field_storage->getSetting('test_field_storage_setting'));

    $field = FieldConfig::loadByName('node', 'article', 'field_test_custom_options');
    $this->assertTrue($field->isRequired());
    $this->assertEquals('preconfigured_field_setting', $field->getSetting('test_field_setting'));

    /** @var \Drupal\Core\Entity\EntityDisplayRepositoryInterface $display_repository */
    $display_repository = \Drupal::service('entity_display.repository');

    $form_display = $display_repository->getFormDisplay('node', 'article');
    $this->assertEquals('test_field_widget_multiple', $form_display->getComponent('field_test_custom_options')['type']);
    $view_display = $display_repository->getViewDisplay('node', 'article');
    $this->assertEquals('field_test_multiple', $view_display->getComponent('field_test_custom_options')['type']);
    $this->assertEquals('altered dummy test string', $view_display->getComponent('field_test_custom_options')['settings']['test_formatter_setting_multiple']);
  }

  /**
   * Tests the access to non-existent field URLs.
   */
  public function testNonExistentFieldUrls() {
    $field_id = 'node.foo.bar';

    $this->drupalGet('admin/structure/types/manage/' . $this->contentType . '/fields/' . $field_id);
    $this->assertSession()->statusCodeEquals(404);

    $this->drupalGet('admin/structure/types/manage/' . $this->contentType . '/fields/' . $field_id . '/storage');
    $this->assertSession()->statusCodeEquals(404);
  }

  /**
   * Test translation defaults.
   */
  public function testTranslationDefaults() {
    $this->createField();
    $field_storage = FieldStorageConfig::loadByName('node', 'field_' . $this->fieldNameInput);
    $this->assertTrue($field_storage->isTranslatable(), 'Field storage translatable.');

    $field = FieldConfig::loadByName('node', $this->contentType, 'field_' . $this->fieldNameInput);
    $this->assertFalse($field->isTranslatable(), 'Field instance should not be translatable by default.');

    // Add a new field based on an existing field.
    $this->drupalCreateContentType(['type' => 'additional', 'name' => 'Additional type']);
    $this->fieldUIAddExistingField("admin/structure/types/manage/additional", $this->fieldName, 'Additional type');

    $field_storage = FieldStorageConfig::loadByName('node', 'field_' . $this->fieldNameInput);
    $this->assertTrue($field_storage->isTranslatable(), 'Field storage translatable.');

    $field = FieldConfig::loadByName('node', 'additional', 'field_' . $this->fieldNameInput);
    $this->assertFalse($field->isTranslatable(), 'Field instance should not be translatable by default.');
  }

}
