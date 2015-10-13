<?php

namespace Supertext\Polylang\Helper;

/**
 * The ACF Custom Field provider
 * @package Supertext\Polylang\Helper
 */
class AcfCustomFieldProvider
{
  const PLUGIN_NAME = 'Advanced Custom Fields';

  public function getPluginName(){
    return self::PLUGIN_NAME;
  }

  /**
   * @return array
   */
  public function getCustomFields()
  {
    $fieldGroups = acf_get_field_groups();
    $customFields = array();

    foreach ($fieldGroups as $fieldGroup) {
      $fields = acf_get_fields($fieldGroup);

      $customFields[] = array(
        'id' => $fieldGroup['key'],
        'label' => $fieldGroup['title'],
        'fields' => $this->getFields($fields)
      );
    }

    return $customFields;
  }

  private function getFields($fields, $metaKeyPrefix = ''){
    $group = array();

    foreach ($fields as $field) {
      $group[] = array(
        'id' => $field['key'],
        'label' => $field['label'],
        'meta_key' => $metaKeyPrefix.$field['name'],
        'fields' => isset($field['sub_fields']) ? $this->getFields($field['sub_fields'], $field['name'].'_\\d+_') : array()
      );
    }

    return $group;
  }
}