<?php

namespace Drupal\lti_tool_provider\Controller;

use Drupal;
use Drupal\Core\Controller\ControllerBase;
use Drupal\lti_tool_provider\Event\LtiToolProviderLaunchRedirectEvent;
use Drupal\lti_tool_provider\LTIToolProviderContext;
use Drupal\lti_tool_provider\LTIToolProviderContextInterface;
use Drupal\lti_tool_provider\LtiToolProviderEvent;
use Exception;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

class LTIToolProviderV1P3Launch extends ControllerBase {

  /**
   * @param \Symfony\Component\HttpFoundation\Request $request
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *
   * @throws \Exception
   */
  public function route(Request $request): RedirectResponse {
    try {
      $context = $request->getSession()->get('lti_tool_provider_context');
      if (!($context instanceof LTIToolProviderContextInterface)) {
        throw new Exception('LTI context missing.');
      }

      $eventDispatcher = Drupal::service('event_dispatcher');
      if (!($eventDispatcher instanceof EventDispatcherInterface)) {
        throw new Exception('Event dispatcher missing.');
      }

      $payload = $context->getPayload();
      $custom = $payload->getCustom();

      $event = new LtiToolProviderLaunchRedirectEvent($context, $custom['destination'] ?? '/');
      LtiToolProviderEvent::dispatchEvent($eventDispatcher, $event);

      if ($event->isCancelled()) {
        throw new Exception($event->getMessage());
      }

      $destination = $event->getDestination();

      return new RedirectResponse($destination);
    }
    catch (Exception $e) {
      $this->getLogger('lti_tool_provider')->warning($e->getMessage());
      LTIToolProviderContext::sendError($e->getMessage(), $context ?? NULL);
      return new RedirectResponse('/', 500);
    }
  }

}
