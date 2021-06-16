<?php

namespace Drupal\lti_tool_provider_provision\EventSubscriber;

use Drupal;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\lti_tool_provider\Event\LtiToolProviderLaunchRedirectEvent;
use Drupal\lti_tool_provider\LTIToolProviderContext;
use Drupal\lti_tool_provider\LTIToolProviderContextInterface;
use Drupal\lti_tool_provider\LtiToolProviderEvent;
use Drupal\lti_tool_provider_provision\Event\LtiToolProviderProvisionCreateProvisionEvent;
use Drupal\lti_tool_provider_provision\Event\LtiToolProviderProvisionEvent;
use Drupal\lti_tool_provider_provision\Services\ProvisionService;
use Exception;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Implementation LtiToolProviderProvisionEventSubscriber class.
 *
 * @package Drupal\lti_tool_provider_provision\EventSubscriber
 */
class LtiToolProviderProvisionEventSubscriber implements EventSubscriberInterface {

  /**
   * @var ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * @var EventDispatcherInterface
   */
  protected $eventDispatcher;

  /**
   * @var ProvisionService
   */
  private $provisionService;

  /**
   * LtiToolProviderProvisionEventSubscriber constructor.
   *
   * @param ConfigFactoryInterface $configFactory
   * @param ProvisionService $provisionService
   * @param EventDispatcherInterface $eventDispatcher
   */
  public function __construct(
    ConfigFactoryInterface $configFactory,
    ProvisionService $provisionService,
    EventDispatcherInterface $eventDispatcher
  ) {
    $this->configFactory = $configFactory;
    $this->provisionService = $provisionService;
    $this->eventDispatcher = $eventDispatcher;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      LtiToolProviderLaunchRedirectEvent::class => 'onLaunch',
      LtiToolProviderProvisionCreateProvisionEvent::class => 'onCreateProvision',
    ];
  }

  /**
   * @param LtiToolProviderLaunchRedirectEvent $event
   */
  public function onLaunch(LtiToolProviderLaunchRedirectEvent $event) {
    $is_entity_sync = FALSE;
    $is_entity_redirect = FALSE;
    $context = $event->getContext();
    $lti_version = $context->getVersion();

    if (($lti_version === LTIToolProviderContextInterface::V1P0)) {
      $is_entity_sync = $this->configFactory
        ->get('lti_tool_provider_provision.settings')->get('v1p0_entity_sync');
      $is_entity_redirect = $this->configFactory
        ->get('lti_tool_provider_provision.settings')
        ->get('v1p0_entity_redirect');
    }
    if ($lti_version === LTIToolProviderContextInterface::V1P3) {
      $is_entity_sync = $this->configFactory
        ->get('lti_tool_provider_provision.settings')->get('v1p3_entity_sync');
      $is_entity_redirect = $this->configFactory
        ->get('lti_tool_provider_provision.settings')
        ->get('v1p3_entity_redirect');
    }

    try {
      if ($entity = $this->provisionService->provision($context)) {
        if ($is_entity_sync) {
          $this->provisionService->syncProvisionedEntity($context, $entity);
          $entity->save();
        }

        $url = $entity->toUrl()->toString();
        $provisionEvent = new LtiToolProviderProvisionEvent($context, $entity, $url);
        LtiToolProviderEvent::dispatchEvent($this->eventDispatcher, $provisionEvent);

        if ($provisionEvent->isCancelled()) {
          throw new Exception($provisionEvent->getMessage());
        }

        if ($is_entity_redirect) {
          $event->setDestination($provisionEvent->getDestination());
        }
      }
    }
    catch (Exception $e) {
      Drupal::logger('lti_tool_provider_provision')->error($e->getMessage());
      LTIToolProviderContext::sendError($e->getMessage(), $context);
    }
  }

  /**
   * @param LtiToolProviderProvisionCreateProvisionEvent $event
   */
  public function onCreateProvision(LtiToolProviderProvisionCreateProvisionEvent $event) {
    $context = $event->getContext();
    $lti_version = $context->getVersion();

    $access = TRUE;
    $ltiRoles = [];
    $allowedRoles = [];
    $is_allowed_roles_enabled = FALSE;

    if (($lti_version === LTIToolProviderContextInterface::V1P0)) {
      $context_data = $context->getContext();
      $is_allowed_roles_enabled = $this->configFactory
        ->get('lti_tool_provider_provision.settings')
        ->get('v1p0_allowed_roles_enabled');
      $ltiRoles = parse_roles($context_data['roles']);
      $allowedRoles = $this->configFactory->get('lti_tool_provider_provision.settings')
        ->get('v1p0_allowed_roles');
    }

    if (($lti_version === LTIToolProviderContextInterface::V1P3)) {
      $is_allowed_roles_enabled = $this->configFactory
        ->get('lti_tool_provider_provision.settings')
        ->get('v1p3_allowed_roles_enabled');
      $ltiRoles = $context->getPayload()->getRoles();
      $allowedRoles = $this->configFactory->get('lti_tool_provider_provision.settings')
        ->get('v1p3_allowed_roles');
    }

    if ($is_allowed_roles_enabled) {
      $access = FALSE;
      foreach ($ltiRoles as $ltiRole) {
        $ltiRole = $lti_version === LTIToolProviderContextInterface::V1P3 ? encode_key($ltiRole) : $ltiRole;

        if (isset($allowedRoles[$ltiRole]) && $allowedRoles[$ltiRole]) {
          $access = TRUE;
          break;
        }
      }
    }

    if (!$access) {
      $event->cancel('Unable to provision entity.');
    }
  }

}
