<?php

namespace Drupal\Tests\anu_lms\FunctionalJavascript;

use Behat\Mink\Exception\ExpectationException;
use Drupal\FunctionalJavascriptTests\WebDriverTestBase;

/**
 * Test the lessons.
 *
 * @group anu_lms
 */
class LessonsCreationTest extends WebDriverTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'anu_lms',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'claro';

  /**
   * Set to TRUE to strict check all configuration saved.
   *
   * @var bool
   *
   * @see \Drupal\Core\Config\Testing\ConfigSchemaChecker
   */
  protected $strictConfigSchema = FALSE;

  /**
   * The incremental value to recognize number of the current content block.
   *
   * @var int
   */
  protected $blockId = -1;

  /**
   * Block: Divider (simple or numeric).
   *
   * @var string
   */
  protected const DIVIDER_BLOCK = 'Block: Divider (simple or numeric)';

  /**
   * Block: Footnotes.
   *
   * @var string
   */
  protected const FOOTNOTES_BLOCK = 'Block: Footnotes';

  /**
   * Block: Heading.
   *
   * @var string
   */
  protected const HEADING_BLOCK = 'Block: Heading';

  /**
   * Block: Highlighted text (full width).
   *
   * @var string
   */
  protected const HIGHLIGHTED_TEXT_FW_BLOCK = 'Block: Highlighted text (full width)';

  /**
   * Block: Highlighted text.
   *
   * @var string
   */
  protected const HIGHLIGHTED_TEXT_BLOCK = 'Block: Highlighted text';

  /**
   * Block: Table.
   *
   * @var string
   */
  protected const TABLE_BLOCK = 'Block: Table';

  /**
   * Block: Text.
   *
   * @var string
   */
  protected const TEXT_BLOCK = 'Block: Text';

  /**
   * Block: List (thumbnails).
   *
   * @var string
   */
  protected const LIST_THUMBNAILS_BLOCK = 'Block: List (thumbnails)';

  /**
   * Block: List (bullets or numbers).
   *
   * @var string
   */
  protected const LIST_BULLETS_BLOCK = 'Block: List (bullets or numbers)';

  /**
   * Block: Audio.
   *
   * @var string
   */
  protected const AUDIO_BLOCK = 'Block: Audio';

  /**
   * Block: Embedded video.
   *
   * @var string
   */
  protected const EMBEDDED_VIDEO_BLOCK = 'Block: Embedded video';

  /**
   * Block: Image.
   *
   * @var string
   */
  protected const IMAGE_BLOCK = 'Block: Image';

  /**
   * Block: Image (thumbnail).
   *
   * @var string
   */
  protected const IMAGE_THUMBNAIL_BLOCK = 'Block: Image (thumbnail)';

  /**
   * Block: Image (full width).
   *
   * @var string
   */
  protected const IMAGE_FULL_WIDTH_BLOCK = 'Block: Image (full width)';

  /**
   * Block: Resource.
   *
   * @var string
   */
  protected const RESOURCE_BLOCK = 'Block: Resource';

  /**
   * Block: Checklist.
   *
   * @var string
   */
  protected const CHECKLIST_BLOCK = 'Block: Checklist';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Rebuild router to get all custom routes.
    $this->container->get('router.builder')->rebuild();
  }

  /**
   * Test "courses creation" for the general case.
   */
  public function testLessonsCreation() {
    $account = $this->drupalCreateUser([], 'test', TRUE);
    $this->drupalLogin($account);

    $assert = $this->assertSession();
    $page = $this->getSession()->getPage();

    // 1. Create a lesson.
    $lessonName = 'Test Demo Lesson';
    $this->drupalGet('node/add/module_lesson');
    $page->fillField('Title', $lessonName);

    // Add all blocks.
    $this->addBlock(static::DIVIDER_BLOCK);
    $this->addBlock(static::FOOTNOTES_BLOCK);
    $this->addBlock(static::HEADING_BLOCK);
    $this->addBlock(static::HIGHLIGHTED_TEXT_FW_BLOCK);
    $this->addBlock(static::HIGHLIGHTED_TEXT_BLOCK);
    $this->addBlock(static::TABLE_BLOCK);
    $this->addBlock(static::TEXT_BLOCK);
    $this->addBlock(static::LIST_THUMBNAILS_BLOCK);
    $this->addBlock(static::LIST_BULLETS_BLOCK);
    $this->addBlock(static::AUDIO_BLOCK);
    $this->addBlock(static::EMBEDDED_VIDEO_BLOCK);
    $this->addBlock(static::IMAGE_BLOCK);
    $this->addBlock(static::IMAGE_THUMBNAIL_BLOCK);
    $this->addBlock(static::IMAGE_FULL_WIDTH_BLOCK);
    $this->addBlock(static::RESOURCE_BLOCK);
    $this->addBlock(static::CHECKLIST_BLOCK);

    // Set alias.
    $page->findById('edit-path-0')->click();
    $page->fillField('URL alias', '/lesson/demo');

    // Save.
    $page->pressButton('Save');

    // Make sure that the lesson was added.
    $title = $assert->waitForElementVisible('css', '#anu-application .MuiTypography-subtitle2');
    $this->assertNotEmpty($title);
    $this->assertSame($lessonName, $title->getText());

    // 2. Make sure that all blocks are placed on the page.
    // Make sure that "Block: Divider (simple or numeric)" block is there.
    $elem = $page->find('css', '#anu-application h6.MuiTypography-subtitle1');
    $this->assertNotEmpty($elem);
    $this->assertSame('1', $elem->getText());

    // Make sure that "Block: Footnotes" block is there.
    $elem = $page->find('css', '#anu-application p:contains(\'This is a "footnotes block"\')');
    $this->assertNotEmpty($elem);
    $this->assertSame('This is a "footnotes block".', $elem->getText());

    // Make sure that "Block: Heading" block is there.
    $elem = $page->find('css', '#anu-application h3 span:contains(\'This is a "heading block".\')');
    $this->assertNotEmpty($elem);
    $this->assertSame('This is a "heading block".', $elem->getText());

    // Make sure that "Block: Highlighted text (full width)" block is there.
    $elem = $page->find('css', '#anu-application p span:contains(\'Highlight fullwidth block\')');
    $this->assertNotEmpty($elem);
    $this->assertSame('Highlight fullwidth block', $elem->getText());

    // Make sure that "Block: Highlighted text" block is there.
    $elem = $page->find('css', '#anu-application span span:contains(\'This is a "highlighted block"\')');
    $this->assertNotEmpty($elem);
    $this->assertSame('This is a "highlighted block"', $elem->getText());

    // Make sure that "Block: Table" block is there.
    $elem = $page->find('css', '#anu-application div h6:contains(\'Table block\')');
    $this->assertNotEmpty($elem);
    $this->assertSame('Table block', $elem->getText());

    // Make sure that "Block: Text" block is there.
    $elem = $page->find('css', '#anu-application div p:contains(\'This is a "text block"\')');
    $this->assertNotEmpty($elem);
    $this->assertStringContainsString('This is a "text block"', $elem->getText());

    // Make sure that "Block: List (thumbnails)" block is there.
    $elem = $page->find('css', '#anu-application h6 span:contains(\'List thumbnails block\')');
    $this->assertNotEmpty($elem);
    $this->assertSame('List thumbnails block', $elem->getText());

    $elem = $page->find('css', '#anu-application div p:contains(\'This is a "List thumbnails block".\')');
    $this->assertNotEmpty($elem);
    $this->assertSame('This is a "List thumbnails block".', $elem->getText());

    // Make sure that "Block: List (bullets or numbers)" block is there.
    $elem = $page->find('css', '#anu-application p:contains(\'This is item of a "Bullet list block".\')');
    $this->assertNotEmpty($elem);
    $this->assertSame('This is item of a "Bullet list block".', $elem->getText());

    // Make sure that "Block: Audio" block is there.
    $elem = $page->find('css', '#anu-application p span:contains(\'Audio block\')');
    $this->assertNotEmpty($elem);
    $this->assertSame('Audio block', $elem->getText());

    // Make sure that "Block: Embedded video" block is there.
    $elem = $page->find('css', '#anu-application iframe[title="Welcome to ANU Community"]');
    $this->assertNotEmpty($elem);

    // Make sure that "Block: Image" block is there.
    $elem = $page->find('css', '#anu-application span span:contains(\'Image Block\')');
    $this->assertNotEmpty($elem);
    $this->assertSame('Image Block', $elem->getText());

    // Make sure that "Block: Image (thumbnail)" block is there.
    $elem = $page->find('css', '#anu-application p p:contains(\'Image Thumbnail Block\')');
    $this->assertNotEmpty($elem);
    $this->assertSame('Image Thumbnail Block', $elem->getText());

    // Make sure that "Block: Image (full width)" block is there.
    $elem = $page->find('css', '#anu-application span span:contains(\'Image Fullwidth Block\')');
    $this->assertNotEmpty($elem);
    $this->assertSame('Image Fullwidth Block', $elem->getText());

    // Make sure that "Block: Resource" block is there.
    $elem = $page->find('css', '#anu-application p span:contains(\'Resource Block\')');
    $this->assertNotEmpty($elem);
    $this->assertSame('Resource Block', $elem->getText());

    $elem = $page->find('css', '#anu-application div span:contains(\'.txt\')');
    $this->assertNotEmpty($elem);
    $this->assertSame('.txt', $elem->getText());

    // Make sure that "Block: Checklist" block is there.
    $elem = $page->find('css', '#anu-application p p:contains(\'This is option of "Checkbox list block".\')');
    $this->assertNotEmpty($elem);
    $this->assertSame('This is option of "Checkbox list block".', $elem->getText());

    $elem = $page->find('css', '#anu-application p p:contains(\'This is description of "Checkbox list block".\')');
    $this->assertNotEmpty($elem);
    $this->assertSame('This is description of "Checkbox list block".', $elem->getText());

    // 3. Add to use "?hl=test" URL. All "test" words must be highlighted, links
    // containing this word in "href" attr shouldn't be broken, and words
    // including "test", but not equal (f.e. "test1"), shouldn't be highlighted.
    $this->drupalGet('lesson/demo', [
      'query' => [
        'hl' => 'test',
      ],
    ]);

    $elem = $page->find('css', '#anu-application div p:contains(\'This is a "text block"\')');
    $this->assertNotEmpty($elem);
    $this->assertSame('This is a "text block". It is a <a href="http://test.dev/test"><strong class="highlight">test</strong></a> link. It is a <strong class="highlight">test</strong> word. It is another word test1.', $elem->getHtml());
  }

  /**
   * Add a content block.
   *
   * @param string $type
   *   Type of block.
   */
  private function addBlock($type) {
    $assert = $this->assertSession();
    $page = $this->getSession()->getPage();

    // Add Block.
    $page
      ->find('css', '[data-drupal-selector="edit-field-module-lesson-content-0-subform-field-lesson-section-content-add-more-browse"]')
      ->click();

    // Choose the requested paragraph.
    $assert
      ->waitForElementVisible('css', 'img[title="' . $type . '"]')
      ->click();
    $assert->assertWaitOnAjaxRequest();

    // Collapse previous content block to avoid collisions during filling
    // next blocks.
    $collapseButton = $page->find('css', 'input[name="field_module_lesson_content_0_subform_field_lesson_section_content_' . $this->blockId++ . '_collapse"]');
    if ($collapseButton) {
      $collapseButton->click();
      $assert->assertWaitOnAjaxRequest();
    }

    // Fill values for a block.
    if ($type === static::DIVIDER_BLOCK) {
      $page->selectFieldOption('Type', 'numeric');
    }
    elseif ($type === static::FOOTNOTES_BLOCK) {
      $this->fillWysiwygField('Footnotes', 'This is a "footnotes block".');
    }
    elseif ($type === static::HEADING_BLOCK) {
      $page->fillField('Heading', 'This is a "heading block".');
      $page->selectFieldOption('Size', 'h3');
    }
    elseif ($type === static::HIGHLIGHTED_TEXT_FW_BLOCK) {
      $page->fillField('Heading', 'Highlight fullwidth block');
      $this->fillWysiwygField('Text', 'This is a "highlighted fullwidth block".');
      $page->selectFieldOption('Color', 'purple');
    }
    elseif ($type === static::HIGHLIGHTED_TEXT_BLOCK) {
      $page->fillField('Text', 'This is a "highlighted block"');
      $page->selectFieldOption('Color', 'blue');
    }
    elseif ($type === static::TABLE_BLOCK) {
      $page->fillField('Caption', 'Table block');
      $this->fillWysiwygField('Table', 'This is a "table block".');
    }
    elseif ($type === static::TEXT_BLOCK) {
      $this->fillWysiwygField('Text', 'This is a "text block". It is a <a href="http://test.dev/test">test</a> link. It is a test word. It is another word test1.');
    }
    elseif ($type === static::LIST_THUMBNAILS_BLOCK) {
      $page->fillField('Heading', 'List thumbnails block');
      $page->selectFieldOption('Color', 'purple');
      $page->selectFieldOption('Size', 'small');
      $page->selectFieldOption('Alignment', 'middle');
      $this->fillWysiwygField('Text', 'This is a "List thumbnails block".');

      $imagePath = __DIR__ . '/assets/example.png';
      $page->attachFileToField('Add a new file', $imagePath);
      $assert->assertWaitOnAjaxRequest();
    }
    elseif ($type === static::LIST_BULLETS_BLOCK) {
      $page->selectFieldOption('Type', 'ol');
      $wysiwygId = $page->find('css', '[name="field_module_lesson_content[0][subform][field_lesson_section_content][' . $this->blockId . '][subform][field_lesson_list_items][0][value]"]');
      $this->fillWysiwygField($wysiwygId, 'This is item of a "Bullet list block".');
    }
    elseif ($type === static::AUDIO_BLOCK) {
      $page->fillField('Name', 'Audio block');
      $mp3Path = __DIR__ . '/assets/example.mp3';
      $page->attachFileToField('Add a new file', $mp3Path);
      $assert->assertWaitOnAjaxRequest();
    }
    elseif ($type === static::EMBEDDED_VIDEO_BLOCK) {
      $page->fillField('URL', 'https://www.youtube.com/watch?v=mqUV4ZWzkew');
    }
    elseif ($type === static::IMAGE_BLOCK) {
      $page->fillField('Caption', 'Image Block');
      $imagePath = __DIR__ . '/assets/example.png';
      $page->attachFileToField('Add a new file', $imagePath);
      $assert->assertWaitOnAjaxRequest();
    }
    elseif ($type === static::IMAGE_THUMBNAIL_BLOCK) {
      $this->fillWysiwygField('Caption', 'Image Thumbnail Block');
      $imagePath = __DIR__ . '/assets/example.png';
      $page->attachFileToField('Add a new file', $imagePath);
      $assert->assertWaitOnAjaxRequest();
    }
    elseif ($type === static::IMAGE_FULL_WIDTH_BLOCK) {
      $page->fillField('Caption', 'Image Fullwidth Block');
      $imagePath = __DIR__ . '/assets/example.png';
      $page->attachFileToField('Add a new file', $imagePath);
      $assert->assertWaitOnAjaxRequest();
    }
    elseif ($type === static::RESOURCE_BLOCK) {
      $page->fillField('Name', 'Resource Block');
      $page->fillField('Description', 'It is a "Resource block"');

      // Hit "Add media".
      $page->pressButton('Add media');
      $assert->assertWaitOnAjaxRequest();

      // Upload a document.
      $textFilePath = __DIR__ . '/assets/example.txt';
      $page->attachFileToField('Add file', $textFilePath);
      $assert->assertWaitOnAjaxRequest();
      $assert->waitForElementVisible('css', '.media-library-add-form__description');

      // Save it.
      $page
        ->find('css', '.ui-dialog .form-actions button')
        ->click();
      $assert->assertWaitOnAjaxRequest();
      $assert->waitForElementRemoved('css', '.media-library-add-form__description');

      // Add the uploaded media file to content.
      $page
        ->find('css', '.ui-dialog .form-actions button')
        ->click();

      // Wait until when overlay disappears.
      $assert->assertWaitOnAjaxRequest();
      $assert->waitForElementRemoved('css', '.ui-widget-overlay');
    }
    elseif ($type === static::CHECKLIST_BLOCK) {
      $this->fillWysiwygField('Option', 'This is option of "Checkbox list block".');
      $this->fillWysiwygField('Description', 'This is description of "Checkbox list block".');
    }
  }

  /**
   * Fill wysiwyg field. The source: https://drupal.stackexchange.com/a/298215.
   *
   * @param string $locator
   *   The field.
   * @param string $value
   *   The value.
   */
  protected function fillWysiwygField($locator, $value) {
    $page = $this->getSession()->getPage();
    $el = is_string($locator) ? $page->findField($locator) : $locator;

    if (empty($el)) {
      throw new ExpectationException('Could not find WYSIWYG with locator: ' . $locator, $this->getSession());
    }

    $fieldId = $el->getAttribute('id');

    if (empty($fieldId)) {
      throw new \Exception('Could not find an id for field with locator: ' . $locator);
    }

    $this->getSession()
      ->executeScript('document.querySelector("#' . $fieldId . '").parentNode.querySelector(".ck-editor__editable").ckeditorInstance.setData("' . addslashes($value) . '");');
  }

}
