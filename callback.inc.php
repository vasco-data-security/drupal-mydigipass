<?php

/**
 * @file
 * Implementation of the MYDIGIPASS.COM OAuth2 callback function.
 */

/**
 * Page callback for 'mydigipass/callback'.
 */
function mydigipass_callback() {
  // Define the error message which will be returned as the page body in case
  // an error occurs while the function is being executed.
  $return_or_error = t('An error occured while contacting MYDIGIPASS.COM. Please try again. If the problem persists, contact your site administrator.');

  // Check whether the client_secret and client_id have been set.
  $client_secret = variable_get('mydigipass_client_secret', '');
  $client_id = variable_get('mydigipass_client_id', '');
  if (empty($client_secret) || empty($client_secret)) {
    drupal_set_message(
      t('The MYDIGIPASS.COM module has not been correctly configured on this Drupal installation. Either the client_id or the client_secret has not been properly configured.'),
      'error');
    watchdog(
      'mydigipass',
      'The MYDIGIPASS.COM module has not been correctly configured on this Drupal installation. Either the client_id or the client_secret has not been properly configured.',
      array(),
      WATCHDOG_ERROR);
    return $return_or_error;
  }

  // Check if the callback contained the error parameter. If so, something must
  // have gone wrong.
  if (isset($_GET['error'])) {
    $error = $_GET['error'];
    $error_description = $_GET['error_description'];

    drupal_set_message(
      t('An error occured: Error: @error - Error description: @error_description',
        array('@error' => $error, '@error_description' => $error_description)),
      'error');
    watchdog(
      'mydigipass',
      'An error occured: Error: @error - Error description: @error_description',
      array('@error' => $error, '@error_description' => $error_description),
      WATCHDOG_ERROR);
    return $return_or_error;
  }

  // Extract the authorisation code.
  $code = $_GET['code'];
  // Validate using regular expression that the authorisation code exists out
  // of [0-9a-z] characters.
  if (preg_match('/^[0-9a-z]+$/', $code) != 1) {
    drupal_set_message(
      t('An error occured: the authorisation code does not have the expected format'),
      'error');
    watchdog(
      'mydigipass',
      'An error occured: the authorisation code does not have the expected format',
      array(),
      WATCHDOG_ERROR);
    return $return_or_error;
  }

  // Extract the value of the state parameter.
  $state = $_GET['state'];
  // Validate using regular expression that, if the state parameter is set,
  // the state exists out of [0-9a-z] characters.
  if (!empty($state) && (preg_match('/^[0-9a-z]+$/', $code) != 1)) {
    drupal_set_message(
      t('An error occured: the state parameter does not have the expected format'),
      'error');
    watchdog(
      'mydigipass',
      'An error occured: the state parameter does not have the expected format',
      array(),
      WATCHDOG_ERROR);
    return $return_or_error;
  }

  // The following function call will perform the actual communication with
  // MYDIGIPASS.COM and will exchange the authorisation code for an access
  // token and additionaly exchange the access token for user data.
  $user_data_array = _mydigipass_consume_authorisation_code($code);

  // Check the value of $user_data_array: if it is FALSE, then an error occured
  // during the communication with MYDIGIPASS.COM.
  if (!$user_data_array) {
    // An error occured during the process. Not necessary to report any errors
    // since this has already been done earlier. Just return.
    return $return_or_error;
  }

  // Log that the user connected via MDP.
  watchdog(
    'mydigipass',
    'Connection from MYDIGIPASS.COM user with mail %email and UUID %uuid.',
    array(
      '%email' => $user_data_array['email'],
      '%uuid' => $user_data_array['uuid'],
    ));
  // Mark this session as one in which a MYDIGIPASS.COM user is authenticated.
  $_SESSION['mydigipass_uuid'] = $user_data_array['uuid'];

  // Update the user data in the database.
  $sql = "DELETE FROM {mydigipass_user_data} WHERE mdp_uuid = '%s'";
  db_query($sql, $user_data_array['uuid']);
  $sql = "INSERT INTO {mydigipass_user_data} (mdp_uuid, attribute_key, attribute_value) VALUES ('%s', '%s', '%s')";
  foreach ($user_data_array as $key => $value) {
    db_query($sql, $user_data_array['uuid'], $key, $value);
  }

  // At this point, the end-user has authenticated himself to MYDIGIPASS.COM.
  // Check whether this end-user is already linked to a Drupal user.
  $sql = "SELECT count(*) FROM {mydigipass_user_link} WHERE mdp_uuid = '%s'";
  $result = db_result(db_query($sql, $user_data_array['uuid']));

  if ($result == 1) {
    // The MYDIGIPASS.COM end-user is already linked to an existing Drupal
    // user, let's authenticate the Drupal user.
    global $user;

    $sql = "SELECT name FROM {users} U, {mydigipass_user_link} MDP WHERE U.uid = MDP.drupal_uid AND mdp_uuid = '%s'";
    $name = db_result(db_query($sql, $user_data_array['uuid']));
    $user = user_load(array('name' => $name));
    $success = user_external_login($user);
    if (!$success) {
      // The user didn't pass the user_external_login function, so probably the
      // user account is disabled or locked. Destroy the current session and
      // make sure the user is set to the anonymous user.
      session_destroy();
      // Load the anonymous user.
      $user = drupal_anonymous_user();
    }

    // Redirect the user to the front page.
    drupal_goto('<front>');

    return;
  }
  else {
    // The MYDIGIPASS.COM end-user is not yet linked to an existing Drupal
    // user.
    // Check if the user is logged in and if the state parameter is set.
    // If so, then the user clicked the 'Link to MYDIGIPASS.COM' button in his
    // profile.
    if (user_is_logged_in() && !empty($_SESSION['mydigipass_link_code']) &&
        ($state == $_SESSION['mydigipass_link_code'])) {
      global $user;
      // Link to currently logged on user.
      $sql = "INSERT INTO {mydigipass_user_link} "
        . "(drupal_uid, mdp_uuid) VALUES (%d, '%s')";
      $success = db_query($sql, $user->uid, $user_data_array['uuid']);

      if ($success) {
        drupal_set_message(t('The user has been successfully linked to MYDIGIPASS.COM'));
      }
      else {
        drupal_set_message(
          t('An error occured while linking the user to MYDIGIPASS.COM'),
          'error');
      }

      // Cleanup the session data: the link code is already consumed.
      unset($_SESSION['mydigipass_link_code']);

      // Redirect to the user edit form (which contained the MYDIGIPASS.COM
      // connect button).
      drupal_goto('user/' . $user->uid . '/edit');
      return;
    }
    else {
      // Show the mydigipass_login_form and mydigipass_user_register.
      if ($state == 'register') {
        drupal_goto('mydigipass/link/new_user');
      }
      else {
        drupal_goto('mydigipass/link');
      }
      return;
    }
  }

  return;
}

