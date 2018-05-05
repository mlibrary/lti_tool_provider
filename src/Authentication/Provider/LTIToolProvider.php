<?php

namespace Drupal\lti_tool_provider\Authentication\Provider;

use Drupal\Core\Authentication\AuthenticationProviderInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Logger\LoggerChannelFactory;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\Url;
use Drupal\lti_tool_provider\Entity\Nonce;
use Drupal\user\Entity\User;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Oauth authentication provider for LTI Tool Provider.
 */
class LTIToolProvider implements AuthenticationProviderInterface {

  /**
   * The configuration factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

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
   * The PECL OauthProvider class.
   *
   * @var \OauthProvider
   */
  public $provider;

  /**
   * The consumer entity matching the LTI request.
   *
   * @var array
   */
  protected $consumerEntity;

  /**
   * The LTI context, i.e. the request parameters.
   *
   * @var array
   */
  protected $context;

  /**
   * Constructs a HTTP basic authentication provider object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The configuration factory.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity manager.
   * @param \Drupal\Core\Logger\LoggerChannelFactory $logger_factory
   *   A logger instance.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   * @param \Drupal\Core\TempStore\PrivateTempStoreFactory $tempStoreFactory
   *   The temp store factory.
   */
  public function __construct(ConfigFactoryInterface $config_factory, EntityTypeManagerInterface $entity_type_manager, LoggerChannelFactory $logger_factory, ModuleHandlerInterface $module_handler, PrivateTempStoreFactory $tempStoreFactory) {
    $this->configFactory = $config_factory;
    $this->entityTypeManager = $entity_type_manager;
    $this->loggerFactory = $logger_factory->get('lti_tool_provider');
    $this->moduleHandler = $module_handler;
    $this->tempStore = $tempStoreFactory->get('lti_tool_provider');
    $this->provider = new \OAuthProvider(["oauth_signature_method" => OAUTH_SIG_METHOD_HMACSHA1]);
  }

