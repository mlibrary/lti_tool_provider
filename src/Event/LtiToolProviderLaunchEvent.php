<?php

namespace Drupal\lti_tool_provider\Event;

use Drupal\lti_tool_provider\LtiToolProviderEvent;
use Symfony\Component\HttpFoundation\Request;

class LtiToolProviderLaunchEvent extends LtiToolProviderEvent {

  /**
   * @var \Symfony\Component\HttpFoundation\Request
   */
  private $request;

  /**
   * LtiToolProviderLaunchEvent constructor.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   */
  public function __construct(Request $request) {
    $this->setRequest($request);
  }

  /**
   * @return \Symfony\Component\HttpFoundation\Request
   */
  public function getRequest(): Request {
    return $this->request;
  }

  /**
   * @param \Symfony\Component\HttpFoundation\Request $request
   */
  public function setRequest(Request $request) {
    $this->request = $request;
  }

}
