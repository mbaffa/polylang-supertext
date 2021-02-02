<?php

namespace Supertext\Api;


use Supertext\Helper\Constant;
use Supertext\Helper\TranslationMeta;

class WriteBack
{
  /**
   * JSON request data
   * @var array
   */
  private $json = null;

  /**
   * Library
   * @var null|\Supertext\Helper\Library
   */
  private $library = null;

  /**
   * Reference data
   * @var null|array
   */
  private $postIds = null;

  /**
   * Target language
   * @var null|string
   */
  private $targetLanguage = null;

  /**
   * Translation data
   * @var null|array
   */
  private $contentData = null;

  /**
   * @param $json
   * @param \Supertext\Helper\Library $library
   */
  public function __construct($json, $library)
  {
    $this->json = $json;
    $this->library = $library;
  }

  /**
   * Validates the reference data
   * @return array|null
   */
  public function isReferenceValid()
  {
    $sourcePostIds = $this->getSourcePostIds();

    $referenceData = hex2bin(Constant::REFERENCE_BITMASK);
    foreach ($sourcePostIds as $sourcePostId) {
      $targetPostId = $this->library->getMultilang()->getPostInLanguage($sourcePostId, $this->getTargetLanguageCode());
      $referenceHash = TranslationMeta::of($targetPostId)->get(TranslationMeta::IN_TRANSLATION_REFERENCE_HASH);
      $referenceData ^= hex2bin($referenceHash);
    }

    return $this->json->ReferenceData === bin2hex($referenceData);
  }

  /**
   * @return null|string
   */

  public function getTargetLanguageCode()
  {
    if ($this->targetLanguage == null) {
      $this->targetLanguage = $this->library->toPolyCode($this->json->TargetLang);
    }
    return $this->targetLanguage;
  }

  /**
   * @return array|null
   */
  public function getContentData(){
    if($this->contentData == null){
      $groups = $this->json->Groups;

      $this->contentData = Wrapper::buildContentData($groups);
    }

    return $this->contentData;
  }

  /**
   * @return array|null
   */
  public function getSourcePostIds(){
    if($this->postIds == null){
      $this->postIds = array_keys($this->getContentData());
    }

    return $this->postIds;
  }

  /**
   * @return int the order id
   */
  public function getOrderId(){
    return intval($this->json->Id);
  }
}