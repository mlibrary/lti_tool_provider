<?php

/**
 * @file
 * Menu page callbacks for the lti operations launch, return as well as home and info.
 */

/**
 * LTI launch.
 *
 * Implements the [base_url]/lti path which is called to launch the
 * tool from the LMS
 *  - Verifies the request using OAuth and adds the lti context_info
 *    to the session
 *  - Authenticates the user, possibly after provisioning the account
 *  - Authorises the user via global role mapping
 *  - If OG is configured a course may be provisioned from the
 *    context_info and group roles are mapped
 *  - Finally the destination is calculated and user is redirected there.
 *
 * @return string
 *   Page content.
 */
function lti_tool_provider_launch() {
  global $user;
  // If not an LTI launch request, then ignore.
  if (!lti_tool_provider_is_basic_lti_request()) {
    drupal_set_message(t('Not a LTI request.'), 'info');
    return 'Error: Not a LTI request.';
  }
  // Insure we have a valid context.
  if (empty($_REQUEST['oauth_consumer_key'])) {
    unset($_SESSION['lti_tool_provider_context_info']);
    drupal_set_message(t('Not a valid LTI context.'), 'info');
    return 'Error: Invalid context. Missing oauth_consumer_key in request';
  }
  // Begin a new session based on this LTI launch request.
  drupal_session_start();
  $oauth_consumer_key = $_REQUEST["oauth_consumer_key"];
  // Verify the message signature.
  $store = new LTIToolProviderOAuthDataStore();
  $server = new OAuthServer($store);
  $method = new OAuthSignatureMethod_HMAC_SHA1();
  $server->add_signature_method($method);
  $request = OAuthRequest::from_request();
  try {
    $server->verify_request($request);
    // This is a LTI launch request.
  } catch (Exception $e) {
    drupal_set_message($e->getMessage(), 'error');
    return 'Error: Invalid context, OAuth failure.';
  }
  // Collect the launch information for later storage in the session.
  $launch_info = $request->get_parameters();
  $launch_info['consumer_domain'] = lti_tool_provider_retrieve_consumer_domain($oauth_consumer_key);
  if (isset($launch_info['context_id']) && isset($launch_info['user_id'])) {
    $lti_user = $launch_info['user_id'] . $launch_info['consumer_domain'];
  }
  else {
    $lti_user = 'lti_user' . $launch_info['consumer_domain'];
  }
  // Revalidate incoming user.
  if ($user->uid > 0 && $user->name != $lti_user) {
    // Log out prior user and clear session, retaining lti session variables.
    watchdog('user', 'Session closed for %name.', array('%name' => $user->name));
    module_invoke_all('user_logout', $user);
    // Destroy the current session, and reset $user to the anonymous user.
    session_destroy();
    drupal_session_start();
    drupal_set_message(t('Logged current user out.'), 'info');
  }
  if ($user->uid == 0) {
    // Unauthenticated so create user if necessary.
    if ($account = user_load_by_name($lti_user)) {
      // User exists.
      if ($account->uid == 1) {
        // User 1 must use drupal authentication.
        drupal_set_message(t('Admin account must use Drupal authentication.'), 'error');
        return;
      }
    }
    else {
      $account = lti_tool_provider_create_account($lti_user, $launch_info);
    }
    // Log the user in.
    $form_state = array('uid' => $account->uid);
    user_login_submit(array(), $form_state);
  }
  else {
    $account = user_load($user->uid);
  }
  // $account is the $lti_user.
  // Map Drupal global roles based on the user LTI role.
  if (isset($launch_info['roles'])) {
    lti_tool_provider_assign_global_roles_to_user($launch_info['roles'], $account->uid);
    drupal_static_reset('user_access');
  }
  $destination = '';
  module_invoke_all('lti_tool_provider_launch', $launch_info, $account);
  drupal_alter('lti_tool_provider_launch', $launch_info, $destination, $account);
  // Go to the required destination.
  if (key_exists('custom_destination', $launch_info)) {
    $destination .= '/' . $launch_info['custom_destination'];
  }
  // Save launch information in session.
  $_SESSION['lti_tool_provider_context_info'] = $launch_info;
  // Set language in session.
  if (isset($launch_info['launch_presentation_locale'])) {
    $_SESSION['language'] = drupal_strtolower($launch_info['launch_presentation_locale']);
  }
  lti_tool_provider_goto($destination);
  return 'Launched';
}

