<?php

/**
 * @file
 * Contains page callbacks for og_menu
 */

/**
 * Menu callback which shows an overview page of all the custom menus in user's groups and their descriptions.
 */
function og_menu_overview_page($node) {
  $gid = og_get_group_ids('node', array($node->nid), FALSE, array(OG_STATE_ACTIVE, OG_STATE_PENDING));
  $gid = $gid[$node->nid];


  // Set the title of the page.
  drupal_set_title(t('List menus for @title', array('@title' => $node->title)));


  $result = db_query("
    SELECT om.gid, om.menu_name as name, m.title as title, m.description as description FROM {og_menu} om
    LEFT JOIN {menu_custom} m
    ON om.menu_name = m.menu_name
    WHERE om.gid = :gid
    ORDER BY title", array(':gid' => $gid));
  $header = array(t('Title'), array('data' => t('Operations'), 'colspan' => '3'));
  $rows = array();
  foreach ($result as $menu) {
    $row = array(theme('menu_admin_overview', array('title' => $menu->title, 'name' => $menu->name, 'description' => $menu->description)));
    $row[] = array('data' => l(t('list links'), 'node/' . $node->nid . '/og_menu/' . $menu->name));
    $row[] = array('data' => l(t('edit menu'), 'node/' . $node->nid . '/og_menu/' . $menu->name . '/edit'));
    $row[] = array('data' => l(t('add link'), 'node/' . $node->nid . '/og_menu/' . $menu->name . '/add'));
    $rows[] = $row;
  }

  if ($result->rowCount() == 0) {
    return t('There are currently no menus. Click') . ' ' . l(t('Add menu'), 'node/' . $node->nid . '/og_menu/add') . t(' to create one.');
  }
  else {
    return theme('table', array('header' => $header, 'rows' => $rows));
  }
}

/**
 * Menu callback; Build the form that handles the adding/editing of a custom menu.
 */
function og_menu_edit_menu_form($form, &$form_state, $type, $menu = array()) {
  module_load_include('inc', 'menu', 'menu.admin');
  $node = menu_get_object('node', 1);
  $og = og_get_group('node', $node->nid, FALSE, array(OG_STATE_ACTIVE, OG_STATE_PENDING));

  // Set the title of the page.
  switch ($type) {
    case 'add': drupal_set_title(t('Add menu for @title', array('@title' => $node->title)));
      break;

    case 'edit': drupal_set_title(t('Edit menu for @title', array('@title' => $node->title)));
      break;
  }

  // Build the form.
  $form = drupal_retrieve_form('menu_edit_menu', $form_state, $type, $menu);
  $form['og_menu_gid'] = array(
    '#type' => 'value',
    '#value' => $og->gid,
  );
  // Add submit handlers.
  $form['#submit'][] = 'menu_edit_menu_submit';
  $form['#submit'][] = 'og_menu_edit_menu_form_submit';
  $form['#submit'][] = 'og_menu_edit_menu_form_submit_redirect';
  $form['actions']['delete']['#submit'][] = 'og_menu_delete_menu_form_submit';

  return $form;
}

/**
 * Submit handler for og_menu_edit_menu_form
 */
function og_menu_delete_menu_form_submit($form, &$form_state) {
  $node = menu_get_object('node', 1);
  $menu = menu_get_object('menu', 3);
  $form_state['redirect'] = 'node/' . $node->nid . '/og_menu/' . $menu['menu_name'] . '/delete';
}

/**
 * Submit handler which handles redirection for og_menu_edit_menu_form form.
 */
function og_menu_edit_menu_form_submit_redirect($form, &$form_state) {
  $node = menu_get_object('node', 1);
  if ($form['#insert']) {
    $menu = array();
    $menu['menu_name'] = 'menu-' . $form_state['values']['menu_name'];
  }
  else {
    $menu = menu_get_object('menu', 3);
  }
  $form_state['redirect'] = 'node/' . $node->nid . '/og_menu/' . $menu['menu_name'];
}

/**
 * Form callback which shows an entire menu tree at once.
 */
function og_menu_overview_form($form, &$form_state, $menu, $node) {
  module_load_include('inc', 'menu', 'menu.admin');
  drupal_set_title(t('List items for menu @mtitle', array('@mtitle' => $menu['title'])));
  $b = drupal_get_breadcrumb();
  $b[] = l($node->title, 'node/' . $node->nid);
  $b[] = l(t('Menus'), 'node/' . $node->nid . '/og_menu');
  drupal_set_breadcrumb($b);
  $form = drupal_retrieve_form('menu_overview_form', $form_state, $menu);

  $form['#theme'] = 'menu_overview_form';
  $form['#empty_text'] = t('There are no menu links yet.') . ' ' . l(t('Add link'), 'node/' . $node->nid . '/og_menu/' . $menu['menu_name'] . '/add');

  foreach (element_children($form) as $mlid) {
    if (strstr($mlid, 'mlid:')) {
      $item = $form[$mlid]['#item'];
      $operations = array();
      $operations['edit'] = array(
        '#type' => 'link',
        '#title' => 'edit',
        '#href' => 'node/' . $node->nid . '/og_menu/' . $menu['menu_name'] . '/item/' . $item['mlid'] . '/edit',
      );

      if ($item['module'] == 'menu' || $item['updated'] == 1) {
        $operations['delete'] = array(
          '#type' => 'link',
          '#title' => 'delete',
          '#href' => 'node/' . $node->nid . '/og_menu/' . $menu['menu_name'] . '/item/' . $item['mlid'] . '/delete',
        );
      }
      $form[$mlid]['operations'] = $operations;

      // $form['delete']['#submit'][] = 'og_menu_item_delete_submit'; // Missing submit handler?
    }
  }
  //$form['submit']['#submit'] = array('menu_overview_form_submit');
  return $form;
}

function og_menu_overview_form_submit($form, &$form_state) {
  menu_overview_form_submit($form, $form_state);
}

/**
 * Form callback for node/%node/og_menu/%menu/delete.
 */
function og_menu_delete_menu_confirm($form, &$form_state, $menu) {
  module_load_include('inc', 'menu', 'menu.admin');
  $form = drupal_retrieve_form('menu_delete_menu_confirm', $form_state);
  $form['#submit'][] = 'menu_delete_menu_confirm_submit';
  $form['#submit'][] = 'og_menu_delete_menu_confirm_submit';
  $form['#submit'][] = 'og_menu_delete_menu_confirm_submit_redirect';
  return $form;
}

/**
 * Form callback; Build the menu link editing form.
 */
function og_menu_edit_item_form($form, &$form_state, $type, $item, $menu, $node) {
  $b = drupal_get_breadcrumb();
  $b[] = l($menu['title'], 'node/' . $node->nid . '/og_menu/' . $menu['menu_name']);
  drupal_set_breadcrumb($b);
  module_load_include('inc', 'menu', 'menu.admin');
  $form = drupal_retrieve_form('menu_edit_item', $form_state, $form, $type, $item, $menu);
  $list = array();
  $menus = og_menu_get_menus(array(og_get_group('node', $node->nid)->gid));
  foreach ($menus as $menu) {
    $list[$menu->mname] = $menu->mtitle;
  }

  // Set the title of the page.
  drupal_set_title(t('Add item into menu @mtitle', array('@mtitle' => $menu->mtitle)));


  // Build the form.
  $form['parent']['#options'] = menu_parent_options($list, array('mlid' => 0));
  $form['#submit'][] = 'menu_edit_item_submit';
  $form['#submit'][] = 'og_menu_redirect';
  $form['#validate'][] = 'menu_edit_item_validate';
  $form['delete']['#submit'][] = 'og_menu_delete_item_form';
  return $form;
}

/**
 * Form callback for node/%node/og_menu/%menu/item/%menu_link/delete page
 */
function og_menu_delete_item_form($form, &$form_state, $item) {
  module_load_include('inc', 'menu', 'menu.admin');
  $form = drupal_retrieve_form('menu_item_delete_form', $form_state, $item);
  $node = menu_get_object('node', 1);
  $menu = menu_get_object('menu', 3);
  $form['#submit'][] = 'menu_item_delete_form_submit';
  $form['#submit'][] = 'og_menu_redirect';
  $form['actions']['cancel']['#value'] = l(t('Cancel'), 'node/' . $node->nid . '/og_menu/' . $menu['menu_name']);
  return $form;
}

/**
 * Form callback for OG Menu configuration.
 */
function og_menu_config_form($form, &$form_state) {
  $form['og_menu_block_links'] = array(
    '#type' => 'checkbox',
    '#title' => t('Convert OG Menu block titles into links'),
    '#default_value' => variable_get('og_menu_block_links', FALSE),
    '#description' => t('If enabled, OG Menu block titles will link to the group node.'),
  );
  $form['og_menu_show_blocks'] = array(
    '#type' => 'checkbox',
    '#title' => t("Show blocks for individual OG Menu's"),
    '#default_value' => variable_get('og_menu_show_blocks', FALSE),
    '#description' => t("If disabled, blocks for OG Menu's will be hidden from the block administration page."),
  );
  $form['og_menu_max_menus_per_group'] = array(
    '#type' => 'textfield',
    '#title' => t('Maximum number of menus per group'),
    '#default_value' => variable_get('og_menu_max_menus_per_group', 1),
    '#size' => 20,
    '#maxlength' => 5,
    '#required' => TRUE,
    '#description' => t('Enter 0 for no limit. Users with the \'administer menu\' permission will be able to bypass this.'),
  );
  $form['og_menu_assignment'] = array(
    '#type' => 'radios',
    '#options' => array('select' => t('Select list'), 'autocomplete' => t('Textbox with autocomplete')),
    '#title' => t('Use autocomplete field on menu admin page'),
    '#default_value' => variable_get('og_menu_assignment', 'autocomplete'),
    '#required' => 'TRUE',
    '#description' => t('Autocomplete is recommended when you have a lot of organic groups.'),
  );
  return system_settings_form($form);
}

/**
 * Validation for OG Menu config form.
 */
function og_menu_config_form_validate($form, &$form_state) {
  $max_num = $form_state['values']['og_menu_max_menus_per_group'];
  if (!is_numeric($max_num)) {
    form_set_error('og_menu_max_menus_per_group', t('You must enter an integer for the maximum number of menus per group.'));
  }
  elseif ($max_num < 0) {
    form_set_error('og_menu_max_menus_per_group', t('Maximum number of menus per group must be positive.'));
  }
}

/**
 * Callback for admin/structure/og_menu
 * Just duplicates the standard menu list, but only displays those created through og_menu
 */
function og_admin_menu_overview_page() {
  $result = db_query("SELECT * FROM {menu_custom} mc, {og_menu} om WHERE mc.menu_name = om.menu_name ORDER BY title", array(), array('fetch' => PDO::FETCH_ASSOC));
  $header = array(t('Title'), array(
      'data' => t('Operations'),
      'colspan' => '3',
          ));
  $rows = array();
  foreach ($result as $menu) {
    $row = array(theme('menu_admin_overview', array('title' => $menu['title'], 'name' => $menu['menu_name'], 'description' => $menu['description'])));
    $row[] = array('data' => l(t('list links'), 'admin/structure/menu/manage/' . $menu['menu_name']));
    $row[] = array('data' => l(t('edit menu'), 'admin/structure/menu/manage/' . $menu['menu_name'] . '/edit'));
    $row[] = array('data' => l(t('add link'), 'admin/structure/menu/manage/' . $menu['menu_name'] . '/add'));
    $rows[] = $row;
  }

  return theme('table', array('header' => $header, 'rows' => $rows));
}