/**
 * Private helper function which consumes the OAuth authorisation code.
 *
 * This function determines whether cURL should be used or whether fsockopen
 * should be used to connect to MYDIGIPASS.COM. Afterwards it exchanges the
 * authorisation code for an access token. Using the access token, it collects
 * the end-user data from MYDIGIPASS.COM.
 *
 * @param string $code
 *   The authorisation code which was extracted from the callback URL.
 *
 * @return array|bool
 *   An array containing the end-user data or FALSE in case an error occured.
 */
function _mydigipass_consume_authorisation_code($code) {
  // Step 1: exchange the authorisation code for an access token.
  $access_token = _mydigipass_callback_get_access_token($code);

  // Check if the function returned FALSE.
  if ($access_token === FALSE) {
    // A communication error occured. An error message has already been
    // displayed and logged to watchdog.
    return FALSE;
  }

  // Validate using regular expression that the access token exists out of
  // [0-9a-z] characters.
  if (preg_match('/^[0-9a-z]+$/', $access_token) != 1) {
    drupal_set_message(
      t('An error occured: the access token does not have the expected format'),
      'error');
    watchdog(
      'mydigipass',
      'An error occured: the access token does not have the expected format. Returned token was %token',
      array('%token' => $access_token),
      WATCHDOG_ERROR);
    return FALSE;
  }

  // Step 2: exchange the access token for user data.
  $user_data = _mydigipass_callback_get_user_data($access_token);

  // Check if the function returned FALSE.
  if ($user_data === FALSE) {
    // A communication error occured. An error message has already been
    // displayed and logged to watchdog.
    return FALSE;
  }

  // Check if the UUID is present.
  if (is_array($user_data) && isset($user_data['uuid']) &&
    !empty($user_data['uuid'])) {
    return $user_data;
  }
  else {
    return FALSE;
  }
}

