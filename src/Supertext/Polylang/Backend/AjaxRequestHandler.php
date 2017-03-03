<?php

namespace Supertext\Polylang\Backend;

use Supertext\Polylang\Api\Multilang;
use Supertext\Polylang\Api\Wrapper;
use Supertext\Polylang\Helper\Constant;
use Supertext\Polylang\Helper\TranslationMeta;

/**
 * Provided ajax request handlers
 * @package Supertext\Polylang\Backend
 */
class AjaxRequestHandler
{
  const TRANSLATION_POST_STATUS = 'draft';

  /**
   * @var \Supertext\Polylang\Helper\Library
   */
  private $library;

  /**
   * @var Log
   */
  private $log;

  /**
   * @var ContentProvider
   */
  private $contentProvider;

  /**
   * @param \Supertext\Polylang\Helper\Library $library
   * @param Log $log
   * @param ContentProvider $contentProvider
   */
  public function __construct($library, $log, $contentProvider)
  {
    $this->library = $library;
    $this->log = $log;
    $this->contentProvider = $contentProvider;

    add_action('wp_ajax_sttr_getPostTranslationInfo', array($this, 'getPostTranslationInfoAjax'));
    add_action('wp_ajax_sttr_getPostRawData', array($this, 'getPostRawDataAjax'));
    add_action('wp_ajax_sttr_getPostContentData', array($this, 'getPostContentDataAjax'));
    add_action('wp_ajax_sttr_getOffer', array($this, 'getOfferAjax'));
    add_action('wp_ajax_sttr_createOrder', array($this, 'createOrderAjax'));
    add_action('wp_ajax_sttr_sendSyncRequest', array($this, 'sendSyncRequestAjax'));
  }

  /**
   * Gets translation information about posts
   */
  public function getPostTranslationInfoAjax()
  {
    $translationInfo = array();
    $postIds = $_GET['postIds'];

    foreach ($postIds as $postId) {
      $post = get_post($postId);
      $translationInfo[] = array(
        'id' => $postId,
        'title' => $post->post_title,
        'languageCode' => Multilang::getPostLanguage($postId),
        'meta' => TranslationMeta::of($postId)->get(array(
          TranslationMeta::IN_TRANSLATION,
          TranslationMeta::SOURCE_LANGUAGE_CODE
        )),
        'isDraft' => $post->post_status == 'draft',
        'unfinishedTranslations' => $this->getUnfinishedTranslations($postId),
        'translatableFieldGroups' => $this->contentProvider->getTranslatableFieldGroups($postId)
      );
    }

    self::returnResponse(200, $translationInfo);
  }

  /**
   * Gets the raw data of a posts
   */
  public function getPostRawDataAjax()
  {
    $postId = $_GET['postId'];
    $content = $this->contentProvider->getRawData(get_post($postId));
    self::returnResponse(200, $content);
  }

  /**
   * Gets the translation data of a post
   */
  public function getPostContentDataAjax()
  {
    $postId = $_GET['postId'];
    $translatableFieldGroups = $_POST['translatableContents'];
    $content = $this->contentProvider->getContentData(get_post($postId), $translatableFieldGroups[$postId]);
    self::returnResponse(200, $content);
  }

  /**
   * Gets the offer
   */
  public function getOfferAjax()
  {
    $content = $this->getContent($_POST['translatableContents']);

    try {
      $quote = Wrapper::getQuote(
        $this->library->getApiClient(),
        $this->library->toSuperCode($_POST['orderSourceLanguage']),
        $this->library->toSuperCode($_POST['orderTargetLanguage']),
        $content['data']
      );

      self::returnResponse(200, $quote);
    } catch (\Exception $e) {
      self::returnResponse(500, $e->getMessage());
    }
  }

