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
  $return_or_error = t('An error occured while contacting MYDIGIPASS.COM. '
   . 'Please try again. If the problem persists, contact your site '
   . 'administrator.');

  // Check whether the client_secret and client_id have been set.
  $client_secret = variable_get('mydigipass_client_secret', '');
  $client_id = variable_get('mydigipass_client_id', '');
  if (empty($client_secret) || empty($client_secret)) {
    drupal_set_message(
      t('The MYDIGIPASS.COM module has not been correctly '
       . 'configured on this Drupal installation. Either the client_id or the '
       . 'client_secret has not been properly configured.'),
      'error');
    watchdog(
      'mydigipass',
      'The MYDIGIPASS.COM module has not been correctly '
       . 'configured on this Drupal installation. Either the client_id or the '
       . 'client_secret has not been properly configured.',
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
      t('An error occured: the authorisation code does not have the expected '
       . 'format'),
      'error');
    watchdog(
      'mydigipass',
      'An error occured: the authorisation code does not have the expected '
       . 'format',
      array(),
      WATCHDOG_ERROR);
    return $return_or_error;
  }

  // Extract the value of the state parameter.
  $state = $_GET['state'];
  // Validate using regular expression that, if the state parameter is set,
  // the state exists out of [0-9a-z] characters.
  if (! empty($state) && (preg_match('/^[0-9a-z]+$/', $code) != 1)) {
    drupal_set_message(
      t('An error occured: the state parameter does not have the expected '
       . 'format'),
      'error');
    watchdog(
      'mydigipass',
      'An error occured: the state parameter does not have the expected '
       . 'format',
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
  if (! $user_data_array) {
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
      '%uuid' => $user_data_array['uuid']
    ));
  // Mark this session as one in which a MYDIGIPASS.COM user is authenticated.
  $_SESSION['mydigipass_uuid'] = $user_data_array['uuid'];

  // Update the user data in the database
  $sql = "DELETE FROM {mydigipass_user_data} WHERE mdp_uuid = '%s'";
  db_query($sql, $user_data_array['uuid']);
  $sql = "INSERT INTO {mydigipass_user_data} "
    . "(mdp_uuid, attribute_key, attribute_value) "
    . "VALUES ('%s', '%s', '%s')";
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

    $sql = "SELECT name "
      . "FROM {users} U, {mydigipass_user_link} MDP "
      . "WHERE U.uid = MDP.drupal_uid AND mdp_uuid = '%s'";
    $name = db_result(db_query($sql, $user_data_array['uuid']));
    $user = user_load(array('name' => $name));
    $success = user_external_login($user);
    if (! $success) {
      // The user didn't pass the user_external_login function, so probably the
      // user account is disabled or locked. Destroy the current session and
      // make sure the user is set to the anonymous user.
      session_destroy();
      // Load the anonymous user
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
      // Link to currently logged on user
      $sql = "INSERT INTO {mydigipass_user_link} "
        . "(drupal_uid, mdp_uuid) VALUES (%d, '%s')";
      $success = db_query($sql, $user->uid, $user_data_array['uuid']);

      if ($success) {
        drupal_set_message(t('The user has been successfully linked to '
         . 'MYDIGIPASS.COM'));
      }
      else {
        drupal_set_message(
          t('An error occured while linking the user to MYDIGIPASS.COM'),
          'error');
      }

      // Cleanup the session data: the link code is already consumed.
      unset($_SESSION['mydigipass_link_code']);

      // Redirect to the user edit form (which contained the MYDIGIPASS.COM
      // connect button)
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

function _mydigipass_consume_authorisation_code($code) {
  // Determine whether curl is supported or whether we can use fsockopen.
  if (in_array('curl', get_loaded_extensions())) {
    $method = 'curl';
  }
  elseif (function_exists('fsockopen') &&
          in_array('openssl', get_loaded_extensions())) {
    $method = 'fsockopen';
  }
  else {
    drupal_set_message(
      t('This PHP installation lacks the necessary functions to make outbound '
       . 'connections.'),
      'error');
    watchdog(
      'mydigipass',
      'This PHP installation lacks the necessary functions to make outbound '
       . 'connections.',
      array(),
      WATCHDOG_ERROR);
    return FALSE;
  }

  // Step 1: exchange the authorisation code for an access token.
  $access_token = ($method == 'curl' ?
    _mydigipass_callback_get_access_token_using_curl($code) :
    _mydigipass_callback_get_access_token_using_fsockopen($code));

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
      'An error occured: the access token does not have the expected format. '
       . 'Returned token was %token',
      array('%token' => $access_token),
      WATCHDOG_ERROR);
    return FALSE;
  }

  // Step 2: exchange the access token for user data.
  $user_data = ($method == 'curl' ?
    _mydigipass_callback_get_user_data_using_curl($access_token) :
    _mydigipass_callback_get_user_data_using_fsockopen($access_token));

  // Check if the function returned FALSE.
  if ($user_data === FALSE) {
    // A communication error occured. An error message has already been
    // displayed and logged to watchdog.
    return FALSE;
  }

  // Check if the UUID is present
  if (is_array($user_data) && isset($user_data) && !empty($user_data)) {
    return $user_data;
  }
  else {
    return FALSE;
  }
}

