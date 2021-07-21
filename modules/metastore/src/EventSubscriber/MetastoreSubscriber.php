<?php

namespace Drupal\metastore\EventSubscriber;

use Drupal\common\Events\Event;
use Drupal\Core\Logger\LoggerChannelFactory;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Drupal\metastore\Plugin\QueueWorker\OrphanReferenceProcessor;
use Drupal\metastore\Service;
use Drupal\metastore\ResourceMapper;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class MetastoreSubscriber.
 */
class MetastoreSubscriber implements EventSubscriberInterface {

  /**
   * Logger service.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactory
   */
  protected $loggerFactory;

  /**
   * Inherited.
   *
   * @{inheritdocs}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('logger.factory'),
      $container->get('dkan.metastore.service'),
      $container->get('dkan.metastore.resource_mapper')
    );
  }

  /**
   * Constructor.
   *
   * @param Drupal\Core\Logger\LoggerChannelFactory $logger_factory
   *   LoggerChannelFactory service.
   * @param \Drupal\metastore\Service $service
   *   The dkan.metastore.service service.
   * @param \Drupal\metastore\ResourceMapper $resourceMapper
   *   The dkan.metastore.resource_mapper.
   */
  public function __construct(LoggerChannelFactory $logger_factory, Service $service, ResourceMapper $resourceMapper) {
    $this->loggerFactory = $logger_factory;
    $this->service = $service;
    $this->resourceMapper = $resourceMapper;
  }

  /**
   * Inherited.
   *
   * @inheritdoc
   */
  public static function getSubscribedEvents() {
    $events = [];
    $events[OrphanReferenceProcessor::EVENT_ORPHANING_DISTRIBUTION][] = ['cleanResourceMapperTable'];
    return $events;
  }

  /**
   * React to a distribution being orphaned.
   *
   * @param \Drupal\common\Events\Event $event
   *   The event object containing the resource uuid.
   */
  public function cleanResourceMapperTable(Event $event) {
    $uuid = $event->getData();
    // Use the metastore service to build a distribution object.
    $distribution = $this->service->get('distribution', $uuid);
    $distribution = json_decode($distribution);
    // Attempt to extract the resource for the given distribution.
    $resource = $distribution->data->{'%Ref:downloadURL'} ?? [];
    $resource = array_shift($resource);
    // Retrieve the distributions ID, perspective, and version metadata.
    $id = $resource->data->identifier ?? NULL;
    $perspective = $resource->data->perspective ?? NULL;
    $version = $resource->data->version ?? NULL;

    // Use the metastore resourceMapper to remove the source entry.
    try {
      // Ensure a valid ID, perspective, and version were found for the given
      // distribution.
      if (!isset($id, $perspective, $version)) {
        throw new \UnexpectedValueException('Distribution does not have resource.');
      }
      $resource = $this->resourceMapper->get($id, $perspective, $version);
      if ($resource) {
        $this->resourceMapper->remove($resource);
      }
      else {
        $this->loggerFactory->get('metastore')->error('Missing resource');
      }
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('metastore')->error('Failed to remove resource source mapping for @uuid. @message',
        [
          '@uuid' => $uuid,
          '@message' => $e->getMessage(),
        ]
      );
    }
  }

}