  /**
   * Creates the order
   */
  public function createOrderAjax()
  {
    $translatableContents = $_POST['translatableContents'];
    $sourceLanguage = $_POST['orderSourceLanguage'];
    $targetLanguage = $_POST['orderTargetLanguage'];
    $content = $this->getContent($translatableContents);
    $sourcePostIds = array_keys($translatableContents);
    $additionalInformation = $_POST['comment'] . ' Posts: ' . implode(', ', $sourcePostIds);
    $referenceHashes = $this->createReferenceHashes($sourcePostIds);

    try {

      $order = Wrapper::createOrder(
        $this->library->getApiClient(),
        get_bloginfo('name') . ' - ' . count($sourcePostIds) . ' post(s)' ,
        $this->library->toSuperCode($sourceLanguage),
        $this->library->toSuperCode($targetLanguage),
        $content['data'],
        $_POST['translationType'],
        $additionalInformation,
        $referenceHashes[0],
        admin_url( 'admin-ajax.php' ) . '?action=sttr_callback'
      );

      $workflowSettings = $this->library->getSettingOption(Constant::SETTING_WORKFLOW);
      $this->processTargetPosts(
        $order,
        $sourcePostIds,
        $sourceLanguage,
        $targetLanguage,
        $referenceHashes,
        $content['metaData'],
        isset($workflowSettings['syncTranslationChanges']) && $workflowSettings['syncTranslationChanges']
      );

      $result = array(
        'message' => '
          ' . __('The order has been placed successfully.', 'polylang-supertext') . '<br />
          ' . sprintf(__('Your order number is %s.', 'polylang-supertext'), $order->Id) . '<br />
          ' . sprintf(__('The post will be translated by %s.', 'polylang-supertext'), date_i18n('D, d. F H:i', strtotime($order->Deadline)))
      );

      self::returnResponse(200, $result);
    } catch (\Exception $e) {
      foreach ($sourcePostIds as $sourcePostId) {
        $this->log->addEntry($sourcePostId, $e->getMessage());
      }

      self::returnResponse(500, $e->getMessage());
    }
  }

  /**
   * Send post changes to supertext
   */
  public function sendSyncRequestAjax()
  {
    try {
      $this->sendSyncRequest(get_post($_GET['targetPostId']));
      self::returnResponse(200, array(
        'message' => __('The changes have been sent successfully.', 'polylang-supertext')
      ));
    } catch (\Exception $e) {
      self::returnResponse(500, $e->getMessage());
    }
  }

  /**
   * @param $translatableContents
   * @return array
   */
  private function getContent($translatableContents)
  {
    $contentData = array(
      'data' => array(),
      'metaData' => array()
    );

    foreach ($translatableContents as $postId => $translatableFieldGroups) {
      $post = get_post($postId);
      $contentData['data'][$postId] = $this->contentProvider->getContentData($post, $translatableFieldGroups);
      $contentData['metaData'][$postId] = $this->contentProvider->getContentMetaData($post, $translatableFieldGroups);
    }

    return $contentData;
  }

  /**
   * @param $postId
   * @return array
   */
  private function getAllTranslatableContent($postId)
  {
    $translatableContents = array($postId => array());
    $translatableFieldGroups = $this->contentProvider->getTranslatableFieldGroups($postId);
    foreach($translatableFieldGroups as $id => $translatableFieldGroup){
      $translatableContents[$postId][$id] = array('fields' => array());
      foreach($translatableFieldGroup['fields'] as $field){
        $translatableContents[$postId][$id]['fields'][$field['name']] = 'on';
      }
    }
    return $translatableContents;
  }

  /**
   * @param $order
   * @param $sourcePostIds
   * @param $sourceLanguage
   * @param $targetLanguage
   * @param $referenceHashes
   * @param $metaData
   * @param $syncTranslationChanges
   */
  private function processTargetPosts($order, $sourcePostIds, $sourceLanguage, $targetLanguage, $referenceHashes, $metaData, $syncTranslationChanges)
  {
    foreach ($sourcePostIds as $sourcePostId) {
      $targetPost = $this->getTargetPost($sourcePostId, $sourceLanguage, $targetLanguage);

      $message = sprintf(
        __('Translation order into %s has been placed successfully. Your order number is %s.', 'polylang-supertext'),
        $this->getLanguageName($targetLanguage),
        $order->Id
      );

      $this->log->addEntry($sourcePostId, $message);
      $this->log->addOrderId($sourcePostId, $order->Id);
      $this->log->addOrderId($targetPost->ID, $order->Id);

      $meta = TranslationMeta::of($targetPost->ID);
      $meta->set(TranslationMeta::TRANSLATION, true);
      $meta->set(TranslationMeta::IN_TRANSLATION, true);
      $meta->set(TranslationMeta::IN_TRANSLATION_REFERENCE_HASH, $referenceHashes[$sourcePostId]);
      $meta->set(TranslationMeta::SOURCE_LANGUAGE_CODE, $sourceLanguage);
      $meta->set(TranslationMeta::META_DATA, $metaData[$sourcePostId]);

      $translationDate = $meta->get(TranslationMeta::TRANSLATION_DATE);
      if($syncTranslationChanges && $translationDate !== null && strtotime($translationDate) < strtotime($targetPost->post_modified)){
        try{
          $this->sendSyncRequest($targetPost);
        }catch (\Exception $e) {
          $this->log->addEntry($targetPost->ID, __('Post changes could not be sent to Supertext.', 'polylang-supertext'));
        }
      }
    }
  }

