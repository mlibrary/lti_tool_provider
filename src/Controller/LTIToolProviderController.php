<?php

namespace Drupal\lti_tool_provider\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Logger\LoggerChannelFactory;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Returns responses for lti_tool_provider module routes.
 */
class LTIToolProviderController extends ControllerBase {

  /**
   * The configuration factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * A logger instance.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactory
   */
  protected $loggerFactory;

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The private temp store for storing LTI context info.
   *
   * @var \Drupal\Core\TempStore\PrivateTempStore
   */
  protected $tempStore;

  /**
   * Constructs a HTTP basic authentication provider object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The configuration factory.
   * @param \Drupal\Core\Logger\LoggerChannelFactory $logger_factory
   *   A logger instance.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   * @param \Drupal\Core\TempStore\PrivateTempStoreFactory $temp_store_factory
   *   The temp store factory.
   */
  public function __construct(ConfigFactoryInterface $config_factory, LoggerChannelFactory $logger_factory, ModuleHandlerInterface $module_handler, PrivateTempStoreFactory $temp_store_factory = NULL) {
    $this->configFactory = $config_factory;
    $this->loggerFactory = $logger_factory->get('lti_tool_provider');
    $this->moduleHandler = $module_handler;
    $this->tempStore = $temp_store_factory->get('lti_tool_provider');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
        $container->get('config.factory'),
        $container->get('logger.factory'),
        $container->get('module_handler'),
        $container->get('tempstore.private')
    );
  }

  /**
   * LTI launch.
   *
   * Authenticates the user via the authentication.lti_tool_provider service,
   * logins that user, and then redirects the user to the appropriate page.
   *
   * @return Symfony\Component\HttpFoundation\RedirectResponse
   *   Redirect user to appropriate LTI url.
   *
   * @see \Drupal\lti_tool_provider\Authentication\Provider\LTIToolProvider
   *   This controller requires that the authentication.lti_tool_provider
   *   service is attached to this route in lti_tool_provider.routing.yml.
   */
  public function launch(Request $request) {
    try {
      $context = $this->tempStore->get('context');

      if (isset($context['custom_destination']) && !empty($context['custom_destination'])) {
        return new RedirectResponse($context['custom_destination']);
      }

      return new RedirectResponse('/');
    }
    catch (Exception $e) {
      $this->loggerFactory->warning($e->getMessage());
    }
  }

  /**
   * LTI return.
   *
   * Logs the user out and returns the user to the LMS.
   *
   * @return Symfony\Component\HttpFoundation\RedirectResponse
   *   Redirect user to appropriate return url.
   */
  public function return(Request $request) {
    try {
      $context = $this->tempStore->get('context');

      $this->moduleHandler->invokeAll('lti_tool_provider_return', [$context]);
      user_logout();

      return new RedirectResponse($context['launch_presentation_return_url']);
    }
    catch (Exception $e) {
      $this->loggerFactory->warning($e->getMessage());
    }
  }

  /**
   * Checks access for LTI routes.
   *
   * @return \Drupal\Core\Access\AccessResult
   *   The access result.
   */
  public function access() {
    $context = $this->tempStore->get('context');

    return AccessResult::allowedIf(isset($context));
  }

}