/**
 * Menu title callback for the lti return menu item.
 */
function lti_tool_provider_return_title($title) {
  if (isset($_SESSION['lti_tool_provider_context_info'])) {
    if (key_exists('custom_return_label', $_SESSION['lti_tool_provider_context_info'])) {
      return $_SESSION['lti_tool_provider_context_info']['custom_return_label'];
    }
    return 'Return to LMS';
  }
}

/**
 * Page callback for the lti return menu item.
 *
 * @return string
 *   The page content if any.
 */
function lti_tool_provider_return() {
  // Logout the user and redirect to the return url in the ltitp_context_info.
  global $user;
  // If in a lti context log user out and return to launch presentation.
  // otherwise just logout.
  if (isset($_SESSION['lti_tool_provider_context_info'])) {
    module_invoke_all('lti_tool_provider_return');
    $url = $_SESSION['lti_tool_provider_context_info']['launch_presentation_return_url'];
    watchdog('user', 'Session closed for %name.', array('%name' => $user->name));
    module_invoke_all('user_logout', $user);
    // Destroy the current session, and reset $user to the anonymous user.
    session_destroy();
    lti_tool_provider_goto($url);
  }
  else {
    lti_tool_provider_goto('user/logout');
  }
  return '';
}

/**
 * Menu title callback for the LTI Home menu item.
 *
 * @return string
 *   The title.
 */
function lti_tool_provider_home_title($title) {
  if (isset($_SESSION['lti_tool_provider_context_info'])) {
    if (key_exists('custom_destination_label', $_SESSION['lti_tool_provider_context_info'])) {
      return $_SESSION['lti_tool_provider_context_info']['custom_destination_label'];
    }
    else {
      if (key_exists('resource_link_title', $_SESSION['lti_tool_provider_context_info'])) {
        return $_SESSION['lti_tool_provider_context_info']['resource_link_title'];
      }
    }
  }
  return 'Home';
}

/**
 * Menu page callback for the LTI Home menu item.
 */
function lti_tool_provider_home() {
  // If in a lti context return to group home page + custom destination.
  // Otherwise just the site home.
  $destination = '';
  if (isset($_SESSION['lti_tool_provider_context_info'])) {
    if (key_exists('course_entity_type', $_SESSION['lti_tool_provider_context_info'])) {
      $destination = $_SESSION['lti_tool_provider_context_info']['course_entity_type'] . '/' . $_SESSION['lti_tool_provider_context_info']['course_entity_eid'];
    }
    if (key_exists('custom_destination', $_SESSION['lti_tool_provider_context_info'])) {
      $destination .= '/' . $_SESSION['lti_tool_provider_context_info']['custom_destination'];
    }
  }
  drupal_goto($destination);
}

/**
 * Menu page callback for the LTI Info menu items.
 *
 * @return array
 *   The conent for the page.
 */
function lti_tool_provider_info() {
  // Display all the key/value pairs in the ltitp_context_info.
  $content = array();
  $content[] = array(
    '#type' => 'item',
    '#markup' => t('LTI Context Session Variables'),
  );
  if (isset($_SESSION['lti_tool_provider_context_info'])) {
    $info = $_SESSION['lti_tool_provider_context_info'];
    $rows = array();
    $loop_counter = 0;
    foreach ($info as $key => $value) {
      $rows[$loop_counter]['data']['key'] = $key;
      $rows[$loop_counter]['data']['value'] = $value;
      $loop_counter++;
    }
    $content['table'] = array(
      '#theme' => 'table',
      '#rows' => $rows,
      '#header' => array(t('Key'), t('Value')),
    );
  }
  else {
    $content['table'] = array(
      '#type' => 'item',
      '#markup' => t('There is no LTI Context'),
    );
  }
  return $content;
}