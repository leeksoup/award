<?php

declare(strict_types=1);

namespace Drupal\lms_classes\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Utility\Token;
use Drupal\lms\Entity\Bundle\Course;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireCallable;

/**
 * Service for generating class names.
 */
class ClassNameGenerator {

  private const CLASS_NO_STATE = 'lms_classes_class_number';

  /**
   * The constructor.
   */
  public function __construct(
    #[Autowire(service: EntityTypeManagerInterface::class, lazy: TRUE)]
    private readonly EntityTypeManagerInterface $entityTypeManager,
    #[Autowire(service: ConfigFactoryInterface::class, lazy: TRUE)]
    private readonly ConfigFactoryInterface $configFactory,
    #[Autowire(service: StateInterface::class, lazy: TRUE)]
    private readonly StateInterface $state,
    #[AutowireCallable(service: Token::class, method: 'replacePlain', lazy: TRUE)]
    private readonly \Closure $tokenReplacePlain,
  ) {}

  /**
   * Generate default class name.
   */
  public function generateDefaultClassName(Course $course, ?string $pattern = NULL): string {
    if ($pattern === NULL) {
      $pattern = $this->configFactory->get('lms_classes.settings')->get('class_name_pattern');
    }
    if ($pattern === '' || $pattern === NULL) {
      return self::generateRandomClassName($course);
    }
    return ($this->tokenReplacePlain)($pattern, ['group' => $course], [
      'callback' => [$this, 'getClassNameReplacements'],
      // Pass pattern to callback options to generate replacements
      // for used tokens only.
      'pattern' => $pattern,
    ]);
  }

  /**
   * Get auto-incremented class number.
   */
  public function getNextClassNumber(): int {
    $class_no = $this->state->get(self::CLASS_NO_STATE);
    if ($class_no === NULL) {
      $class_no = $this->entityTypeManager->getStorage('group')->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', 'lms_class')
        ->count()
        ->execute();
    }

    return ++$class_no;
  }

  /**
   * Set current class number in state.
   */
  public function setCurrentClassNumber(): void {
    $class_no = $this->state->get(self::CLASS_NO_STATE);
    if ($class_no === NULL) {
      $class_no = $this->entityTypeManager->getStorage('group')->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', 'lms_class')
        ->count()
        ->execute();
      // This is called just after inserting a new class so
      // not incrementing the count in this case.
      $this->state->set(self::CLASS_NO_STATE, $class_no);
      return;
    }
    $this->state->set(self::CLASS_NO_STATE, ++$class_no);
  }

  /**
   * Get class name replacements.
   */
  public function getClassNameReplacements(array &$replacements, array $data, array $options): void {
    $pattern = $options['pattern'];
    while (($pos = \strpos($pattern, '[lms_cn:rl')) !== FALSE) {
      $count = \substr($pattern, $pos + 10, 1);
      if (!\is_numeric($count)) {
        break;
      }
      $replacement = '';
      for ($i = 0; $i < $count; $i++) {
        $replacement .= \chr(\rand(65, 90));
      }
      $replacements["[lms_cn:rl$count]"] = $replacement;
      $pattern = \substr_replace($pattern, '', $pos, 12);
      break;
    }
    while (($pos = \strpos($pattern, '[lms_cn:rd')) !== FALSE) {
      $count = \substr($pattern, $pos + 10, 1);
      if (!\is_numeric($count)) {
        break;
      }
      $replacement = '';
      for ($i = 0; $i < $count; $i++) {
        $replacement .= (\rand(0, 9));
      }
      $replacements["[lms_cn:rd$count]"] = $replacement;
      $pattern = \substr_replace($pattern, '', $pos, 12);
      break;
    }
    if (\strpos($pattern, '[lms_cn:class_no]') !== FALSE) {
      $replacements["[lms_cn:class_no]"] = $this->getNextClassNumber();
    }

  }

  /**
   * Generate default class name.
   */
  public static function generateRandomClassName(Course $course): string {
    // Generate a class name based on the learning path. Using the learning
    // path name could make group listings hard to scan. Using the learning
    // path ID could create confusion with the class's own numeric ID. To
    // avoid this and also avoid hash collisions, add 100 to the learning
    // path ID then base36 encode and upper-case it. This gives us a
    // human-readable short hash which is superficially similar to the
    // kind of class names that are often used in schools (1A, DF1, etc.).
    return (string) \t('Class @class_name', [
      '@class_name' => \strtoupper(\base_convert((string) ($course->id() + 100), 10, 36)),
    ]);
  }

}
