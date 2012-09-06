<?php

/**
 * @file
 * Administrative page callbacks for the mydigipass module.
 */

/**
 * Page callback: Shows an administration form.
 *
 * Page callback for 'admin/settings/mydigipass' and for
 * 'admin/settings/mydigipass/account_settings'. The form allows configuring
 * the mydigipass module.
 *
 * @see mydigipass_admin_settings_submit_test_connectivity()
 *
 * @return array
 *   Admin form generated by system_settings_form().
 */
function mydigipass_admin_settings($form_state) {
  $form = array();

  $form['mydigipass_integration_enabled'] = array(
    '#type' => 'checkbox',
    '#title' => t('Enable MYDIGIPASS.COM integration'),
    '#default_value' => variable_get('mydigipass_integration_enabled', 0),
  );

  $form['account_settings'] = array(
    '#type' => 'fieldset',
    '#title' => t('Account settings'),
  );
  $form['account_settings']['mydigipass_environment'] = array(
    '#type' => 'radios',
    '#title' => t('Environment'),
    '#options' => array('test' => t('Sandbox / developer'), 'production' => t('Production')),
    '#default_value' => variable_get('mydigipass_environment', ''),
    '#required' => TRUE,
  );
  $form['account_settings']['mydigipass_client_id'] = array(
    '#type' => 'textfield',
    '#title' => 'client_id',
    '#default_value' => variable_get('mydigipass_client_id', ''),
    '#description' => t('The client identifier issued by MYDIGIPASS.COM.'),
    '#required' => TRUE,
  );
  // If the client_secret has already been set, we don't show the textfield by
  // default. This is because it is a password field of which it is not possible
  // to set a default_value. In order to avoid accidental overwriting the
  // client_secret, we hide the input field.
  $client_secret = variable_get('mydigipass_client_secret', '');
  if (empty($client_secret) || ($_GET['edit_password'] == 1)) {
    $form['account_settings']['mydigipass_client_secret'] = array(
      '#type' => 'password',
      '#title' => 'client_secret',
      // For security reasons, the client_secret is never filled in. This is
      // also the default behaviour of a password-type form field.
      // '#default_value' => variable_get('mydigipass_client_secret', ''),
      '#description' => t('The client secret issued by MYDIGIPASS.COM.'),
      '#required' => TRUE,
    );
  }
  else {
    $form['account_settings'][] = array(
      '#type' => 'item',
      '#title' => 'client_secret',
      '#value' => t('Click <a href="@here">here</a> to edit the client_secret', array('@here' => url('admin/settings/mydigipass', array('query' => array('edit_password' => 1))))),
    );
  }
  $form['account_settings']['mydigipass_callback_url'] = array(
    '#type' => 'textfield',
    '#title' => t('Callback URL'),
    '#default_value' => variable_get('mydigipass_callback_url', url('mydigipass/callback', array('absolute' => TRUE))),
    '#description' => t('The callback URL of this website. The default value is !url.<br />Ensure that this is the same url which you submitted to MYDIGIPASS.COM!', array('!url' => url('mydigipass/callback', array('absolute' => TRUE)))),
    '#required' => TRUE,
  );


  $form['authentication_mode'] = array(
    '#type' => 'fieldset',
    '#title' => t('Authentication mode'),
  );
  $form['authentication_mode']['mydigipass_authentication_mode'] = array(
    '#type' => 'radios',
    '#title' => t('Choose authentication mode'),
    '#options' => array(
      'mdp_only' => t('MYDIGIPASS.COM only'),
      'mixed' => t('Mixed mode. The end-user can logon with both MYDIGIPASS.COM and with username/password.'),
    ),
    '#default_value' => variable_get('mydigipass_authentication_mode', 'mdp_only'),
    '#description' => t('The most secure authentication mode is <i>MYDIGIPASS.COM only</i>. In this mode, a user is forced to authenticate using two-factor authentication from MYDIGIPASS.COM once he/she has linked his/her Drupal account to MYDIGIPASS.COM. In <i>Mixed mode</i>, an end-user still can logon with username/password.<br />Note: users who can have not yet linked their account with a MYDIGIPASS.COM account are not affected by this setting.'),
    '#required' => TRUE,
  );

  $form['test_connectivity'] = array(
    '#type' => 'fieldset',
    '#title' => t('Test the connectivity to MYDIGIPASS.COM'),
    '#description' => t('By clicking this button you can test whether this webserver can connect to MYDIGIPASS.COM. In order to do this, it will make an outbound HTTPS connection.'),
  );
  $form['test_connectivity']['submit_test_connectivity'] = array(
    '#type' => 'submit',
    '#submit' => array('mydigipass_admin_settings_submit_test_connectivity'),
    '#value' => t('Test connectivity'),
  );

  return system_settings_form($form);
}

