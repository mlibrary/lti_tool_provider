<?php

namespace Drupal\lti_tool_provider;

use Exception;
use Symfony\Component\EventDispatcher\Event;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

abstract class LtiToolProviderEvent extends Event {

  /**
   * @var bool
   */
  private $cancelled = FALSE;

  /**
   * @var string
   */
  private $message;

  /**
   * Dispatch an LTI Tool Provider event.
   *
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $eventDispatcher
   *   The event dispatcher.
   * @param \Drupal\lti_tool_provider\LtiToolProviderEvent $event
   *   The event to dispatch.
   *
   * @throws \Exception
   */
  static function dispatchEvent(EventDispatcherInterface $eventDispatcher, LtiToolProviderEvent &$event) {
    $event = $eventDispatcher->dispatch(get_class($event), $event);
    if ($event instanceof LtiToolProviderEvent && $event->isCancelled()) {
      throw new Exception($event->getMessage());
    }
  }

  /**
   * @return bool
   */
  public function isCancelled(): bool {
    return $this->cancelled;
  }

  /**
   * @return string
   */
  public function getMessage(): string {
    return $this->message;
  }

  public function cancel(string $message): void {
    $this->cancelled = TRUE;
    $this->message = $message;
    $this->stopPropagation();
  }

}
