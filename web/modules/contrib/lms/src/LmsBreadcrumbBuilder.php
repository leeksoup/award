<?php

declare(strict_types=1);

namespace Drupal\lms;

use Drupal\Core\Breadcrumb\BreadcrumbBuilderInterface;
use Drupal\Core\Breadcrumb\Breadcrumb;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Link;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Class to define the comment breadcrumb builder.
 */
class LmsBreadcrumbBuilder implements BreadcrumbBuilderInterface {

  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  public function applies(RouteMatchInterface $route_match, ?CacheableMetadata $cacheable_metadata = NULL) {
    // @todo Remove null safe operator in Drupal 12.0.0, see
    //   https://www.drupal.org/project/drupal/issues/3459277.
    $cacheable_metadata?->addCacheContexts(['route.name']);
    return \in_array($route_match->getRouteName(), [
      'lms.group.answer_form',
      'lms.group.results',
    ], TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function build(RouteMatchInterface $route_match) {
    $breadcrumb = new Breadcrumb();
    $breadcrumb->addCacheContexts(['route']);
    $breadcrumb->addLink(Link::createFromRoute($this->t('Home'), '<front>'));

    /** @var \Drupal\lms\Entity\Bundle\Course $course */
    $course = $route_match->getParameter('group');
    $breadcrumb->addLink($course->toLink());
    $breadcrumb->addCacheableDependency($course);

    return $breadcrumb;
  }

}
