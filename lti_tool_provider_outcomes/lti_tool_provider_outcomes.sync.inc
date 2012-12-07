<?php
/**
 * @file
 * Contains all functions related to retrieving/sending outcomes from/to
 * the consumers outcomes service.
 */

module_load_include('inc', 'lti_tool_provider_outcomes', 'lti_tool_provider_outcomes.connect');

/**
 * Function invoked when url is accessed.
 *
 * Sends all the results belonging to the current user back to the LMS site.
 *
 * @param string $return_url
 *   To customise return page when URL is accessed.
 * @param int $mode
 *   The scope of results to retrieve.
 */
function lti_tool_provider_outcomes_send_results($return_url = '', $mode) {
  global $user;
  // Consumer_id based on the context.
  $consumer = lti_tool_provider_get_consumer_by_key($_SESSION['lti_tool_provider_context_info']['oauth_consumer_key']);
  $uid = $user->uid;
  $context_id = $_SESSION['lti_tool_provider_context_info']['context_id'];
  $key = $_SESSION['lti_tool_provider_context_info']['oauth_consumer_key'];
  $resource_link_id = $_SESSION['lti_tool_provider_context_info']['resource_link_id'];
  $bool = TRUE;
  // Retrieve all results.
  switch ($mode) {
    case 1: {
      // For all results of current user.
      $outcomes_entities = lti_tool_provider_outcomes_retrieve_results_by_user($consumer->lti_tool_provider_consumer_id, $uid, $context_id);
      break;
    }
    // For all results in current resource.
    case 2: {
      $outcomes_entities = lti_tool_provider_outcomes_retrieve_results_by_resource_for_context($consumer->lti_tool_provider_consumer_id, $context_id, $resource_link_id);
      break;
    }
    // For all results in current context.
    case 3: {
      $outcomes_entities = lti_tool_provider_outcomes_retrieve_results_for_context($consumer->lti_tool_provider_consumer_id, $context_id);
      break;
    }
    // For all results.
    case 4: {
      $outcomes_entities = lti_tool_provider_outcomes_retrieve_all_results();
      break;
    }
  }
  if (isset($outcomes_entities)) {
    $url = $consumer->lti_tool_provider_consumer_outcomes_url;
    if (!isset($url)) {
      drupal_set_message(t('Unable to send results. No Outcome URL is avaliable', 'error'));
      drupal_goto($return_url);
    }
    else {
      $outcomes_counter = 0;
      $total_outcomes = count($outcomes_entities);
      foreach ($outcomes_entities as $outcome) {
        $resource = lti_tool_provider_outcomes_resource_load($outcome->lti_tool_provider_outcomes_resource_entity_id_fk);
        if ($consumer->lti_tool_provider_consumer_ext) {
          $params = lti_tool_provider_outcomes_construct_params_array_update($outcome->lti_tool_provider_outcomes_result_sourcedid, $outcome->lti_tool_provider_outcomes_score, $resource->lti_tool_provider_outcomes_resource_score_datatype_base_value, lti_tool_provider_outcomes_get_datatype_by_resource($outcome->lti_tool_provider_outcomes_resource_entity_id_fk, 2));
          $response = lti_tool_provider_outcomes_lti_service_post($params, $consumer->lti_tool_provider_consumer_key, $consumer->lti_tool_provider_consumer_outcomes_url);
          $success = lti_tool_provider_outcomes_decode_ext_xml_reply($response);
        }
        else {
          $response = lti_tool_provider_outcomes_lti_service_xml($consumer, $consumer->lti_tool_provider_consumer_outcomes_url, 1, $outcome->lti_tool_provider_outcomes_result_sourcedid, $outcome->lti_tool_provider_outcomes_score);
          $success = lti_tool_provider_outcomes_decode_xml_reply($response);
        }
        if ($success) {
          // Once sent, update timestamp.
          lti_tool_provider_outcomes_update_timestamp($outcome);
          $outcomes_counter++;
        }
        else {
          // Unable to update.
          // Display error.
          drupal_set_message(check_plain('Unable to send result ID:' . $outcome->lti_tool_provider_outcomes_id, 'error'));
          $bool = FALSE;
        }
      }
    }
    if ($bool) {
      drupal_set_message(t('@total_outcomes outcomes retrieved. @outcomes_counter outcomes has been sent. All results has been sent back and updated', array(
      '@total_outcomes' => $total_outcomes,
      '@outcomes_counter' => $outcomes_counter,
      )));
    }
    drupal_goto($return_url);
  }
  else {
    drupal_set_message(t('There is nothing to send.'));
    drupal_goto($return_url);
  }
}

