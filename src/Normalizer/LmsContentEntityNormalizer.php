<?php

declare(strict_types=1);

namespace Drupal\lms_default_content\Normalizer;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\Plugin\DataType\EntityReference;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\TypedData\DataReferenceTargetDefinition;
use Drupal\Core\TypedData\Exception\MissingDataException;
use Drupal\Core\TypedData\TypedDataInterface;
use Drupal\default_content\Normalizer\ContentEntityNormalizer;
use Drupal\lms\Plugin\Field\FieldType\LMSReferenceItem;
use Drupal\user\UserInterface;

/**
 * Extends the default_content normalizer to handle lms_reference fields.
 *
 * The lms_reference field type stores both an entity reference and a
 * serialized data blob containing LMS metadata (mandatory, required_score,
 * time_limit, auto_repeat_failed, max_score). The parent normalizer treats
 * the entity reference via the internal 'entity' property name and has no
 * awareness of the 'data' blob structure.
 *
 * This normalizer:
 *  - On normalize: emits a flat structure per item —
 *      target_uuid, target_type, plus each data key at the top level.
 *  - On denormalize: reconstructs the lms_reference item from that flat
 *      structure, resolving the UUID back to a target_id.
 *
 * All other field types delegate to the parent implementation unchanged.
 */
final class LmsContentEntityNormalizer extends ContentEntityNormalizer {

  private const string FIELD_TYPE = 'lms_reference';

  /**
   * Reserved keys that are not part of the data blob.
   */
  private const array RESERVED_KEYS = ['target_uuid', 'target_type'];

  /**
   * {@inheritdoc}
   *
   * Extends the parent to also exclude computed fields, which the parent only
   * partially covers (it filters internal and read-only but not computed).
   */
  protected function getFieldsToNormalize(ContentEntityInterface $entity): array {
    return array_filter(
      parent::getFieldsToNormalize($entity),
      static function (string $field_name) use ($entity): bool {
        $storage = $entity->getFieldDefinition($field_name)->getFieldStorageDefinition();
        return !($storage instanceof BaseFieldDefinition && $storage->isComputed());
      },
    );
  }

  /**
   * {@inheritdoc}
   *
   * Extends the parent to handle entity references where the referenced entity
   * is not automatically loaded. The parent relies on $field_item->entity being
   * populated; when it is null (e.g. the entity exists in the DB but was not
   * lazy-loaded), the parent falls through to returning the raw numeric
   * target_id via PrimitiveInterface. This override performs an explicit load
   * by target_id + target_type so a stable UUID is always emitted instead.
   */
  protected function getValueFromProperty(TypedDataInterface $property, FieldItemInterface $field_item, &$normalized_item = NULL) {
    if ($property instanceof EntityReference && !($property->getValue() instanceof ContentEntityInterface)) {
      $target_id = $field_item->get('target_id')->getValue();
      $target_type = $field_item->getFieldDefinition()->getSetting('target_type');
      if ($target_id && $target_type) {
        $entity = $this->entityTypeManager->getStorage($target_type)->load($target_id);
        if ($entity instanceof ContentEntityInterface
            && !($entity instanceof UserInterface && in_array($entity->id(), [0, 1], TRUE))) {
          $this->addDependency($entity);
          return $entity->uuid();
        }
      }
      return NULL;
    }

    // Suppress the raw numeric target_id when the entity reference was not
    // resolved — prevents environment-specific IDs leaking into exported YAML.
    if ($property->getDataDefinition() instanceof DataReferenceTargetDefinition
        && !($field_item->entity instanceof ContentEntityInterface)) {
      return NULL;
    }

    return parent::getValueFromProperty($property, $field_item, $normalized_item);
  }

  /**
   * {@inheritdoc}
   */
  protected function normalizeTranslation(ContentEntityInterface $translation, array $field_names): array {
    // Separate lms_reference fields from everything else so the parent handles
    // its own field iteration in one pass.
    $lms_fields = [];
    $other_fields = [];
    foreach ($field_names as $field_name) {
      if ($translation->getFieldDefinition($field_name)->getType() === self::FIELD_TYPE) {
        $lms_fields[] = $field_name;
      }
      else {
        $other_fields[] = $field_name;
      }
    }

    $normalization = parent::normalizeTranslation($translation, $other_fields);

    foreach ($lms_fields as $field_name) {
      foreach ($translation->get($field_name) as $delta => $item) {
        assert($item instanceof LMSReferenceItem);
        if ($item->isEmpty()) {
          continue;
        }

        $entity = $item->entity;
        if (!$entity instanceof ContentEntityInterface) {
          continue;
        }

        $this->addDependency($entity);

        $item_value = [
          'target_uuid' => $entity->uuid(),
          'target_type' => $entity->getEntityTypeId(),
        ];

        // Flatten data keys alongside the reference so the YAML is readable
        // without nesting: mandatory, auto_repeat_failed, required_score, etc.
        try {
          $data = $item->get('data')->getValue();
        }
        catch (MissingDataException) {
          $data = [];
        }
        if (!empty($data) && is_array($data)) {
          $item_value += $data;
        }

        $normalization[$field_name][$delta] = $item_value;
      }
    }

    return $normalization;
  }

  /**
   * {@inheritdoc}
   */
  protected function setFieldValues(ContentEntityInterface $entity, string $field_name, array $values): void {
    if (!$entity->hasField($field_name)) {
      return;
    }

    if ($entity->getFieldDefinition($field_name)->getType() !== self::FIELD_TYPE) {
      parent::setFieldValues($entity, $field_name, $values);
      return;
    }

    $items = [];
    foreach ($values as $item_value) {
      if (empty($item_value['target_uuid'])) {
        continue;
      }

      $target_entity = $this->loadEntityDependency($item_value['target_uuid']);
      if (!$target_entity instanceof ContentEntityInterface) {
        continue;
      }

      // Everything except the reference keys is LMS metadata for the data blob.
      $data = array_diff_key($item_value, array_flip(self::RESERVED_KEYS));

      $items[] = [
        'target_id' => $target_entity->id(),
        'data' => $data,
      ];
    }

    $entity->set($field_name, $items);
  }

}