  /**
   * {@inheritdoc}
   *
   * @see https://www.imsglobal.org/wiki/step-1-lti-launch-request
   */
  public function applies(Request $request) {
    $lti_message_type = $request->request->get('lti_message_type');
    $lti_version = $request->request->get('lti_version');
    $oauth_consumer_key = $request->request->get('oauth_consumer_key');
    $resource_link_id = $request->request->get('resource_link_id');

    if (!$request->isMethod('POST')) {
      return FALSE;
    }

    if ($lti_message_type !== 'basic-lti-launch-request') {
      return FALSE;
    }

    if (!in_array($lti_version, ['LTI-1p0', 'LTI-1p2'])) {
      return FALSE;
    }

    if (empty($oauth_consumer_key)) {
      return FALSE;
    }

    if (empty($resource_link_id)) {
      return FALSE;
    }

    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function authenticate(Request $request) {
    $this->context = $request->request->all();
    $this->moduleHandler->alter('lti_tool_provider_launch', $this->context);

    try {
      $this->provider->consumerHandler([$this, 'consumerHandler']);
      $this->provider->timestampNonceHandler([$this, 'timestampNonceHandler']);
      $this->provider->isRequestTokenEndpoint(FALSE);
      $this->provider->is2LeggedEndpoint(TRUE);
      $this->provider->checkOAuthRequest();
    }
    catch (\OAuthException $e) {
      $this->loggerFactory->warning($e->getMessage());
      $this->sendLtiError($e->getMessage());

      return NULL;
    }

    try {
      $user = $this->provisionUser();
    }
    catch (\Exception $e) {
      $this->loggerFactory->warning($e->getMessage());
      $this->sendLtiError('Account provisioning failed.');

      return NULL;
    }

    $this->moduleHandler->invokeAll('lti_tool_provider_authenticated', [$user, $this->context]);
    $this->userLoginFinalize($user);
    $this->tempStore->set('context', $this->context);

    return $user;
  }

  /**
   * Looks up the consumer entity that matches the consumer key.
   *
   * @return int
   *   - OAUTH_OK if validated.
   *   - OAUTH_CONSUMER_KEY_UNKNOWN if not.
   */
  public function consumerHandler() {
    $ids = $this->entityTypeManager->getStorage('lti_tool_provider_consumer')
      ->getQuery()
      ->condition('consumer_key', $this->provider->consumer_key, '=')
      ->execute();

    if (count($ids)) {
      $this->consumer_entity = $this->entityTypeManager->getStorage('lti_tool_provider_consumer')->load(key($ids));
      $this->provider->consumer_secret = $this->consumer_entity->get('consumer_secret')->getValue()[0]['value'];

      return OAUTH_OK;
    }
    else {
      return OAUTH_CONSUMER_KEY_UNKNOWN;
    }
  }

  /**
   * Validate nonce.
   *
   * @return int
   *   - OAUTH_OK if validated.
   *   - OAUTH_BAD_TIMESTAMP if timestamp too old.
   *   - OAUTH_BAD_NONCE if nonce has been used.
   */
  public function timestampNonceHandler($provider) {

    // Verify timestamp has been set.
    if (!isset($provider->timestamp)) {
      return OAUTH_BAD_TIMESTAMP;
    }

    // Verify nonce timestamp is not older than now - nonce interval.
    if ($provider->timestamp < (time() - LTI_TOOL_PROVIDER_NONCE_INTERVAL)) {
      return OAUTH_BAD_TIMESTAMP;
    }

    // Verify nonce timestamp is not newer than now + nonce interval.
    if ($provider->timestamp > (time() + LTI_TOOL_PROVIDER_NONCE_INTERVAL)) {
      return OAUTH_BAD_TIMESTAMP;
    }

    // Verify nonce and consumer_key has been set.
    if (!isset($provider->nonce) || !isset($provider->consumer_key)) {
      return OAUTH_BAD_NONCE;
    }

    $storage = $this->entityTypeManager->getStorage('lti_tool_provider_nonce');

    // Verify that current nonce is not a duplicate.
    $nonce_exists = $storage->getQuery()->condition('nonce', $provider->nonce, '=')->execute();
    if (count($nonce_exists)) {
      return OAUTH_BAD_NONCE;
    }

    // Store nonce in database.
    $storage->create([
      'nonce' => $provider->nonce,
      'consumer_key' => $provider->consumer_key,
      'timestamp' => $provider->timestamp,
    ])->save();

    return OAUTH_OK;
  }

  /**
   * Get the user that matches the LTI request context info.
   *
   * @return \Drupal\user\Entity\User
   *   Returns a user corresponding to the LTI request.
   */
  protected function provisionUser() {
    $name = 'ltiuser';
    $mail = 'ltiuser@invalid';

    $name_param = $this->consumer_entity->get('name')->getValue()[0]['value'];
    if (isset($this->context[$name_param]) && !empty($this->context[$name_param])) {
      $name = $this->context[$name_param];
    }

    $mail_param = $this->consumer_entity->get('mail')->getValue()[0]['value'];
    if (isset($this->context[$mail_param]) && !empty($this->context[$mail_param])) {
      $mail = $this->context[$mail_param];
    }

    if ($users = $this->entityTypeManager->getStorage('user')->loadByProperties(['name' => $name, 'status' => 1])) {
      $user = reset($users);
    }
    elseif ($users = $this->entityTypeManager->getStorage('user')->loadByProperties(['mail' => $mail, 'status' => 1])) {
      $user = reset($users);
    }
    else {
      $storage = $this->entityTypeManager->getStorage('user');

      $user = $storage->create();
      $user->setUsername($name);
      $user->setEmail($mail);
      $user->setPassword(user_password());
      $user->enforceIsNew();
      $user->activate();

      $this->moduleHandler->invokeAll('lti_tool_provider_create_user', [$user, $this->context]);

      $user->save();
    }

    return $user;
  }

  /**
   * Finalizes the user login.
   *
   * @param \Drupal\user\Entity\User $user
   *   The user.
   */
  protected function userLoginFinalize(User $user) {
    user_login_finalize($user);
  }

  /**
   * Send an error back to the LMS.
   *
   * @param string $message
   *   The error message to send.
   */
  protected function sendLtiError($message) {
    if (isset($this->context['launch_presentation_return_url']) && !empty($this->context['launch_presentation_return_url'])) {
      $url = Url::fromUri($this->context['launch_presentation_return_url'])
        ->setOption('query', [
          'lti_errormsg' => $message,
        ])
        ->setAbsolute(TRUE)
        ->toString();

      $response = new RedirectResponse($url);
      $response->send();
    }
  }

}
