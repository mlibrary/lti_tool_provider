<?php

namespace Drupal\lti_tool_provider\Controller;

use Drupal;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Controller\ControllerBase;
use Drupal\lti_tool_provider\Authentication\Provider\LTIToolProviderV1P0;
use Drupal\lti_tool_provider\Event\LtiToolProviderLaunchRedirectEvent;
use Drupal\lti_tool_provider\LTIToolProviderContext;
use Drupal\lti_tool_provider\LTIToolProviderContextInterface;
use Drupal\lti_tool_provider\LtiToolProviderEvent;
use Exception;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

class LTIToolProviderV1P0Launch extends ControllerBase {

  /**
   * LTI launch.
   *
   * Authenticates the user via the authentication.lti_tool_provider service,
   * login that user, and then redirect the user to the appropriate page.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse|\Symfony\Component\HttpFoundation\Response
   *   Redirect user to appropriate LTI url.
   *
   * @throws \Exception
   *
   * @see \Drupal\lti_tool_provider\Authentication\Provider\LTIToolProvider
   *   This controller requires that the authentication.lti_tool_provider
   *   service is attached to this route in lti_tool_provider.routing.yml.
   */
  public function route(): RedirectResponse {
    $context = Drupal::request()->getSession()->get('lti_tool_provider_context');
    if (!($context instanceof LTIToolProviderContextInterface)) {
      throw new Exception('LTI context missing.');
    }

    $eventDispatcher = Drupal::service('event_dispatcher');
    if (!($eventDispatcher instanceof EventDispatcherInterface)) {
      throw new Exception('Event dispatcher missing.');
    }

    try {
      $context_data = $context->getContext();

      $event = new LtiToolProviderLaunchRedirectEvent($context, $context_data['custom_destination'] ?? '/');
      LtiToolProviderEvent::dispatchEvent($eventDispatcher, $event);

      if ($event->isCancelled()) {
        throw new Exception($event->getMessage());
      }

      $destination = $event->getDestination();

      return new RedirectResponse($destination);
    }
    catch (Exception $e) {
      $this->getLogger('lti_tool_provider')->warning($e->getMessage());
      LTIToolProviderContext::sendError($e->getMessage(), $context);
      return new Response($e->getMessage(), 500);
    }
  }

  /**
   * Checks access for LTI routes.
   *
   * @return AccessResult
   *   The access result.
   */
  public function access(): AccessResult {
    $request = Drupal::request();
    return AccessResult::allowedIf(LTIToolProviderV1P0::isValidLaunchRequest($request));
  }

}