/**
 * Submit handler connected to the 'Test connectivity button'.
 *
 * The button is present on 'admin/settings/mydigipass' and on
 * 'admin/settings/mydigipass/account_settings'.
 *
 * Tests whether the webserver is able to connect to the MYDIGIPASS.COM
 * service.
 *
 * @return array
 *   Admin form generated by system_settings_form().
 */
function mydigipass_admin_settings_submit_test_connectivity($form, &$form_state) {
  $url = 'https://www.mydigipass.com';
  $result = drupal_http_request($url);

  switch ($result->code) {
    case 200:
    case 301:
    case 302:
      drupal_set_message(t('Connectivity test succeeded: this web server can contact MYDIGIPASS.COM.'));
      break;

    default:
      watchdog('mydigipass', 'Connectivity test failed due to "%error".', array('%error' => $result->code . ' ' . $result->error), WATCHDOG_WARNING);
      drupal_set_message(t('Connectivity test failed due to "%error".', array('%error' => $result->code . ' ' . $result->error)));
  }
}

/**
 * Page callback for 'admin/settings/mydigipass/user_profile_fiels'.
 *
 * Shows the admin form which allows the administrator to specify which
 * attributes are being displayed on a user's profile page.
 *
 * @see mydigipass_admin_settings_user_profile_fields_form_validate()
 * @see mydigipass_admin_settings_user_profile_fields_form_submit()
 */
function mydigipass_admin_settings_user_profile_fields_form($form_state) {
  $form = array();

  // Extract all available fields which are currently selected.
  $sql = 'SELECT name, title, weight, selected FROM {mydigipass_profile_fields} ORDER BY weight ASC';
  $result = db_query($sql);
  $user_data_fields = array();
  while ($row = db_fetch_object($result)) {
    $user_data_fields[] = array(
      'name' => check_plain($row->name),
      'title' => check_plain($row->title),
      'weight' => $row->weight,
      'selected' => $row->selected,
    );
  }

  // Extract all available fields which are currently available in the
  // user_data column which are not yet used in the mydigipass_profile_fields
  // table.
  $sql = 'SELECT DISTINCT attribute_key FROM {mydigipass_user_data} WHERE attribute_key <> \'error\' AND attribute_key NOT IN (SELECT name FROM {mydigipass_profile_fields}) ORDER BY attribute_key ASC';
  $result = db_query($sql);
  while ($row = db_fetch_object($result)) {
    $user_data_fields[] = array(
      'name' => check_plain($row->attribute_key),
      'title' => "",
      'weight' => 0,
      'selected' => FALSE,
    );
  }

  // Construct the form.
  $form = array();
  $form['items'] = array();
  $form['items']['#tree'] = TRUE;

  foreach ($user_data_fields as $item) {
    $form['items'][$item['name']] = array(
      'selected' => array(
        '#type' => 'checkbox',
        '#title' => check_plain($item['name']),
        '#default_value' => isset($item['selected']) ? $item['selected'] : FALSE,
      ),
      'title' => array(
        '#type' => 'textfield',
        '#default_value' => $item['title'],
      ),
      'weight' => array(
        '#type' => 'weight',
        '#delta' => count($user_data_fields),
        '#default_value' => isset($item['weight']) ? $item['weight'] : 0,
      ),
      'name' => array(
        '#type' => 'hidden',
        '#value' => $item['name'],
      ),
    );
  }

  $form[] = array(
    '#type' => 'submit',
    '#value' => t('Save'),
  );

  return $form;
}

/**
 * Theme callback for the mydigipass_admin_settings_user_profile_fields_form.
 *
 * The theme callback will format the $form data structure into a table and
 * add our tabledrag functionality. (Note that drupal_add_tabledrag should be
 * called from the theme layer, and not from a form declaration. This helps
 * keep template files clean and readable, and prevents tabledrag.js from
 * being added twice accidently.
 *
 * @return string
 *   The rendered tabledrag form
 */
function theme_mydigipass_admin_settings_user_profile_fields_form($form) {
  drupal_add_tabledrag('draggable-table', 'order', 'sibling', 'weight-group');
  $header = array(t('Show field'), t('Show as'), t('Weight'));
  foreach (element_children($form['items']) as $key) {
    $element = &$form['items'][$key];
    $element['weight']['#attributes']['class'] = 'weight-group';
    $row = array();
    $row[] = drupal_render($element['selected']);
    $row[] = drupal_render($element['title']);
    $row[] = drupal_render($element['weight']) . drupal_render($element['name']);
    $rows[] = array('data' => $row, 'class' => 'draggable');
  }
  $output = theme('table', $header, $rows, array('id' => 'draggable-table'));
  $output .= drupal_render($form);
  return $output;
}

