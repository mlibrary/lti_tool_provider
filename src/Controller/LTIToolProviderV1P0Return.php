<?php

namespace Drupal\lti_tool_provider\Controller;

use Drupal;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\PageCache\ResponsePolicy\KillSwitch;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\lti_tool_provider\Event\LtiToolProviderEvents;
use Drupal\lti_tool_provider\Event\LtiToolProviderReturnEvent;
use Drupal\lti_tool_provider\LTIToolProviderContext;
use Drupal\lti_tool_provider\LTIToolProviderContextInterface;
use Exception;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Returns responses for lti_tool_provider module routes.
 */
class LTIToolProviderV1P0Return extends ControllerBase {

  /**
   * LTI return.
   *
   * Log the user out and returns the user to the LMS.
   *
   * @return TrustedRedirectResponse|RedirectResponse
   *   Redirect user to appropriate return url.
   *
   * @throws \Exception
   */
  public function route() {
    $context = Drupal::request()->getSession()->get('lti_tool_provider_context');
    if (!($context instanceof LTIToolProviderContextInterface)) {
      throw new Exception('LTI context missing.');
    }

    try {
      $context_data = $context->getContext();

      $eventDispatcher = Drupal::service('event_dispatcher');
      if (!($eventDispatcher instanceof EventDispatcherInterface)) {
        throw new Exception('Event dispatcher missing.');
      }

      $killSwitch = Drupal::service('page_cache_kill_switch');
      if ($killSwitch instanceof KillSwitch) {
        $killSwitch->trigger();
      }

      $event = new LtiToolProviderReturnEvent($context, $context_data['launch_presentation_return_url'] ?? '/');
      $eventDispatcher->dispatch(LtiToolProviderEvents::RETURN, $event);

      $this->userLogout();

      return new TrustedRedirectResponse($event->getDestination());
    }
    catch (Exception $e) {
      $this->getLogger('lti_tool_provider')->warning($e->getMessage());
      LTIToolProviderContext::sendError($e->getMessage(), $context);
      return new RedirectResponse('/', 500);
    }
  }

  /**
   * User log out method.
   */
  protected function userLogout() {
    user_logout();
  }

}
