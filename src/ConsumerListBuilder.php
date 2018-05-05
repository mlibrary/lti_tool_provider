<?php

namespace Drupal\lti_tool_provider;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Url;

/**
 * Defines a class to build a listing of user entities.
 *
 * @see \Drupal\user\Entity\User
 */
class ConsumerListBuilder extends EntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader() {
    $header = [
      'id' => [
        'data' => $this->t('ID'),
        'field' => 'id',
        'specifier' => 'id',
      ],
      'consumer' => [
        'data' => $this->t('Label'),
        'field' => 'consumer',
        'specifier' => 'consumer',
        'class' => [RESPONSIVE_PRIORITY_LOW],
      ],
      'consumer_key' => [
        'data' => $this->t('Label'),
        'field' => 'consumer_key',
        'specifier' => 'consumer_key',
        'class' => [RESPONSIVE_PRIORITY_LOW],
      ],
      'consumer_secret' => [
        'data' => $this->t('Label'),
        'field' => 'consumer_secret',
        'specifier' => 'consumer_secret',
        'class' => [RESPONSIVE_PRIORITY_LOW],
      ],
      'created' => [
        'data' => $this->t('Created'),
        'field' => 'created',
        'specifier' => 'created',
        'sort' => 'desc',
        'class' => [RESPONSIVE_PRIORITY_LOW],
      ],
    ];

    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity) {
    $row = [
      'id' => $entity->id(),
      'consumer' => \Drupal::l(
        $this->getLabel($entity),
        Url::fromRoute('entity.lti_tool_provider_consumer.canonical',
          ['lti_tool_provider_consumer' => $entity->id()]
        )
      ),
      'consumer_key' => $entity->consumer_key->value,
      'consumer_secret' => $entity->consumer_secret->value,
      'created' => format_date($entity->created->value),
    ];

    return $row + parent::buildRow($entity);
  }

  /**
   * {@inheritdoc}
   */
  public function render() {
    $build = parent::render();

    $build['table']['#empty'] = $this->t('No consumers found.');

    return $build;
  }

}
