<?php

use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Implements hook_post_update_NAME().
 */
function format_strawberryfield_post_update_make_metadatadisplay_entity_revisionable(&$sandbox) {

  \Drupal::entityTypeManager()->clearCachedDefinitions();
  $definition_update_manager = \Drupal::entityDefinitionUpdateManager();
  /** @var \Drupal\Core\Entity\EntityLastInstalledSchemaRepositoryInterface $last_installed_schema_repository */
  $last_installed_schema_repository = \Drupal::service('entity.last_installed_schema.repository');

  $entity_type = $definition_update_manager->getEntityType('metadatadisplay_entity');
  $field_storage_definitions = $last_installed_schema_repository->getLastInstalledFieldStorageDefinitions('metadatadisplay_entity');

  // Update the entity type definition.
  $entity_keys = $entity_type->getKeys();
  $entity_keys['revision'] = 'revision_id';
  //$entity_keys['published'] = 'status';
  $entity_type->set('entity_keys', $entity_keys);
  $entity_type->set('revision_table', 'metadatadisplay_entity_revision');
  $entity_type->set('revision_data_table', 'metadatadisplay_entity_field_revision');
  $revision_metadata_keys = [
    'revision_default' => 'revision_default',
    'revision_user' => 'revision_user',
    'revision_created' => 'revision_created',
    'revision_log_message' => 'revision_log_message',
  ];
  $entity_type->set('revision_metadata_keys', $revision_metadata_keys);

  // Update the field storage definitions and add the new ones required by a
  // revisionable entity type.
  $field_storage_definitions['langcode']->setRevisionable(TRUE);
  $field_storage_definitions['name']->setRevisionable(TRUE);
  $field_storage_definitions['twig']->setRevisionable(TRUE);
  $field_storage_definitions['changed']->setRevisionable(TRUE);
  $field_storage_definitions['link']->setRevisionable(TRUE);
  $field_storage_definitions['user_id']->setRevisionable(TRUE);
  $field_storage_definitions['created']->setRevisionable(TRUE);
  $field_storage_definitions['mimetype']->setRevisionable(TRUE);

  $field_storage_definitions['revision_id'] = BaseFieldDefinition::create('integer')
    ->setName('revision_id')
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

  $field_storage_definitions['revision_created'] = BaseFieldDefinition::create('created')
    ->setName('revision_created')
    ->setTargetEntityTypeId('metadatadisplay_entity')
    ->setTargetBundle(NULL)
    ->setLabel(new TranslatableMarkup('Revision create time'))
    ->setDescription(new TranslatableMarkup('The time that the current revision was created.'))
    ->setRevisionable(TRUE);
  $field_storage_definitions['revision_user'] = BaseFieldDefinition::create('entity_reference')
    ->setName('revision_user')
    ->setTargetEntityTypeId('metadatadisplay_entity')
    ->setTargetBundle(NULL)
    ->setLabel(new TranslatableMarkup('Revision user'))
    ->setDescription(new TranslatableMarkup('The user ID of the author of the current revision.'))
    ->setSetting('target_type', 'user')
    ->setRevisionable(TRUE);
  $field_storage_definitions['revision_log_message'] = BaseFieldDefinition::create('string_long')
    ->setName('revision_log_message')
    ->setTargetEntityTypeId('metadatadisplay_entity')
    ->setTargetBundle(NULL)
    ->setLabel(new TranslatableMarkup('Revision log message'))
    ->setDescription(new TranslatableMarkup('Briefly describe the changes you have made.'))
    ->setRevisionable(TRUE)
    ->setDefaultValue('');

  $field_storage_definitions['status'] = BaseFieldDefinition::create('boolean')
    ->setName('status')
    ->setLabel(t('Publishing status'))
    ->setDescription(t('A boolean indicating the published state.'))
    ->setTargetEntityTypeId('metadatadisplay_entity')
    ->setRevisionable(TRUE)
    ->setTranslatable(TRUE)
    ->setDefaultValue(TRUE)
    ->setInitialValue(TRUE)
    ->setDisplayOptions('form', [
      'type' => 'boolean_checkbox',
      'settings' => [
        'display_label' => TRUE,
      ],
    ])
    ->setDisplayConfigurable('form', TRUE);


  $definition_update_manager->updateFieldableEntityType($entity_type, $field_storage_definitions, $sandbox);

  return t('Metadata Display Entities have been converted to be revisionable.');
}
