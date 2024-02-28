<?php

use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Update metadatadisplay_entity to be revisionable.
 */

function format_strawberryfield_post_update_make_metadatadisplay_entity_revisionable(&$sandbox) {
  $definition_update_manager = \Drupal::entityDefinitionUpdateManager();
  /** @var \Drupal\Core\Entity\EntityLastInstalledSchemaRepositoryInterface $last_installed_schema_repository */
  $last_installed_schema_repository = \Drupal::service('entity.last_installed_schema.repository');

  $entity_type = $definition_update_manager->getEntityType('metadatadisplay_entity');
  $field_storage_definitions = $last_installed_schema_repository->getLastInstalledFieldStorageDefinitions('metadatadisplay_entity');

  // Update the entity type definition.
  $entity_keys = $entity_type->getKeys();
  $entity_keys['revision'] = 'vid';
  $entity_keys['revision_translation_affected'] = 'revision_translation_affected';
  $entity_type->set('entity_keys', $entity_keys);
  $entity_type->set('revision_table', 'strawberryfield_metadatadisplay_revision');
  $entity_type->set('revision_data_table', 'strawberryfield_metadatadisplay_field_revision');
  $revision_metadata_keys = [
    'revision_default' => 'revision_default',
    'revision_user' => 'revision_user',
    'revision_created' => 'revision_created',
    'revision_log_message' => 'revision_log_message',
  ];
  $entity_type->set('revision_metadata_keys', $revision_metadata_keys);

  // Update the field storage definitions and add the new ones required by a
  // revisionable entity type.
  $field_storage_definitions['name']->setRevisionable(TRUE);
  $field_storage_definitions['twig']->setRevisionable(TRUE);
  $field_storage_definitions['changed']->setRevisionable(TRUE);
  $field_storage_definitions['mimetype']->setRevisionable(TRUE);

  $field_storage_definitions['vid'] = BaseFieldDefinition::create('integer')
    ->setName('vid')
    ->setTargetEntityTypeId('metadatadisplay_entity')
    ->setTargetBundle(NULL)
    ->setLabel(new TranslatableMarkup('Revision ID'))
    ->setReadOnly(TRUE)
    ->setSetting('unsigned', TRUE);

  $field_storage_definitions['revision_default'] = BaseFieldDefinition::create('boolean')
    ->setName('revision_default')
    ->setTargetEntityTypeId('metadatadisplay_entity')
    ->setTargetBundle(NULL)
    ->setLabel(new TranslatableMarkup('Default revision'))
    ->setDescription(new TranslatableMarkup('A flag indicating whether this was a default revision when it was saved.'))
    ->setStorageRequired(TRUE)
    ->setInternal(TRUE)
    ->setTranslatable(FALSE)
    ->setRevisionable(TRUE);

  $field_storage_definitions['revision_translation_affected'] = BaseFieldDefinition::create('boolean')
    ->setName('revision_translation_affected')
    ->setTargetEntityTypeId('metadatadisplay_entity')
    ->setTargetBundle(NULL)
    ->setLabel(new TranslatableMarkup('Revision translation affected'))
    ->setDescription(new TranslatableMarkup('Indicates if the last edit of a translation belongs to current revision.'))
    ->setReadOnly(TRUE)
    ->setRevisionable(TRUE)
    ->setTranslatable(TRUE);

  $field_storage_definitions['revision_created'] = BaseFieldDefinition::create('created')
    ->setName('revision_created')
    ->setTargetEntityTypeId('metadatadisplay_entity')
    ->setTargetBundle(NULL)
    ->setLabel(new TranslatableMarkup('Revision create time'))
    ->setDescription(new TranslatableMarkup('The time that the current revision was created.'))
    ->setRevisionable(TRUE)
    ->setInitialValueFromField('created');
  $field_storage_definitions['revision_user'] = BaseFieldDefinition::create('entity_reference')
    ->setName('revision_user')
    ->setTargetEntityTypeId('metadatadisplay_entity')
    ->setTargetBundle(NULL)
    ->setLabel(new TranslatableMarkup('Revision user'))
    ->setDescription(new TranslatableMarkup('The user ID of the author of the current revision.'))
    ->setSetting('target_type', 'user')
    ->setInitialValueFromField('user_id')
    ->setRevisionable(TRUE);
  $field_storage_definitions['revision_log_message'] = BaseFieldDefinition::create('string_long')
    ->setName('revision_log_message')
    ->setTargetEntityTypeId('metadatadisplay_entity')
    ->setTargetBundle(NULL)
    ->setLabel(new TranslatableMarkup('Revision log message'))
    ->setDescription(new TranslatableMarkup('Briefly describe the changes you have made.'))
    ->setRevisionable(TRUE)
    ->setDefaultValue('');

  $field_storage_definitions['revision_translation_affected'] = BaseFieldDefinition::create('boolean')
    ->setName('revision_translation_affected')
    ->setLabel(t('Revision translation affected'))
    ->setTargetEntityTypeId('metadatadisplay_entity')
    ->setTargetBundle(NULL)
    ->setDescription(t('Indicates if the last edit of a translation belongs to current revision.'))
    ->setReadOnly(TRUE)
    ->setRevisionable(TRUE)
    ->setTranslatable(TRUE);
  $sandbox = [];
  $definition_update_manager->updateFieldableEntityType($entity_type, $field_storage_definitions, $sandbox);

  return t('Metadata Display Entity has been converted to be revisionable.');
}
/**
 * Update metadatadisplay_entity revisionable fields without values.
 * @see https://www.drupal.org/project/drupal/issues/3317361
 */
function format_strawberryfield_post_update_make_metadatadisplay_entity_revisionablevalues(&$sandbox) {
  $connection = \Drupal::database();

  $fields = [
    'revision_created' => 'created',
    'revision_user' => 'user_id',
  ];

  $entity_type = \Drupal::entityDefinitionUpdateManager()->getEntityType('metadatadisplay_entity');

  $base_table= 'strawberryfield_metadatadisplay';
  $revision_table = $entity_type->getRevisionTable();

  foreach ($fields as $newFieldName => $existingFieldName) {
    $subQuery = $connection->select($base_table, 'mtd')
      ->fields('mtd', [$existingFieldName])
      ->where("$revision_table.id = mtd.id AND $revision_table.vid = mtd.vid");

    $connection->update($revision_table)
      ->expression($newFieldName, $subQuery)
      ->isNull($newFieldName)
      ->execute();
  }
  return t('Metadata Display Entity Default revisions have been updated with default values for created and user.');
}
