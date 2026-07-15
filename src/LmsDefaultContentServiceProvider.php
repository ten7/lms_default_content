<?php

declare(strict_types=1);

namespace Drupal\lms_default_content;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;
use Drupal\lms_default_content\Normalizer\LmsContentEntityNormalizer;

/**
 * Replaces the default_content normalizer with an LMS-aware implementation.
 *
 * Uses the service provider alter pattern so the override is guaranteed
 * regardless of module weight — no YAML service ID collision needed.
 */
class LmsDefaultContentServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container): void {
    if ($container->hasDefinition('default_content.content_entity_normalizer')) {
      $container->getDefinition('default_content.content_entity_normalizer')
        ->setClass(LmsContentEntityNormalizer::class);
    }
  }

}
