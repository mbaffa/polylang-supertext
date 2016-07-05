<?php

namespace Supertext\Polylang\Helper;


class PostContentAccessor implements IContentAccessor
{
  /**
   * @var the text processor
   */
  private $textProcessor;

  public function __construct($textProcessor)
  {
    $this->textProcessor = $textProcessor;
  }

  public function getTranslatableFields($postId)
  {
    $translatableFields = array();

    $translatableFields[] = array(
      'title' => __('Title', 'polylang-supertext'),
      'name' => 'post_title',
      'default' => true
    );

    $translatableFields[] = array(
      'title' => __('Content', 'polylang-supertext'),
      'name' => 'post_content',
      'default' => true
    );

    $translatableFields[] = array(
      'title' => __('Excerpt', 'polylang-supertext'),
      'name' => 'post_excerpt',
      'default' => true
    );

    return array(
      'source_name' => __('Post', 'polylang-supertext'),
      'fields' => $translatableFields
    );
  }

  public function getTexts($post, $selectedTranslatableFields)
  {
    $texts = array();

    if ($selectedTranslatableFields['post_title']) {
      $texts['post_title'] = $post->post_title;
    }

    if ($selectedTranslatableFields['post_content']) {
      $texts['post_content'] = $this->textProcessor->replaceShortcodes($post->post_content);
    }

    if ($selectedTranslatableFields['post_excerpt']) {
      $texts['post_excerpt'] = $post->post_excerpt;
    }

    return $texts;
  }

  public function setTexts($post, $texts)
  {
    foreach ($texts as $id => $text) {
      $decodedContent = html_entity_decode($text, ENT_COMPAT | ENT_HTML401, 'UTF-8');

      if ($id === 'post_content') {
        $decodedContent = $this->textProcessor->replaceShortcodeNodes($decodedContent);
      }

      $post->{$id} = $decodedContent;
    }
  }
}