/**
 * Helper function which exchanges the authorisation code for an access token.
 *
 * @param string $code
 *   The authorisation code which was provided in the callback URL.
 *
 * @return string|bool
 *   If no errors occured: a string containing the access token.
 *   If errors occured: FALSE
 */
function _mydigipass_callback_get_access_token($code) {
  // Get the URL of the token endpoint.
  $token_endpoint = _mydigipass_get_endpoint_url('token_endpoint');

  // If either the authorisation code or the token endpoint URL is empty,
  // return FALSE.
  if (empty($code) || empty($token_endpoint)) {
    return FALSE;
  }

  // Exchange the authorisation code for an access token.
  $post_data = array(
    'code' => $code,
    'client_secret' => variable_get('mydigipass_client_secret', ''),
    'client_id' => variable_get('mydigipass_client_id', ''),
    'redirect_uri' => variable_get('mydigipass_callback_url', url('mydigipass/callback', array('absolute' => TRUE))),
    'grant_type' => 'authorization_code',
  );
  $request_data = http_build_query($post_data, '', '&');
  $request_headers = array('Content-Type' => 'application/x-www-form-urlencoded');

  $result = drupal_http_request($token_endpoint, $request_headers, 'POST', $request_data);

  // Fail secure: set return value to FALSE.
  $return = FALSE;

  switch ($result->code) {
    case 200:
    case 301:
    case 302:
      $access_token_array = json_decode($result->data, TRUE);
      $return = $access_token_array['access_token'];
      break;

    default:
      watchdog('mydigipass', 'An error occured while contacting MYDIGIPASS.COM: "%error".', array('%error' => $result->code . ' ' . $result->error), WATCHDOG_WARNING);
      drupal_set_message(t('An error occured while contacting MYDIGIPASS.COM.'));
  }
  return $return;
}

/**
 * Private helper function which exchanges the access token for the user data.
 *
 * @param string $access_token
 *   The access token which was received from MYDIGIPASS.COM.
 *
 * @return array|bool
 *   If no errors occured: An associative array which contains the user data
 *                         in 'attribute_name' => 'attribute_value' pairs.
 *   If errors occured: FALSE
 */
function _mydigipass_callback_get_user_data($access_token) {
  // Get the URL of the user data endpoint.
  $user_data_endpoint = _mydigipass_get_endpoint_url('data_endpoint');

  // If either the access token  or the user data endpoint URL is empty, return
  // an empty array.
  if (empty($access_token) || empty($user_data_endpoint)) {
    return array();
  }

  $request_headers = array('Authorization' => 'Bearer ' . $access_token);
  // Call MYDIGIPASS.COM User Data Endpoint.
  $result = drupal_http_request($user_data_endpoint, $request_headers);

  // Fail secure: set return value to FALSE.
  $return = FALSE;

  switch ($result->code) {
    case 200:
    case 301:
    case 302:
      $return = json_decode($result->data, TRUE);

      break;
    default:
      watchdog('mydigipass', 'An error occured while contacting MYDIGIPASS.COM: "%error".', array('%error' => $result->code . ' ' . $result->error), WATCHDOG_WARNING);
      drupal_set_message(t('An error occured while contacting MYDIGIPASS.COM.'));
  }
  return $return;
}