/**
 * Retrieves results from the LMS site.
 *
 * Function invoked when url is accessed.
 *
 * @param string $return_url
 *   To customise return page when URL is accessed.
 * @param int $mode
 *   The scope of results to retrieve.
 */
function lti_tool_provider_outcomes_pull_results($return_url = '', $mode) {
  global $user;
  // Consumer_id based on the context.
  $consumer = lti_tool_provider_get_consumer_by_key($_SESSION['lti_tool_provider_context_info']['oauth_consumer_key']);
  $uid = $user->uid;
  $context_id = $_SESSION['lti_tool_provider_context_info']['context_id'];
  $resource_sourcedid = isset($_SESSION['lti_tool_provider_context_info']['lis_result_sourcedid']) ? $_SESSION['lti_tool_provider_context_info']['lis_result_sourcedid'] : NULL;
  $key = $_SESSION['lti_tool_provider_context_info']['oauth_consumer_key'];
  $resource_link_id = $_SESSION['lti_tool_provider_context_info']['resource_link_id'];
  $bool = TRUE;
  // Retrieve all results.
  switch ($mode) {
    case 0: {
      // For this particular resource score.
      $outcomes_entities = lti_tool_provider_outcomes_get_current_outcome($resource_sourcedid, $context_id, $resource_link_id, $consumer->lti_tool_provider_consumer_id, $uid);
      break;
    }
    case 1: {
      // For all results of current user.
      $outcomes_entities = lti_tool_provider_outcomes_retrieve_results_by_user($consumer->lti_tool_provider_consumer_id, $uid, $context_id);
      break;
    }
    // For all results in current resource.
    case 2: {
      $outcomes_entities = lti_tool_provider_outcomes_retrieve_results_by_resource_for_context($consumer->lti_tool_provider_consumer_id, $context_id, $resource_link_id);
      break;
    }
    // For all results in current context.
    case 3: {
      $outcomes_entities = lti_tool_provider_outcomes_retrieve_results_for_context($consumer->lti_tool_provider_consumer_id, $context_id);
      break;
    }
    // For all results.
    case 4: {
      $outcomes_entities = lti_tool_provider_outcomes_retrieve_all_results();
      break;
    }
  }
  if (isset($outcomes_entities)) {
    // Construct params.
    $url = $consumer->lti_tool_provider_consumer_outcomes_url;
    if (!isset($url)) {
      drupal_set_message(t('Unable to send results. No Outcome URL is avaliable', 'error'));
      drupal_goto($return_url);
    }
    else {
      $outcomes_counter = 0;
      $total_outcomes = count($outcomes_entities);
      foreach ($outcomes_entities as $outcome) {
        if ($consumer->lti_tool_provider_consumer_ext) {
          $params = lti_tool_provider_outcomes_construct_params_array_read($outcome->lti_tool_provider_outcomes_result_sourcedid);
          $response = lti_tool_provider_outcomes_lti_service_post($params, $consumer->lti_tool_provider_consumer_key, $consumer->lti_tool_provider_consumer_outcomes_url);
          $score = lti_tool_provider_outcomes_extract_ext_xml_score_reply($response);
          $success = isset($score) ? TRUE : FALSE;
        }
        else {
          $response = lti_tool_provider_outcomes_lti_service_xml($consumer, $consumer->lti_tool_provider_consumer_outcomes_url, 2, $outcome->lti_tool_provider_outcomes_result_sourcedid);
          $score = lti_tool_provider_outcomes_extract_xml_score_reply($response);
          $success = isset($score) ? TRUE : FALSE;
        }
        if ($success) {
          // Once sent, update timestamp.
          lti_tool_provider_outcomes_updated_pulled_score($outcome, $score);
          $outcomes_counter++;
        }
        else {
          // Unable to update.
          // Display error.
          drupal_set_message(check_plain('Unable to retreive result ID:' . $outcome->lti_tool_provider_outcomes_id, 'error'));
          $bool = FALSE;
        }
      }
    }
    if ($bool) {
      drupal_set_message(t('@total_outcomes outcomes found. @outcomes_counter outcomes successfully retrieved from LMS site. All results has been retrieved', array(
      '@total_outcomes' => $total_outcomes,
      '@outcomes_counter' => $outcomes_counter,
      )));
    }
    drupal_goto($return_url);
  }
  else {
    drupal_set_message(t('There is nothing to retrieve.'));
    drupal_goto($return_url);
  }
}
