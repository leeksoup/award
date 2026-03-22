<?php

namespace Drupal\Tests\anu_lms\FunctionalJavascript;

use PHPUnit\Framework\ExpectationFailedException;

/**
 * Manage content helper.
 *
 * @group anu_lms
 */
trait ManageContentHelperTrait {

  /**
   * Find last button on the page.
   *
   * @param string $locator
   *   Type of block.
   *
   * @return \Behat\Mink\Element\NodeElement
   *   The node.
   */
  public function findLastButton($locator) {
    $page = $this->getSession()->getPage();
    $fields = $page->findAll('named', ['button', $locator]);

    if (empty($fields)) {
      throw new ExpectationFailedException('Could not find button with locator: ' . $locator);
    }

    return end($fields);
  }

  /**
   * Find last field on the page.
   *
   * @param string $locator
   *   Type of block.
   *
   * @return \Behat\Mink\Element\NodeElement
   *   The node.
   */
  public function findLastField($locator) {
    $page = $this->getSession()->getPage();
    $fields = $page->findAll('named', ['field', $locator]);

    if (empty($fields)) {
      throw new ExpectationFailedException('Could not find button with locator: ' . $locator);
    }

    return end($fields);
  }

  /**
   * Find the field by label.
   *
   * @param string $label
   *   Label of field.
   * @param bool $multi
   *   Indicator whether all detected nodes should be returned.
   * @param string $fieldType
   *   Type of field.
   *
   * @return \Behat\Mink\Element\NodeElement|\Behat\Mink\Element\NodeElement[]
   *   The node.
   */
  public function getQuestionField($label, $multi = FALSE, $fieldType = 'input') {
    $result = $this
      ->getSession()
      ->getPage()
      ->find('css', 'h6 span:contains("' . $label . '")')
      ->getParent()
      ->getParent()
      ->getParent();

    return $multi ? $result->findAll('css', $fieldType) : $result->find('css', $fieldType);
  }

}
