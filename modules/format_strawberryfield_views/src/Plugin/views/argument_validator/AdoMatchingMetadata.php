<?php

namespace Drupal\format_strawberryfield_views\Plugin\views\argument_validator;

use Drupal\Core\Form\FormStateInterface;
use Drupal\views\Plugin\views\argument_validator\Entity;

/**
* Validate whether an argument matches a provided regex pattern.
*
* @ingroup views_argument_validate_plugins
*
* @ViewsArgumentValidator(
*   id = "ado_matching_metadata",
*   title = @Translation("Is ADO and matches Metadata constraints"),
 *  entity_type = "node"
* )
*/
class AdoMatchingMetadata extends Entity {

/**
* {@inheritdoc}
*/
protected function defineOptions(): array {
$options = parent::defineOptions();
$options['pattern'] = ['default' => ''];
return $options;
}

/**
* {@inheritdoc}
*/
public function buildOptionsForm(&$form, FormStateInterface $form_state): void {

$form['pattern'] = [
'#type' => 'textfield',
'#title' => $this->t('Regular expression'),
'#description' => $this->t('Regex pattern to use for validating the
argument.'),
'#default_value' => $this->options['pattern'],
'#states' => [
'required' => [
':input[name="options[specify_validation]"]' => ['checked' => TRUE],
':input[name="options[validate][type]"]' => ['value' => 'ado_matching_metadata'],
],
],
];

parent::buildOptionsForm($form, $form_state);
}

/**
* {@inheritdoc}
*/
public function validateOptionsForm(&$form, FormStateInterface $form_state): void {
$form_path = ['options', 'validate', 'options', 'ado_matching_metadata', 'pattern'];
$pattern = $form_state->getValue($form_path);

// Ensure that the pattern is valid by testing it against a NULL value. This
// will return "0" for a valid pattern and FALSE for an invalid one, so the
// result (`$valid`) must be strict type checked. Pattern may be empty (e.g.
// if the Regex validator is selected but validation is disabled).
if (!empty($pattern)) {
$valid = @preg_match($pattern, NULL);
if ($valid === FALSE) {
$form_state->setErrorByName(
implode('][', $form_path),
$this->t('Invalid JMESPATH.')
);
}
}

parent::validateOptionsForm($form, $form_state);
}

/**
* {@inheritdoc}
*/
public function validateArgument($arg): bool {

  // Returns true if no ado types are selected and negate option is disabled.
  if (empty($this->options['jmespath']) && $this->options['negated']) {
    return TRUE;
  }
  /** @var \Drupal\node\Entity\Node $node */
  $entity = $this->getContextValue('node');

  $ado_types = [];
  if ($sbf_fields = $this->strawberryfieldUtilityService->bearsStrawberryfield($entity)) {
    foreach ($sbf_fields as $field_name) {
      /* @var \Drupal\strawberryfield\Plugin\Field\FieldType\StrawberryFieldItem $field */
      $field = $entity->get($field_name);
      if (!$field->isEmpty()) {
        foreach ($field->getIterator() as $delta => $itemfield) {
          /** @var \Drupal\strawberryfield\Plugin\Field\FieldType\StrawberryFieldItem $itemfield */
          if($this->configuration['recurse_ado_types']) {
            $values = (array) $itemfield->provideFlatten();
          }
          else {
            $values = (array) $itemfield->provideDecoded();
          }
          if (isset($values['type'])) {
            $ado_types = array_merge($ado_types, (array) $values['type']);
          }
        }
      }
    }
  }
  else {
    // This not an entity type that bears a strawberryfield. Therefore, this condition can not apply.
    // However, it could still be negated and cause in a "TRUE" evaluation to flip and result in the "FALSE"
    // being returned. Deal with that by removing the negation.
    return TRUE;
  }
  if(!empty($ado_types)) {
    // Return true if any of the entity's types are in the condition's ado_types.
    return (count(array_intersect($this->configuration['ado_types'], $ado_types)) > 0);
  }
  // Default, return not matched.
  return FALSE;



return (bool) preg_match($this->options['pattern'], $arg);
}

}