/**
 * Private helper function which exchanges the authorisation code for an access
 * token.
 *
 * Exchanges the authorisation code for an access token and connects to
 * MYDIGIPASS.COM using the curl functions.
 *
 * @param $code
 *   The authorisation code which was provided in the callback URL
 *
 * @return
 *   If no errors occured: a string containing the access token.
 *   If errors occured: FALSE
 */
function _mydigipass_callback_get_access_token_using_curl($code) {
  // Get the URL of the token endpoint
  $token_endpoint = _mydigipass_get_endpoint_url('token_endpoint');

  // If either the authorisation code or the token endpoint URL is empty,
  // return FALSE.
  if (empty($code) || empty($token_endpoint)) {
    return FALSE;
  }

  // Exchange the authorisation code for an access token
  $post_data = 'code=' . $code
   . '&client_secret=' . variable_get('mydigipass_client_secret', '')
   . '&client_id=' . variable_get('mydigipass_client_id', '')
   . '&redirect_uri=' . url('mydigipass/callback', array('absolute' => TRUE))
   . '&grant_type=authorization_code';

  $ch = curl_init($token_endpoint);
  curl_setopt($ch, CURLOPT_POST, 1);
  curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
  curl_setopt($ch, CURLOPT_HTTPPROXYTUNNEL, TRUE);
  curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
  curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_0);
  curl_setopt($ch, CURLOPT_CRLF, TRUE);
  curl_setopt($ch, CURLOPT_USERAGENT, 'MYDIGIPASS.COM Drupal module');
  //curl_setopt($ch, CURLOPT_PROXY, "http://proxy:8080");
  //curl_setopt($ch, CURLOPT_PROXYPORT, 8080);
  $data = curl_exec($ch);
  $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

  // Set the return value to FALSE (fail secure).
  $return = FALSE;

  // Check if curl_exec returned no errors
  if ($data === FALSE) {
    drupal_set_message(
      t('An error occured while contacting MYDIGIPASS.COM.'),
      'error');
    watchdog(
      'mydigipass',
      'An error occured while executing the curl_exec to the token endpoint: '
       . 'the error was %error',
      array('%error' => curl_error($ch)),
      WATCHDOG_ERROR);
  }
  elseif ($http_status == 200) {
    // The HTTP request resulted in a HTTP 200 OK response.
    // Extract access token from JSON response
    $access_token_array = json_decode($data, TRUE);
    $return = $access_token_array['access_token'];
  }
  else {
    // MYDIGIPASS.COM did not return a status code 200, so something went
    // wrong.
    drupal_set_message(
      t('An error occured while contacting MYDIGIPASS.COM.'),
      'error');
    watchdog(
      'mydigipass',
      'An error occured while contacting the token endpoint: the HTTP status '
       . 'code was %http_status',
      array('%http_status' => $http_status),
      WATCHDOG_ERROR);
  }

  curl_close($ch);
  return $return;
}