  /**
   * Send post changes to supertext
   * @param object $targetPost the post
   * @throws \Exception
   */
  public function sendSyncRequest($targetPost)
  {
    $targetPostId = $targetPost->ID;
    $meta = TranslationMeta::of($targetPostId);
    $sourceLanguageCode = $meta->get(TranslationMeta::SOURCE_LANGUAGE_CODE);
    $newTranslatableContent = $this->getAllTranslatableContent($targetPostId);
    $oldTranslatableContent = array();

    $revisions = wp_get_post_revisions($targetPostId);
    $translationDate = strtotime($meta->get(TranslationMeta::TRANSLATION_DATE));

    foreach ($revisions as $revision) {
      if (strtotime($revision->post_modified) == $translationDate) {
        $oldTranslatableContent = $this->getAllTranslatableContent($revision->ID);
        break;
      }
    }

    if (empty($oldTranslatableContent)) {
      throw new \Exception(__('Could not retrieve old version', 'polylang-supertext'));
    }

    Wrapper::sendSyncRequest(
      $this->library->getApiClient(),
      $this->log->getLastOrderId($targetPostId),
      $this->library->toSuperCode($sourceLanguageCode),
      $this->library->toSuperCode(Multilang::getPostLanguage($targetPostId)),
      $this->getContent($oldTranslatableContent)['data'],
      $this->getContent($newTranslatableContent)['data']
    );

    $meta->set(TranslationMeta::TRANSLATION_DATE, $targetPost->post_modified);
    $this->log->addEntry($targetPostId, __('Post changes have been sent to Supertext.', 'polylang-supertext'));
  }

  /**
   * @param string $polyCode slug to search
   * @return string name of the $key language
   */
  private function getLanguageName($polyCode)
  {
    // Get the supertext key
    $superCode = $this->library->toSuperCode($polyCode);
    return __($superCode, 'polylang-supertext-langs');
  }

  /**
   * @param $sourcePostId
   * @param $sourceLanguage
   * @param $targetLanguage
   * @return array|null|\WP_Post
   */
  private function getTargetPost($sourcePostId, $sourceLanguage, $targetLanguage)
  {
    $targetPostId = Multilang::getPostInLanguage($sourcePostId, $targetLanguage);

    if ($targetPostId == null) {
      $targetPost = $this->createTargetPost($sourcePostId, $sourceLanguage, $targetLanguage);
      $this->log->addEntry($targetPost->ID, __('The post to be translated has been created.', 'polylang-supertext'));
      return $targetPost;
    }

    return get_post($targetPostId);
  }

  /**
   * @param $sourcePostId
   * @param $sourceLanguage
   * @param $targetLanguage
   * @return array|null|\WP_Post
   * @internal param $options
   */
  private function createTargetPost($sourcePostId, $sourceLanguage, $targetLanguage)
  {
    $targetPostId = self::createNewPostFrom($sourcePostId);
    $targetPost = get_post($targetPostId);

    self::addImageAttachments($sourcePostId, $targetPostId, $sourceLanguage, $targetLanguage);
    self::copyPostMetas($sourcePostId, $targetPostId, $targetLanguage);

    wp_update_post($targetPost);

    self::setLanguage($sourcePostId, $targetPostId, $sourceLanguage, $targetLanguage);

    return $targetPost;
  }

  /**
   * @param $sourcePostId
   * @return int|\WP_Error
   */
  private static function createNewPostFrom($sourcePostId)
  {
    $sourcePost = get_post($sourcePostId);

    $targetPostData = array(
      'post_author' => wp_get_current_user()->ID,
      'post_mime_type' => $sourcePost->post_mime_type,
      'post_password' => $sourcePost->post_password,
      'post_status' => self::TRANSLATION_POST_STATUS,
      'post_title' => $sourcePost->post_title . ' [' . __('In translation', 'polylang-supertext') . '...]',
      'post_type' => $sourcePost->post_type,
      'menu_order' => $sourcePost->menu_order,
      'comment_status' => $sourcePost->comment_status,
      'ping_status' => $sourcePost->ping_status,
    );

    return wp_insert_post($targetPostData);
  }

