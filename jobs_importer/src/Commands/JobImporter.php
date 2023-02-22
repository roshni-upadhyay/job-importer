<?php

namespace Drupal\jobs_importer\Commands;

use Drush\Commands\DrushCommands;
use Drupal\node\Entity\Node;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactory;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use Symfony\Component\Serializer\SerializerInterface;
use Drupal\file\FileRepository;

/**
 * A Drush commandfile.
 *
 * Drush command to import Jobs from xml.
 */
class JobImporter extends DrushCommands {
  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\Client
   */
  protected $httpClient;

  /**
   * The serializer.
   *
   * @var \Symfony\Component\Serializer\SerializerInterface
   */
  protected $serializer;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Logger Factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactory
   */
  protected $loggerFactory;

  /**
   * The file repository service under test.
   *
   * @var \Drupal\file\FileRepository
   */
  protected $fileRepository;

  /**
   * Constructs the Job Import.
   *
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The HTTP client.
   * @param \Symfony\Component\Serializer\SerializerInterface $serializer
   *   The serializer.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Logger\LoggerChannelFactory $loggerFactory
   *   The logger factory service.
   * @param \Drupal\file\FileRepository $fileRepository
   *   The fileRepository service.
   */
  public function __construct(ClientInterface $http_client, SerializerInterface $serializer, EntityTypeManagerInterface $entity_type_manager, LoggerChannelFactory $loggerFactory, FileRepository $fileRepository) {
    $this->httpClient = $http_client;
    $this->serializer = $serializer;
    $this->entityTypeManager = $entity_type_manager;
    $this->loggerFactory = $loggerFactory->get('jobs_importer');
    $this->fileRepository = $fileRepository;
  }

  /**
   *
   * @command jobimport
   * @aliases jobim
   * @usage importJobItems to import jobs in backend
   */
  public function importJobItems() {
    $jobDetail = $this->getJobDetails();

    if (!empty($jobDetail)) {
      foreach ($jobDetail as $jobValue) {
        $jobIds = $this->entityTypeManager->getStorage('node')->getQuery()
          ->condition('type', 'article')
          ->condition('field_job_guid', $jobValue['guid'], '=')
          ->execute();

        $jobId = array_values($jobIds);
        if (!empty($jobId[0])) {
          // Update Jobs from Feeds.
          $this->updateJobs($jobId[0], $jobValue);
        }
        else {
          // Create Jobs from Feeds.
          $this->createJobs($jobValue);
        }
      }
    }
  }

  /**
   * To get Job Items from xml.
   */
  public function getJobDetails() {
    $jobsConfig = config_pages_config('job_importer_settings');
    $jobFeedsUrl = $jobsConfig->get('field_job_feed_url')->value;
    if ($jobFeedsUrl) {
      $jobCount = 0;
      $arrJob = $jobIds = [];

      try {
        $response = $this->httpClient->request('GET', $jobFeedsUrl, ['verify' => FALSE]);
        $response_data = $this->serializer->decode($response->getBody()->getContents(), 'xml');
      }
      catch (RequestException $e) {
        $this->loggerFactory
          ->info("HTTP request failed. Error was: @error.",
          ['@error' => 'Failed to open Job Feed file']);
      }

      if (!empty($response_data)) {
        foreach ($response_data['channel']['item'] as $xmlValue) {
          $guid = $xmlValue['guid']['#'];
          $arrJob[$jobCount]['guid'] = $guid;
          $arrJob[$jobCount]['title'] = $xmlValue['title'];
          $arrJob[$jobCount]['link'] = $xmlValue['link'];
          $arrJob[$jobCount]['pubDate'] = $xmlValue['pubDate'];
          $arrJob[$jobCount]['imageUrl'] = $xmlValue['media:content']['@url'];
          $arrJob[$jobCount]['pubDate'] = $xmlValue['pubDate'];
          $jobIds[] = $guid;
          $jobCount++;
        }

        if (!empty($jobIds)) {
          $this->deleteJobs($jobIds);
        }
      }
      return $arrJob;
    }
    else {
      $this->loggerFactory
        ->notice("Import failed - file not found @file.",
          ['@file' => $jobFeedsUrl]);
    }
  }

  /**
   * To delete Jobs Items.
   */
  public function deleteJobs(array $ids = []) :void {
    if (!empty($ids)) {
      $jobIds = $this->entityTypeManager->getStorage('node')->getQuery()
        ->condition('type', 'article')
        ->condition('field_job_guid', $ids, 'NOT IN')
        ->execute();
      if (!empty($jobIds)) {
        $nodeStorage = $this->entityTypeManager->getStorage('node');
        $nodes = $nodeStorage->loadMultiple($jobIds);
        foreach ($nodes as $node) {
          $node->delete();
          $this->loggerFactory
            ->notice("Deleted @data.",
            ['@data' => $node->title]);
        }
      }
    }
  }

  /**
   * To update Job Items.
   */
  public function updateJobs($jobId, array $jobValue = []):void {

    if (!empty($jobId)) {
      $nodeStorage = $this->entityTypeManager->getStorage('node');
      $node = $nodeStorage->load($jobId);
      $arrFile = $this->createImageFile($jobValue['imageUrl']);

      if ($node->bundle() == 'article') {
        $node->set('field_job_guid', $jobValue['guid']);
        $node->set('title', $jobValue['title']);
        $node->set('field_job_link', $jobValue['link']);
        $node->set('created', strtotime($jobValue['pubDate']));
        if (!empty($file)) {
          $node->set('field_job_storyimage', ['target_id' => $arrFile['fileId'], 'alt' => $arrFile['fileName']]);
        }
        $node->save();
        if ($node->id()) {
          $this->loggerFactory
            ->notice("Updated @data.",
            ['@data' => $jobValue['title']]);
        }
      }
    }
  }

  /**
   * To create Job Items from xml.
   */
  public function createJobs(array $jobValue = []):void {
    // Create new job.
    if (!empty($jobValue)) {
      $arrFile = $this->createImageFile($jobValue['imageUrl']);
      $node = Node::create(['type' => 'article']);
      $node->set('status', 1);
      $node->set('uid', 1);
      $node->set('field_job_guid', $jobValue['guid']);
      $node->set('title', $jobValue['title']);
      $node->set('field_job_link', $jobValue['link']);
      $node->set('created', strtotime($jobValue['pubDate']));
      if (!empty($arrFile)) {
        $node->set('field_job_storyimage', ['target_id' => $arrFile['fileId'], 'alt' => $arrFile['fileName']]);
      }
      $node->save();

      if ($node->id()) {
        $this->loggerFactory
          ->notice("Created @data.",
        ['@data' => $jobValue['title']]);
      }
    }
  }

  /**
   * To get Job Items from xml.
   */
  public function createImageFile($imageUrl = '') {
    $arrFile = [];
    if (!empty($imageUrl)) {
      $image_data = file_get_contents($imageUrl);
      $fileName = basename($imageUrl);
      $image_target_path = "public://" . $fileName;

      $file = $this->fileRepository->writeData($image_data, $image_target_path, FileSystemInterface::EXISTS_REPLACE);
      $arrFile['fileId'] = $file->id();
      $arrFile['fileName'] = $fileName;
      return $arrFile;
    }
  }

}
