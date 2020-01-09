<?php

namespace Drupal\lti_tool_provider\Controller;

use Drupal;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\PageCache\ResponsePolicy\KillSwitch;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Exception;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Returns responses for lti_tool_provider module routes.
 */
class LTIToolProviderController extends ControllerBase
{
    /**
     * The configuration factory.
     *
     * @var ConfigFactoryInterface
     */
    protected $configFactory;

    /**
     * A logger instance.
     *
     * @var LoggerChannelFactoryInterface
     */
    protected $loggerFactory;

    /**
     * The module handler.
     *
     * @var ModuleHandlerInterface
     */
    protected $moduleHandler;

    /**
     * The page cache kill switch.
     *
     * @var KillSwitch
     */
    protected $killSwitch;

    /**
     * The request.
     *
     * @var Request
     */
    protected $request;

    /**
     * The request session.
     *
     * @var SessionInterface
     */
    protected $session;

    /**
     * The LTI context.
     *
     * @var mixed
     */
    protected $context;

    /**
     * Optional destination.
     *
     * @var string
     */
    protected $destination;

    /**
     * Constructs a HTTP basic authentication provider object.
     *
     * @param ConfigFactoryInterface $configFactory
     *   The configuration factory.
     * @param LoggerChannelFactoryInterface $loggerFactory
     *   A logger instance.
     * @param ModuleHandlerInterface $moduleHandler
     *   The module handler.
     * @param KillSwitch $killSwitch
     *   The page cache kill switch.
     * @param Request $request
     *   The request.
     * @param SessionInterface $session
     *   The request session.
     * @param mixed $context
     *   The LTI context.
     * @param mixed $destination
     *   Optional destination.
     */
    public function __construct(
        ConfigFactoryInterface $configFactory,
        LoggerChannelFactoryInterface $loggerFactory,
        ModuleHandlerInterface $moduleHandler,
        KillSwitch $killSwitch,
        Request $request,
        SessionInterface $session,
        $context,
        $destination
    ) {
        $this->configFactory = $configFactory;
        $this->loggerFactory = $loggerFactory->get('lti_tool_provider');
        $this->moduleHandler = $moduleHandler;
        $this->killSwitch = $killSwitch;
        $this->request = $request;
        $this->session = $session;
        $this->context = $context;
        $this->destination = $destination;
    }

    /**
     * {@inheritdoc}
     */
    public static function create(ContainerInterface $container)
    {
        /* @var $configFactory ConfigFactoryInterface */
        $configFactory = $container->get('config.factory');
        /* @var $loggerFactory LoggerChannelFactoryInterface */
        $loggerFactory = $container->get('logger.factory');
        /* @var $moduleHandler ModuleHandlerInterface */
        $moduleHandler = $container->get('module_handler');
        /* @var $killSwitch KillSwitch */
        $killSwitch = $container->get('page_cache_kill_switch');
        $request = Drupal::request();
        $session = $request->getSession();
        $context = $session->get('lti_tool_provider_context');
        $destination = Drupal::config('lti_tool_provider.settings')->get('destination');

        return new static(
            $configFactory,
            $loggerFactory,
            $moduleHandler,
            $killSwitch,
            $request,
            $session,
            $context,
            $destination
        );
    }

    /**
     * LTI launch.
     *
     * Authenticates the user via the authentication.lti_tool_provider service,
     * login that user, and then redirect the user to the appropriate page.
     *
     * @return RedirectResponse
     *   Redirect user to appropriate LTI url.
     *
     * @see \Drupal\lti_tool_provider\Authentication\Provider\LTIToolProvider
     *   This controller requires that the authentication.lti_tool_provider
     *   service is attached to this route in lti_tool_provider.routing.yml.
     */
    public function ltiLaunch()
    {
        try {
            $destination = '/';

            if (empty($this->context)) {
                throw new Exception('LTI context missing.');
            }

            if (!empty($this->destination)) {
                $destination = $this->destination;
            }

            if (isset($this->context['custom_destination']) && !empty($this->context['custom_destination'])) {
                $destination = $this->context['custom_destination'];
            }

            $this->moduleHandler->alter('lti_tool_provider_launch_redirect', $destination, $this->context);

            $this->killSwitch->trigger();

            return new RedirectResponse($destination);
        }
        catch (Exception $e) {
            $this->loggerFactory->warning($e->getMessage());

            return new RedirectResponse('/', 500);
        }
    }

    /**
     * LTI return.
     *
     * Logs the user out and returns the user to the LMS.
     *
     * @return RedirectResponse
     *   Redirect user to appropriate return url.
     */
    public function ltiReturn()
    {
        try {
            $destination = '/';

            if (empty($this->context)) {
                throw new Exception('LTI context missing in launch request.');
            }

            if (!empty($this->destination)) {
                $destination = $this->destination;
            }

            if (isset($this->context['launch_presentation_return_url']) && !empty($this->context['launch_presentation_return_url'])) {
                $destination = $this->context['launch_presentation_return_url'];
                $this->userLogout();
            }

            $this->moduleHandler->invokeAll('lti_tool_provider_return', [$this->context]);

            $this->killSwitch->trigger();

            return new TrustedRedirectResponse($destination);
        }
        catch (Exception $e) {
            $this->loggerFactory->warning($e->getMessage());

            return new RedirectResponse('/', 500);
        }
    }

    /**
     * Checks access for LTI routes.
     *
     * @return AccessResult
     *   The access result.
     */
    public function access()
    {
        return AccessResult::allowedIf(!empty($this->context));
    }

    protected function userLogout()
    {
        user_logout();
    }
}