  /**
   * @param $sourcePostId
   * @param $targetPostId
   * @param $sourceLang
   * @param $targetLang
   */
  private static function addImageAttachments($sourcePostId, $targetPostId, $sourceLang, $targetLang)
  {
    $sourceAttachments = get_children(array(
        'post_parent' => $sourcePostId,
        'post_type' => 'attachment',
        'post_mime_type' => 'image',
        'orderby' => 'menu_order ASC, ID',
        'order' => 'DESC')
    );

    foreach ($sourceAttachments as $sourceAttachment) {
      $sourceAttachmentId = $sourceAttachment->ID;
      $sourceAttachmentLink = get_post_meta($sourceAttachmentId, '_wp_attached_file', true);
      $sourceAttachmentMetadata = get_post_meta($sourceAttachmentId, '_wp_attached_file', true);

      $targetAttachmentId = Multilang::getPostInLanguage($sourceAttachmentId, $targetLang);

      if ($targetAttachmentId == null) {
        $targeAttachment = $sourceAttachment;
        $targeAttachment->ID = null;
        $targeAttachment->post_parent = $targetPostId;
        $targetAttachmentId = wp_insert_attachment($targeAttachment);
        add_post_meta($targetAttachmentId, '_wp_attachment_metadata', $sourceAttachmentMetadata);
        add_post_meta($targetAttachmentId, '_wp_attached_file', $sourceAttachmentLink);
        self::setLanguage($sourceAttachmentId, $targetAttachmentId, $sourceLang, $targetLang);
      } else {
        $targetAttachment = get_post($targetAttachmentId);
        $targetAttachment->post_parent = $targetPostId;
        wp_insert_attachment($targetAttachment);
      }
    }
  }

  /**
   * Copy post metas using polylang
   * @param $sourcePostId
   * @param $targetPostId
   * @param $target_lang
   */
  private static function copyPostMetas($sourcePostId, $targetPostId, $target_lang)
  {
    global $polylang;

    if (empty($polylang)) {
      return;
    }

    $polylang->sync->copy_taxonomies($sourcePostId, $targetPostId, $target_lang);
    $polylang->sync->copy_post_metas($sourcePostId, $targetPostId, $target_lang);

    TranslationMeta::of($targetPostId)->delete();
    //TODO refactor

    delete_post_meta($targetPostId, Log::META_LOG);
    delete_post_meta($targetPostId, Log::META_ORDER_ID);
  }

  /**
   * @param $sourcePostId
   * @param $targetPostId
   * @param $sourceLanguage
   * @param $targetLanguage
   */
  private static function setLanguage($sourcePostId, $targetPostId, $sourceLanguage, $targetLanguage)
  {
    Multilang::setPostLanguage($targetPostId, $targetLanguage);

    $postsLanguageMappings = array(
      $sourceLanguage => $sourcePostId,
      $targetLanguage => $targetPostId
    );

    foreach (Multilang::getLanguages() as $language) {
      $languagePostId = Multilang::getPostInLanguage($sourcePostId, $language->slug);
      if ($languagePostId) {
        $postsLanguageMappings[$language->slug] = $languagePostId;
      }
    }

    Multilang::savePostTranslations($postsLanguageMappings);
  }

  /**
   * @param $code
   * @param $body
   */
  private static function returnResponse($code, $body)
  {
    header('Content-Type: application/json');
    http_response_code($code);
    echo json_encode($body);
    die();
  }

  /**
   * @param array $sourcePostIds
   * @return array
   */
  private function createReferenceHashes($sourcePostIds)
  {
    $referenceHashes = array();

    $referenceData = hex2bin(Constant::REFERENCE_BITMASK);
    foreach ($sourcePostIds as $sourcePostId) {
      $referenceHash = openssl_random_pseudo_bytes(32);
      $referenceData ^= $referenceHash;
      $referenceHashes[$sourcePostId] = bin2hex($referenceHash);
    }

    $referenceHashes[0] = bin2hex($referenceData);

    return $referenceHashes;
  }

  /**
   * @param $sourcePostId
   * @return array
   */
  private function getUnfinishedTranslations($sourcePostId)
  {
    $unfinishedTranslations = array();

    $languages = Multilang::getLanguages();
    foreach ($languages as $language) {
      $targetPostId = Multilang::getPostInLanguage($sourcePostId, $language->slug);

      if ($targetPostId == null || $targetPostId == $sourcePostId || !TranslationMeta::of($targetPostId)->is(TranslationMeta::IN_TRANSLATION)) {
        continue;
      }

      $unfinishedTranslations[$language->slug] = array(
        'orderId' => $this->log->getLastOrderId($targetPostId)
      );
    }

    return $unfinishedTranslations;
  }
}