function _mydigipass_callback_get_user_data_using_curl($access_token) {
  // Get the URL of the user data endpoint
  $user_data_endpoint = _mydigipass_get_endpoint_url('data_endpoint');

  // If either the access token  or the user data endpoint URL is empty, return
  // an empty array.
  if (empty($access_token) || empty($user_data_endpoint)) {
    return array();
  }

  // Call MYDIGIPASS.COM User Data Endpoint
  $ch = curl_init($user_data_endpoint);
  curl_setopt($ch,
    CURLOPT_HTTPHEADER,
    array('Authorization: Bearer ' . $access_token));
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
  //curl_setopt($ch, CURLOPT_PROXY, "http://proxy:8080");
  //curl_setopt($ch, CURLOPT_PROXYPORT, 8080);
  curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
  $data = curl_exec($ch);
  $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  // Check the value of the returned HTTP Status Code
  if ($http_status == 200) {
    // Extract user data
    $user_data_array = json_decode($data, TRUE);
    return $user_data_array;
  }
  else {
    // MYDIGIPASS.COM did not return a status code 200, so something is wrong.
    drupal_set_message(
      t('An error occured while contacting MYDIGIPASS.COM.'),
      'error');
    watchdog(
      'mydigipass',
      'An error occured while contacting the user data endpoint: the HTTP status '
       . 'code was %http_status',
      array('%http_status' => $http_status),
      WATCHDOG_ERROR);
    return FALSE;
  }
}

/**
 * Private helper function which exchanges the authorisation code for an
 * access token.
 *
 * Exchanges the authorisation code for an access token and connects to
 * MYDIGIPASS.COM using the fsockopen function.
 *
 * @param $code
 *   The authorisation code which was provided in the callback URL
 *
 * @return
 *   If no errors occured: a string containing the access token.
 *   If errors occured: FALSE
 */
function _mydigipass_callback_get_access_token_using_fsockopen($code) {
  // Get the URL of the token endpoint
  $token_endpoint = _mydigipass_get_endpoint_url('token_endpoint');

  // If either the authorisation code or the token endpoint URL is empty,
  // return an empty string.
  if (empty($code) || empty($token_endpoint)) {
    return FALSE;
  }

  // Set the return value to FALSE. This ensures that this function only
  // returns something when a socket could be opened and when the HTTP status
  // code in the response is 200.
  $return = FALSE;

  // Open an SSL socket to MYDIGIPASS.COM using fsockopen
  $url = parse_url($token_endpoint);
  $fp = fsockopen('ssl://' . $url['host'], 443, $err_num, $err_msg, 30);

  // Check is an error occured (fsockopen() returns FALSE in case of errors).
  if ($fp === FALSE) {
    drupal_set_message(
      t('An error occured while contacting MYDIGIPASS.COM.'),
      'error');
    watchdog(
      'mydigipass',
      'An error occured while opening a socket to the token endpoint using '
       . 'fsockopen: the error was %error',
      array('%error' => $err_msg),
      WATCHDOG_ERROR);
  }
  else {
    // Create the POST request
    $crlf = "\r\n";
    $post_data = 'code=' . $code
     . '&client_secret=' . variable_get('mydigipass_client_secret', '')
     . '&client_id=' . variable_get('mydigipass_client_id', '')
     . '&redirect_uri=' . url('mydigipass/callback', array('absolute' => TRUE))
     . '&grant_type=authorization_code';
    $request = 'POST ' . $url['path'] . ' HTTP/1.0' . $crlf;
    $request .= 'Host: ' . $url['host'] . $crlf;
    $request .= 'User-Agent: MYDIGIPASS.COM Drupal module' . $crlf;
    $request .= 'Content-Type: application/x-www-form-urlencoded' . $crlf;
    $request .= 'Content-Length: '. drupal_strlen($post_data) . $crlf . $crlf;
    $request .= $post_data;

    // Send the request and collect the response.
    fputs($fp, $request);
    $http_response = '';
    while (!feof($fp)) {
      $http_response .= fgets($fp, 128);
    }
    fclose($fp);

    // Extract the HTTP status code
    $arr_http_response = explode($crlf . $crlf, $http_response, 2);
    $http_status_array = explode(' ', $arr_http_response[0]);

    if ($http_status_array[1] == '200') {
      // The HTTP request resulted in a HTTP 200 OK response.
      // The HTTP response is the full response including the headers, so
      // extract the response body.
      $arr_http_response = explode($crlf . $crlf, $http_response);
      $access_token_array = json_decode($arr_http_response[1], TRUE);
      $return = $access_token_array['access_token'];
    }
    else {
      // MYDIGIPASS.COM did not return a status code 200, so something went
      // wrong.
      drupal_set_message(
        t('An error occured while contacting MYDIGIPASS.COM.'),
        'error');
      watchdog(
        'mydigipass',
        'An error occured while contacting the token endpoint: the HTTP status'
         . 'code was %http_status',
        array('%http_status' => $http_status_array[1]),
        WATCHDOG_ERROR);
    }
  }

  return $return;
}

