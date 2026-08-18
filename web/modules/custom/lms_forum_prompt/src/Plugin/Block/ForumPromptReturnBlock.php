<?php

declare(strict_types=1);

namespace Drupal\lms_forum_prompt\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\lms_forum_prompt\Service\ForumPromptManager;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Shows a return-to-course link on forum prompt topic pages.
 */
#[Block(
  id: 'lms_forum_prompt_return',
  admin_label: new TranslatableMarkup('Forum Prompt return link'),
)]
final class ForumPromptReturnBlock extends BlockBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected readonly RequestStack $requestStack,
    protected readonly RouteMatchInterface $routeMatch,
    protected readonly ForumPromptManager $forumPromptManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition,
  ): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('request_stack'),
      $container->get('current_route_match'),
      $container->get(ForumPromptManager::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $request = $this->requestStack->getCurrentRequest();
    $return = $request?->query->get('return');
    if (\is_string($return) && \str_starts_with($return, '/course/')) {
      $url = Url::fromUserInput($return);
    }
    else {
      $node = $this->routeMatch->getParameter('node');
      if (!$node instanceof NodeInterface) {
        return [];
      }
      $url = $this->forumPromptManager->getReturnUrlForTopic($node);
      if ($url === NULL) {
        return [];
      }
    }

    return [
      '#type' => 'link',
      '#title' => $this->t('When finished, click here to return to the lesson'),
      '#url' => $url,
      '#attributes' => ['class' => ['lms-forum-prompt-return-link']],
      '#cache' => [
        'max-age' => 0,
      ],
    ];
  }

}