/**
 * Submit handler for mydigipass_admin_settings_user_profile_fields_form().
 *
 * @see mydigipass_admin_settings_user_profile_fields_form_validate()
 */
function mydigipass_admin_settings_user_profile_fields_form_submit($form, &$form_state) {
  // A boolean via which we will track whether the database queries were
  // succesfull.
  $success = TRUE;

  // Delete the previous stored setting.
  $sql = 'TRUNCATE TABLE {mydigipass_profile_fields}';
  db_query($sql);

  // Store the selected attribute names in the database.
  $sql = "INSERT INTO {mydigipass_profile_fields} (name, title, selected, weight) VALUES ('%s', '%s', %d, %d)";

  foreach ($form_state['values']['items'] as $item) {
    $success = db_query($sql, $item['name'], $item['title'], $item['selected'], $item['weight']) && $success;
  }
  if ($success) {
    drupal_set_message(t('The configuration options have been saved.'));
  }
  else {
    drupal_set_message(t('An error occured while saving the configuration options.'), 'error');
  }
}

/**
 * Page callback for 'admin/settings/mydigipass/button_style'.
 *
 * Shows the admin form which allows the administrator to select which
 * button has to be shown within the forms. The administrator can select the
 * layout of three buttons: the one shown on a typical login form, the one
 * shown on a register form and the one shown in the user profile which allows
 * to link to account with a MYDIGIPASS.COM account.
 *
 * @return array
 *   Admin form generated by system_settings_form().
 */
function mydigipass_admin_settings_button_style_form($form_state) {
  // These are the names of the 3 different forms. Their names should be run
  // through t().
  $forms = array(
    'login' => t('Login'),
    'register' => t('Register'),
    'link' => t('Link'),
  );
  // The following array contains fixed default values for radios field-items.
  // As such, this does not have to be run through t().
  $defaults = array(
    'login' => array(
      'style' => 'default',
      'text' => 'secure-login',
      'help' => 'true'),
    'register' => array(
      'style' => 'large',
      'text' => 'sign-up',
      'help' => 'true'),
    'link' => array(
      'style' => 'large',
      'text' => 'connect',
      'help' => 'true'),
  );
  $form_style_options = array(
    'default' => t('default'),
    'large' => t('large'),
    'medium' => t('medium'),
    'small' => t('small'),
    'false' => t('false'),
  );
  $form_text_options = array(
    'connect' => t('connect'),
    'sign-up' => t('sign-up'),
    'secure-login' => t('secure-login'),
  );
  $form_help_options = array(
    'true' => t('true'),
    'false' => t('false'),
  );
  foreach ($forms as $key => $value) {
    $form[$key . '_form'] = array(
      '#type' => 'fieldset',
      '#title' => t("@value form", array('@value' => $value)),
      '#collapsible' => TRUE,
      '#collapsed' => TRUE,
      '#description' => t('Use the options below to modify the style of the MYDIGIPASS.COM button which is shown in the @key form.', array('@key' => $key)),
    );
    $form[$key . '_form']['mydigipass_' . $key . '_form_style'] = array(
      '#type' => 'radios',
      '#title' => t('Style'),
      '#options' => $form_style_options,
      '#default_value' => variable_get('mydigipass_' . $key . '_form_style', $defaults[$key]['style']),
      '#required' => TRUE,
      '#description' => t("Sets the button style. Use false if you don't want to use the default MYDIGIPASS.COM Secure Login button styling."),
    );
    $form[$key . '_form']['mydigipass_' . $key . '_form_text'] = array(
      '#type' => 'radios',
      '#title' => t('Text'),
      '#options' => $form_text_options,
      '#default_value' => variable_get('mydigipass_' . $key . '_form_text', $defaults[$key]['text']),
      '#required' => TRUE,
      '#description' => t("Specifies the text to appear on the button. Note that this attribute is irrelevant if the style attribute is set to default or small."),
    );
    $form[$key . '_form']['mydigipass_' . $key . '_form_help'] = array(
      '#type' => 'radios',
      '#title' => t('Help'),
      '#options' => $form_help_options,
      '#default_value' => variable_get('mydigipass_' . $key . '_form_help', $defaults[$key]['help']),
      '#required' => TRUE,
      '#description' => t("If set to true, meta-text is used to display information about the button in question."),
    );
  }

  return system_settings_form($form);
}