/**
 * Private helper function which exchanges the access token for the user data.
 *
 * Exchanges the access token for the user data and connects to MYDIGIPASS.COM
 * using the fsockopen function.
 *
 * @param $access_token
 *   The access token which was received from MYDIGIPASS.COM
 *
 * @return
 *   If no errors occured: An associative array which contains the user data
 *                         in 'attribute_name' => 'attribute_value' pairs.
 *   If errors occured: FALSE
 */
function _mydigipass_callback_get_user_data_using_fsockopen($access_token) {
  // Get the URL of the user data endpoint
  $user_data_endpoint = _mydigipass_get_endpoint_url('data_endpoint');

  // If either the access token or the user data endpoint URL is empty, return
  // an empty array.
  if (empty($access_token) || empty($user_data_endpoint)) {
    return FALSE;
  }

  // Set the return value to FALSE. This ensures that this function only
  // returns something when a socket could be opened and when the HTTP status
  // code in the response is 200.
  $return = FALSE;

  // Open an SSL socket to MYDIGIPASS.COM using fsockopen
  $url = parse_url($user_data_endpoint);
  $fp = fsockopen('ssl://' . $url['host'], 443, $err_num, $err_msg, 30);

  // Check is an error occured (fsockopen() returns FALSE in case of errors).
  if ($fp === FALSE) {
    drupal_set_message(
      t('An error occured while contacting MYDIGIPASS.COM.'),
      'error');
    watchdog(
      'mydigipass',
      'An error occured while opening a socket to the user data endpoint using'
       . 'fsockopen: the error was %error',
      array('%error' => $err_msg),
      WATCHDOG_ERROR);
  }
  else {
    // Create the GET request
    $crlf = "\r\n";
    $request = 'GET ' . $url['path'] . ' HTTP/1.0' . $crlf;
    $request .= 'Host: ' . $url['host'] . $crlf;
    $request .= 'Authorization: Bearer ' . $access_token . $crlf;
    $request .= 'User-Agent: MYDIGIPASS.COM Drupal module' . $crlf;
    $request .= $crlf;

    // Send the request and collect the response
    fputs($fp, $request);
    $http_response = '';
    while (!feof($fp)) {
      $http_response .= fgets($fp, 128);
    }
    fclose($fp);

    // Extract the HTTP status code
    $arr_http_response = explode($crlf . $crlf, $http_response, 2);
    $http_status_array = explode(' ', $arr_http_response[0]);

    if ($http_status_array[1] == '200') {
      // The HTTP request resulted in a HTTP 200 OK response.
      // The HTTP response is the full response including the headers, so
      // extract the response body.
      $arr_http_response = explode($crlf . $crlf, $http_response);
      $result = json_decode($arr_http_response[1], TRUE);
    }
    else {
      // MYDIGIPASS.COM did not return a status code 200, so something went
      // wrong.
      drupal_set_message(
        t('An error occured while contacting MYDIGIPASS.COM.'),
        'error');
      watchdog(
        'mydigipass',
        'An error occured while contacting the user data endpoint: the HTTP '
         . 'status code was %http_status',
        array('%http_status' => $http_status_array[1]),
        WATCHDOG_ERROR);
    }
  }

  return $result;
}
