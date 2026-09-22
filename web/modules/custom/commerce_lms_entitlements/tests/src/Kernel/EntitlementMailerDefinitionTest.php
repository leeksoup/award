<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_lms_entitlements\Kernel;

use Drupal\commerce_lms_entitlements\Mailer\EntitlementMailer;
use Drupal\Component\Serialization\Yaml;
use Drupal\KernelTests\KernelTestBase;
use Drupal\symfony_mailer\Attribute\MailerInfo;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the native Mailer Plus entitlement message definitions.
 */
#[Group('commerce_lms_entitlements')]
final class EntitlementMailerDefinitionTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system'];

  /**
   * Every declared message has a complete default policy.
   */
  public function testEveryMessageHasDefaultPolicy(): void {
    $attributes = (new \ReflectionClass(EntitlementMailer::class))
      ->getAttributes(MailerInfo::class);
    self::assertCount(1, $attributes);
    $definition = $attributes[0]->newInstance();
    self::assertSame('commerce_lms_entitlements', $definition->base_tag);

    $module_path = DRUPAL_ROOT . '/modules/custom/commerce_lms_entitlements';
    foreach (array_keys($definition->sub_defs) as $sub_tag) {
      $path = $module_path . '/config/install/'
        . 'mailer_policy.mailer_policy.'
        . $definition->base_tag . '.' . $sub_tag . '.yml';
      self::assertFileExists($path);
      $policy = Yaml::decode((string) file_get_contents($path));
      self::assertSame($definition->base_tag . '.' . $sub_tag, $policy['id']);
      self::assertNotEmpty($policy['configuration']['email_subject']['value']);
      self::assertNotEmpty($policy['configuration']['email_body']['content']['value']);
      self::assertSame('email_html', $policy['configuration']['email_body']['content']['format']);
    }
  }

}
