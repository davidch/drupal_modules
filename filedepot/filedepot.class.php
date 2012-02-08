<?php

/**
 * @file
 * filedepot.class.php
 * Main class for the Filedepot module
 */

class filedepot {

  protected static $_instance;
  public $root_storage_path = '';
  public $tmp_storage_path = '';
  public $tmp_incoming_path = '';

  public $validReportingModes = array(
    'latestfiles',
    'notifications',
    'lockedfiles',
    'downloads',
    'flaggedfiles',
    'unread',
    'myfiles',
    'approvals',
    'incoming',
    'searchtags',
    'search',
  );

  public $iconmap = array(
    'default' => 'none.gif',
    'favorite-on' => 'staron-16x16.gif',
    'favorite-off' => 'staroff-16x16.gif',
    'locked' => 'padlock.gif',
    'download' => 'download.png',
    'editfile' => 'editfile.png',
    'upload' => 'upload.png',
  );

  public $defOwnerRights = array();
  public $defRoleRights = array();

  public $maxDefaultRecords = 30;
  public $listingpadding = 20; // Number of px to indent filelisting per folder level
  public $filedescriptionOffset = 50;
  public $shortdate = '%x';
  public $activeview = ''; // Active filtered view
  public $cid = 0; // Active folder
  public $selectedTopLevelFolder = 0;
  public $recordCountPass1 = 2;
  public $recordCountPass2 = 10;
  public $folder_filenumoffset = 0;
  public $lastRenderedFiles = array();
  public $lastRenderedFolder = 0;
  public $allowableViewFolders = '';
  public $allowableViewFoldersSql = '';
  public $ajaxBackgroundMode = FALSE;
  private $upload_prefix_character_count = 18;
  private $download_chunk_rate =   8192; //set to 8k download chunks
  public $ogenabled = FALSE;
  public $ogmode_enabled = FALSE;        // SET true if OG installed and site admin has enabled OG Mode
  public $ogrootfolder = 0;
  public $allowableGroupViewFoldersSql = '';
  public $paddingsize = 5; // Number of pixels to indent each folder level
  public $message = '';   // Temporary storage of message

  static $ogmode_initialized = FALSE;   // SET true once we have intialized GroupViewFolders

  public $notificationTypes = array(
    1 => 'New File Added',
    2 => 'New File Approved',
    3 => 'New File Declined',
    4 => 'File Changed',
    5 => 'Broadcast',
  );



  protected function __construct() { # Singleton Pattern: we don't permit an explicit call of the constructor!
    global $user;

    $this->tmp_storage_path  =  drupal_realpath('public://') . '/filedepot/';
    $this->tmp_incoming_path  = drupal_realpath('public://') . '/filedepot/incoming/';
    $this->root_storage_path = 'private://filedepot/';

    /* @TODO: Need to add logic that will only be executed once to test
     * that the private filesystem has been setup and the filedepot folders
     * for the repository have been created - we can get the $private path.
     */
    $private = variable_get('file_private_path', '');

    $this->recordCountPass1 = variable_get('filedepot_pass1_recordcount', 2);
    $this->recordCountPass2 = variable_get('filedepot_pass2_recordcount', 10);

    $iconsettings = unserialize(variable_get('filedepot_extension_data', ''));
    if (!empty($iconsettings)) {
      $this->iconmap = array_merge($this->iconmap, $iconsettings);
    }
    $defOwnerRights = variable_get('filedepot_extension_data', '');
    if (!empty($defOwnerRights)) {
      $this->defOwnerRights = unserialize($defOwnerRights);
    }
    else {
      $defOwnerRights = array('view');
    }
    $permsdata = variable_get('filedepot_default_perms_data', '');
    if (!empty($permsdata)) {
      $permsdata = unserialize($permsdata);
    }
    else {
      $permsdata = array('authenticated user' => array('view', 'upload'));
    }
    if (isset($permsdata['owner']) AND count($permsdata['owner'] > 0)) {
      $this->defOwnerRights = $permsdata['owner'];
    }
    else {
      $this->defOwnerRights = array('view', 'admin');
    }
    if (isset($permsdata['owner'])) {
      unset($permsdata['owner']); // It has now been assigned to defOwnerRights variable
    }

    $this->defRoleRights = $permsdata;

    if (user_is_logged_in()) {

      // This cached setting will really only benefit when there are many thousand access records like portal23
      // User setting (all users) is cleared each time a folder permission is updated.
      // But this library is also included for all AJAX requests

      $data = db_query("SELECT allowable_view_folders FROM {filedepot_usersettings} WHERE uid=:uid", array('uid' => $user->uid))->fetchField();
      if (empty($data)) {
        $this->allowableViewFolders = $this->getAllowableCategories('view', FALSE);
        $data = serialize($this->allowableViewFolders);
        if (db_query("SELECT count(uid) FROM {filedepot_usersettings} WHERE uid=:uid", array('uid' => $user->uid))->fetchField() == 0) {
          /* Has a problem handling serialized data - we couldn't unserialize the data afterwards.
           * The problem is the pre-constructed SQL statement. When we use the function "udate_sql($sql)",
           * we construct the SQL statement without using any argument. A serialized data normally contains curly brackets.
           * When you call update_sql($sql), it then hands your pre-constructed $sql to the function db_query($sql).
           * Inside the function db_query(), it replace the curly bracket with table prefix blindly,
           * even the curly bracket inside data string are converted.
           * And thus you will not be able to unserialize the data from the table anymore.
           * To get around this, instead of calling update_sql, call db_query($sql, $args).
           * Put all the variables to be inserted into the table into the argument list.
           * This way db_query will only convert the curly bracket surrounding the table name.
           */
          db_query("INSERT INTO {filedepot_usersettings} (uid, allowable_view_folders, notify_newfile, notify_changedfile, allow_broadcasts) VALUES (:uid, :view, :newfile, :changed, :broadcasts)", array(
            'uid' => $user->uid,
            'view' => $data,
            ':newfile' => variable_get('filedepot_default_notify_newfile', 0),
            ':changed' => variable_get('filedepot_default_notify_filechange', 0),
            ':broadcasts' => variable_get('filedepot_default_allow_broadcasts', 0),
          ));
        }
        else {
          db_query("UPDATE {filedepot_usersettings} set allowable_view_folders=:view WHERE uid=:uid", array(
            'view' => $data,
            'uid' => $user->uid,
          ));
        }
      }

      $this->allowableViewFolders = '';
      if (module_exists('og') AND module_exists('og_access')) {
        $this->ogenabled = TRUE;
        if (variable_get('filedepot_organic_group_mode_enabled', 0) == 1) {
          $this->ogmode_enabled = TRUE;
        }
        if (self::$ogmode_initialized === FALSE) {
          self::$ogmode_initialized = TRUE;   // Only want to do this once.
          // Using the ctools cache functionality to save which folder the user has selected
          ctools_include('object-cache');
          $gid = ctools_object_cache_get('filedepot', 'grpid');
          if ($gid > 0) {
            $this->ogrootfolder = db_query("SELECT cid FROM {filedepot_categories} WHERE group_nid=:gid AND pid=0", array(':gid' => $gid))->fetchfield();
            if ($this->ogrootfolder !== FALSE and $this->ogrootfolder > 0) {
              $this->allowableViewFolders = array();
              array_push($this->allowableViewFolders, $this->ogrootfolder);
              $folderlist = $this->getRecursiveCatIDs ($this->allowableViewFolders, $this->ogrootfolder, 'view');
                $this->allowableGroupViewFoldersSql = implode(',', $folderlist);  // Format to use for SQL statement - test for allowable categories
            }
          }
        }
      }

      if (empty($this->allowableViewFolders)) {
        $this->allowableViewFolders = unserialize($data);
      }
      $this->allowableViewFoldersSql = implode(',', $this->allowableViewFolders); // Format to use for SQL statement - test for allowable categories
    }
    else {
      $this->allowableViewFolders = $this->getAllowableCategories('view', FALSE);
      $this->allowableViewFoldersSql = implode(',', $this->allowableViewFolders); // Format to use for SQL statement - test for allowable categories
    }

  }

  protected function __clone() { # we don't permit cloning the singleton
  }

  public static function getInstance() {
    if ( self::$_instance === NULL) {
      self::$_instance = new self();
    }
    return self::$_instance;
  }

  /* Function to check if passed in file extension and mimetype are in the allowed list
   * @param        string          $ext            File extension to test
   * @param        string          $mimetype       Mimetype to test if allowed for extension
   * @return       Boolean                         Returns TRUE or FALSE and depends on the filter mode setting
   */
  function checkFilter($filename, $mimetype) {
    $ext = end(explode(".", $filename));
    $filterdata = unserialize(variable_get('filedepot_filetype_filterdata', ''));
    if (is_array($filterdata) AND !empty($filterdata)) {
      if (array_key_exists($mimetype, $filterdata) AND is_array($filterdata[$mimetype])) {
        if (in_array($ext, $filterdata[$mimetype])) {
          // Match found - Mimetype and extension match defined settings
          if (variable_get('filedepot_filter_mode', FILEDEPOT_FILTER_INCLUDEMODE) == FILEDEPOT_FILTER_INCLUDEMODE) {
            return TRUE;
          }
          else {
            RETURN FALSE;
          }
        }
      }
    }
    // If we get here, no match found. Return depends on the filtering mode
    if (variable_get('filedepot_filter_mode', FILEDEPOT_FILTER_EXCLUDEMODE) == FILEDEPOT_FILTER_EXCLUDEMODE) {
      return TRUE;
    }
    else {
      return FALSE;
    }

  }

  /* Function to check if user has a particular right or permission to a folder
   *  Checks the access table for the user and any groups they belong to
   *  Takes either a single Right or an array of Rights
   *
   * @param        string          $cid            Category to check user access for
   * @param        string|array    $rights         Rights to check (admin,view,upload,upload_dir,upload_ver,approval)
   * @param        Boolean         $adminOverRide  Set to FALSE, to ignore if user is in the Admin group and check for absolute perms
   * @return       Boolean                         Returns TRUE if user has one of the requested access rights else FALSE
   */
  function checkPermission($cid, $rights, $userid = 0, $adminOverRide = TRUE) {
    global $user;

    if (intval($cid) < 1) {
      return FALSE;
    }

    // If user is an admin - they should have access to all rights on all categories
    if ($userid == 0) {
      if (empty($user->uid) OR $user->uid == 0 ) {
        $uid = 0;
      }
      else {
        $uid = $user->uid;
      }
    }
    else {
      $uid = $userid;
    }
    if ($adminOverRide AND user_access('administer filedepot', $user)) {
      return TRUE;
    }
    else {
      // Check user access records
      $sql = "SELECT view,upload,upload_direct,upload_ver,approval,admin from {filedepot_access} WHERE catid=:cid AND permtype='user' AND permid=:uid";
      $query = db_query($sql, array('cid' => $cid, 'uid' => $uid));
      while ($rec = $query->fetchAssoc()) {
        list($view, $upload, $upload_dir, $upload_ver, $approval, $admin) = array_values($rec);
        if (is_array($rights)) {
          foreach ($rights as $key) { // Field name above needs to match access right name
            if ($$key == 1) {
              return TRUE;
            }
          }
        }
        elseif ($$rights == 1) {
          return TRUE;
        }
      }

      if ($this->ogenabled) {
        // Retrieve all the Organic Groups this user is a member of
        $groupids = og_get_entity_groups('user', $user);
        foreach ($groupids as $gid) {
          $sql = "SELECT view,upload,upload_direct,upload_ver,approval,admin from {filedepot_access} WHERE catid=:cid AND permtype='group' AND permid=:gid";
          $query = db_query($sql, array(':cid' => $cid, ':gid' => $gid));
          while ($rec = $query->fetchAssoc()) {
            list($view, $upload, $upload_dir, $upload_ver, $approval, $admin) = array_values($rec);
            if (is_array($rights)) {
              foreach ($rights as $key) { // Field name above needs to match access right name
                if ($$key == 1) {
                  return TRUE;
                }
              }
            }
            elseif ($$rights == 1) {
              return TRUE;
            }
          }
        }
      }

      // For each role that the user is a member of - check if they have the right
      foreach ($user->roles as $rid => $role) {
        $sql = "SELECT view,upload,upload_direct,upload_ver,approval,admin from {filedepot_access} WHERE catid=:cid AND permtype='role' AND permid=:uid";
        $query = db_query($sql, array('cid' => $cid, 'uid' => $rid));
        while ($rec = $query->fetchAssoc()) {
          list($view, $upload, $upload_dir, $upload_ver, $approval, $admin) = array_values($rec);
          if (is_array($rights)) {
            // If any of the required permissions set - return TRUE
            foreach ($rights as $key) {
              if ($$key == 1) { // Field name above needs to match access right name
                return TRUE;
              }
            }
          }
          elseif ($$rights == 1) {
            return TRUE;
          }
        }
      }
    }
    return FALSE;
  }

  /**
   * Return list of repository categories user has permission to access to be used in SQL statements
   *
   * @param   mixed   array or string    - permission(s) you want to test for
   * @param   boolean return format      - if FALSE, return an array
   * @return  mixed - comma separated list of categories, or array
   */
  function getAllowableCategories($perm = 'view', $returnstring = TRUE) {
    global $user;

    $categories = array();
    $sql = "SELECT distinct catid FROM {filedepot_access} ";
    $query = db_query($sql);
    while ($A = $query->fetchAssoc()) {
      if ($this->checkPermission($A['catid'], $perm, $user->uid)) {
        $categories[] = $A['catid'];
      }
    }
    if ($returnstring AND count($categories) > 0) {
      $retval = implode(',', $categories);
    }
    else {
      $retval = $categories;
    }

    return $retval;
  }


  /* Function to return and array of subcategories - used to generate a list of folders
   * under the OG Root Group assigned folder.
   *
   *  Recursive function calls itself building the list
   *
   * @param        array      $list        Array of categories
   * @param        string     $cid         Category to lookup
   * @param        string     $perms       Permissions to check if user has access to catgegory
   * @param        boolean    $override    Set to TRUE only if you don't want to test for permissions
   */
  public function getRecursiveCatIDs(&$list, $cid, $perms, $override = false) {
    $filedepot = filedepot_filedepot();
    $query = db_query("SELECT cid FROM {filedepot_categories} WHERE PID=:cid ORDER BY cid", array('cid' => $cid));
    while ( $A = $query->fetchAssoc()) {
      // Check and see if this category has any sub categories - where a category record has this cid as it's parent
      if (db_query("SELECT count(pid) FROM {filedepot_categories} WHERE pid=:cid", array('cid' => $A['cid']))->fetchField() > 0) {
        if ($override === TRUE OR $this->checkPermission($A['cid'], $perms)) {
          array_push($list, $A['cid']);
          $this->getRecursiveCatIDs($list, $A['cid'], $perms, $override);
        }
      }
      else {
        if ($override === TRUE OR $this->checkPermission($A['cid'], $perms)) {
          array_push($list, $A['cid']);
        }
      }
    }
    return $list;
  }


  public function updatePerms($id, $accessrights, $users = '', $groups = '', $roles = '') {

    if ($users != ''    AND !is_array($users)) {
      $users  = array($users);
    }
    if ($groups != '' AND !is_array($groups)) {
      $groups = array($groups);
    }
    if ($roles != ''    AND !is_array($roles)) {
      $roles  = array($roles);
    }

    if (!empty($accessrights)) {
      if (in_array('view', $accessrights)) {
        $view = 1;
      }
      else {
        $view = 0;
      }
      if (in_array('upload', $accessrights)) {
        $upload = 1;
      }
      else {
        $upload = 0;
      }
      if (in_array('approval', $accessrights)) {
        $approval = 1;
      }
      else {
        $approval = 0;
      }
      if (in_array('upload_dir', $accessrights)) {
        $direct = 1;
      }
      else {
        $direct = 0;
      }
      if (in_array('admin', $accessrights)) {
        $admin = 1;
      }
      else {
        $admin = 0;
      }
      if (in_array('upload_ver', $accessrights)) {
        $versions = 1;
      }
      else {
        $versions = 0;
      }

      if (!empty($users)) {
        foreach ($users as $uid ) {
          $uid = intval($uid);
          $query = db_query("SELECT accid FROM {filedepot_access} WHERE catid=:cid AND permtype='user' AND permid=:uid", array(
            ':cid' => $id,
            ':uid' => $uid,
          ));
          if ($query->fetchField() === FALSE) {
            $query = db_insert('filedepot_access');
            $query->fields(array('catid', 'permid', 'permtype', 'view', 'upload', 'upload_direct', 'upload_ver', 'approval', 'admin'));
            $query->values(array(
              'catid' => $id,
              'permid' => $uid,
              'permtype' => 'user',
              'view' => $view,
              'upload' => $upload,
              'upload_direct' => $direct,
              'upload_ver' => $versions,
              'approval' => $approval,
              'admin' => $admin,
            ));
            $query->execute();
          }
          else {
            db_update('filedepot_access')
              ->fields(array('view' => $view, 'upload' => $upload, 'upload_direct' => $direct, 'upload_ver' => $versions, 'approval' => $approval, 'admin' => $admin))
              ->condition('catid', $id)
              ->condition('permtype', 'user')
              ->condition('permid', $uid)
              ->execute();
          }
        }
      }

      if (!empty($groups)) {
        foreach ($groups as $gid ) {
          $gid = intval($gid);
          $query = db_query("SELECT accid FROM {filedepot_access} WHERE catid=:cid AND permtype='group' AND permid=:gid", array(
            ':cid' => $id,
            ':gid' => $gid,
          ));
          if ($query->fetchField() === FALSE) {
            $query = db_insert('filedepot_access');
            $query->fields(array('catid', 'permid', 'permtype', 'view', 'upload', 'upload_direct', 'upload_ver', 'approval', 'admin'));
            $query->values(array(
              'catid' => $id,
              'permid' => $gid,
              'permtype' => 'group',
              'view' => $view,
              'upload' => $upload,
              'upload_direct' => $direct,
              'upload_ver' => $versions,
              'approval' => $approval,
              'admin' => $admin,
            ));
            $query->execute();
          }
          else {
            db_update('filedepot_access')
              ->fields(array('view' => $view, 'upload' => $upload, 'upload_direct' => $direct, 'upload_ver' => $versions, 'approval' => $approval, 'admin' => $admin))
              ->condition('catid', $id)
              ->condition('permtype', 'group')
              ->condition('permid', $gid)
              ->execute();
          }
        }
      }

      if (!empty($roles)) {
        foreach ($roles as $rid ) {
          $rid = intval($rid);
          $query = db_query("SELECT accid FROM {filedepot_access} WHERE catid=:cid AND permtype='role' AND permid=:uid", array(
            'cid' => $id,
            'uid' => $rid,
          ));
          if ($query->fetchField() === FALSE) {
            $query = db_insert('filedepot_access');
            $query->fields(array('catid', 'permid', 'permtype', 'view', 'upload', 'upload_direct', 'upload_ver', 'approval', 'admin'));
            $query->values(array(
              'catid' => $id,
              'permid' => $rid,
              'permtype' => 'role',
              'view' => $view,
              'upload' => $upload,
              'upload_direct' => $direct,
              'upload_ver' => $versions,
              'approval' => $approval,
              'admin' => $admin,
            ));
            $query->execute();
          }
          else {
            db_update('filedepot_access')
              ->fields(array('view' => $view, 'upload' => $upload, 'upload_direct' => $direct, 'upload_ver' => $versions, 'approval' => $approval, 'admin' => $admin))
              ->condition('catid', $id)
              ->condition('permtype', 'role')
              ->condition('permid', $rid)
              ->execute();
          }
        }
      }

      /* May need to review this - and clear only those users that have been updated later.
       But determining the users in updated groups and sorting out duplicates from the individual user perms
       and only updating them may take more processing then simply clearing all.
       The users setting will be updated the next time they use the application - public/filedepot/library.php
       Distributing the load to update the cached setting.
       This cached setting will really only benefit when there are many thousand access records like portal23
       */
      db_update('filedepot_usersettings')
      ->fields(array('allowable_view_folders' => ''))
      ->execute();

      return TRUE;

    }
    else {
      return FALSE;
    }

  }

  public function createFolder($node) {
    global $user;

    if ($node->parentfolder == 0 AND !user_access('administer filedepot')) {
      return FALSE;
    }

    if ($node->parentfolder > 0 AND $this->checkPermission($node->parentfolder, 'admin') === FALSE) {
      return FALSE;
    }

    if (@is_dir($this->tmp_storage_path) === FALSE) {
      @mkdir($this->tmp_storage_path, FILEDEPOT_CHMOD_DIRS);
    }

    if (@is_dir($this->tmp_incoming_path) === FALSE) {
      @mkdir($this->tmp_incoming_path, FILEDEPOT_CHMOD_DIRS);
    }

    db_query("UPDATE {node} set promote = 0 WHERE nid = :nid", array(
      'nid' => $node->nid,
    ));

    $query = db_query("SELECT max(folderorder) FROM {filedepot_categories} WHERE pid=:pid", array('pid' => $node->parentfolder));
    $maxorder = $query->fetchField() + 10;

    // Only used for top level OG folders
    if (!isset($node->gid) OR empty($node->gid)) {
      $node->gid = 0;
    }

    db_query("INSERT INTO {filedepot_categories} (pid, name, description, folderorder, nid, vid, group_nid) VALUES (:pfolder, :title, :folder, :maxorder, :nid, :vid, :gid)",
    array(
      'pfolder' => $node->parentfolder,
      'title' => check_plain($node->title),
      'folder' => check_plain($node->filedepot_folder_desc[LANGUAGE_NONE][0]['value']),
      'maxorder' => $maxorder,
      'nid' => $node->nid,
      'vid' => $node->vid,
      'gid' => $node->gid
    ));

    // Need to clear the cached user folder permissions
    db_query("UPDATE {filedepot_usersettings} set allowable_view_folders = ''");

    // Retrieve the folder id (category id) for the new folder
    $cid = db_query("SELECT cid FROM {filedepot_categories} WHERE nid=:nid",
    array('nid' => $node->nid))->fetchField();
    if ($cid > 0 AND $this->createStorageFolder($cid)) {
      $this->cid = $cid;
      $catpid = db_query("SELECT pid FROM {filedepot_categories} WHERE cid=:cid",
      array('cid' => $cid))->fetchField();
      if (isset($node->inherit) AND $node->inherit == 1 AND $catpid > 0) {
        // Retrieve parent User access records - for each record create a new one for this category
        $sql = "SELECT permid,view,upload,upload_direct,upload_ver,approval,admin FROM {filedepot_access} "
        . "WHERE permtype = 'user' AND permid > 0 AND catid = :cid";
        $q1 = db_query($sql, array('cid' => $catpid));
        foreach ($q1 as $rec) {
          $query = db_insert('filedepot_access');
          $query->fields(array('catid', 'permtype', 'permid', 'view', 'upload', 'upload_direct', 'upload_ver', 'approval', 'admin'));
          $query->values(array(
            'catid' => $cid,
            'permtype' => 'user',
            'permid' => $rec->permid,
            'view' => $rec->view,
            'upload' => $rec->upload,
            'upload_direct' => $rec->upload_direct,
            'upload_ver' => $rec->upload_ver,
            'approval' => $rec->approval,
            'admin' => $rec->admin,
          )
          );
          $query->execute();
        }
        // Retrieve parent Role Access records - for each record create a new one for this category
        $sql = "SELECT permid,view,upload,upload_direct,upload_ver,approval,admin "
        . "FROM {filedepot_access} WHERE permtype='role' AND permid > 0 AND catid=:cid";
        $q2 = db_query($sql, array('cid' => $catpid));
        foreach ($q2 as $rec) {
          $query = db_insert('filedepot_access');
          $query->fields(array('catid', 'permtype', 'permid', 'view', 'upload', 'upload_direct', 'upload_ver', 'approval', 'admin'));
          $query->values(array(
            'catid' => $cid,
            'permtype' => 'role',
            'permid' => $rec->permid,
            'view' => $rec->view,
            'upload' => $rec->upload,
            'upload_direct' => $rec->upload_direct,
            'upload_ver' => $rec->upload_ver,
            'approval' => $rec->approval,
            'admin' => $rec->admin,
          )
          );
          $query->execute();
        }

        // Retrieve parent Group Access records - for each record create a new one for this category
        $sql = "SELECT permid,view,upload,upload_direct,upload_ver,approval,admin "
        . "FROM {filedepot_access} WHERE permtype='group' AND permid > 0 AND catid=:cid";
        $q3 = db_query($sql, array('cid' => $catpid));
        foreach ($q3 as $rec) {
          $query = db_insert('filedepot_access');
          $query->fields(array('catid', 'permtype', 'permid', 'view', 'upload', 'upload_direct', 'upload_ver', 'approval', 'admin'));
          $query->values(array(
            'catid' => $cid,
            'permtype' => 'group',
            'permid' => $rec->permid,
            'view' => $rec->view,
            'upload' => $rec->upload,
            'upload_direct' => $rec->upload_direct,
            'upload_ver' => $rec->upload_ver,
            'approval' => $rec->approval,
            'admin' => $rec->admin,
          )
          );
          $query->execute();
        }
      }
      else {
        // Create default permissions record for the user that created the category
        $this->updatePerms($cid, $this->defOwnerRights, $user->uid);
        if (is_array($this->defRoleRights) AND count($this->defRoleRights) > 0) {
          foreach ($this->defRoleRights as $role => $perms) {
            $rid = db_query("SELECT rid FROM {role} WHERE name=:role",
            array('role' => $role))->fetchField();
            if ($rid and $rid > 0) {
              $this->updatePerms($cid, $perms, '', '', array($rid));
            }
          }
        }
      }
      return TRUE;
    }
    else {
      return FALSE;
    }
  }


  public function createStorageFolder($cid) {
    if (@is_dir($this->root_storage_path) === FALSE) {
      watchdog('filedepot', "Storage Directory does not exist ({$this->root_storage_path}), attempting to create now");
      $res = @mkdir($this->root_storage_path, FILEDEPOT_CHMOD_DIRS);
      if ($res === FALSE) {
        watchdog('fildepot', "Failed - check the folder path is correct and valid");
      }
      else {
        watchdog('filedepot', "Success, Root Storage director created");
      }
    }
    $path = $this->root_storage_path . $cid;
    if (@is_dir($path)) {
      @chmod($path, FILEDEPOT_CHMOD_DIRS);
      if ($fh = fopen($path . '/.htaccess', 'w')) {
        fwrite($fh, "deny from all\n");
        fclose($fh);
      }
      if ($fh = fopen("$path/submissions" . '/.htaccess', 'w')) {
        fwrite($fh, "deny from all\n");
        fclose($fh);
      }
      return TRUE;
    }
    else {
      $oldumask = umask(0);
      $res1 = @mkdir($path, FILEDEPOT_CHMOD_DIRS);
      $res2 = @mkdir("{$path}/submissions", FILEDEPOT_CHMOD_DIRS);
      umask($oldumask);
      if ($res1 === FALSE OR $res2 === FALSE) {
        watchdog('fildepot', "Failed to create server directory $path or $path/submissions");
        RETURN FALSE;
      }
      else {
        if ($fh = fopen($path . '/.htaccess', 'w')) {
          fwrite($fh, "deny from all\n");
          fclose($fh);
        }
        if ($fh = fopen("$path/submissions" . '/.htaccess', 'w')) {
          fwrite($fh, "deny from all\n");
          fclose($fh);
        }
        return TRUE;
      }
    }
  }


  public function deleteFolder($filedepot_folder_id) {
    /* Test for valid folder and admin permission one more time
     * We are going to override the permission test in the function filedepot_getRecursiveCatIDs()
     * and return all subfolders in case hidden folders exist for this user.
     * If this user has admin permission for parent -- then they should be able to delete it
     * and any subfolders.
     */

    if ($filedepot_folder_id > 0 AND $this->checkPermission($filedepot_folder_id, 'admin')) {
      // Need to delete all files in the folder
      /* Build an array of all linked categories under this category the user has admin access to */
      $list = array();
      array_push($list, $filedepot_folder_id);

      // Passing in permission check over-ride as noted above to filedepot_getRecursiveCatIDs()
      $list = $this->getRecursiveCatIDs($list, $filedepot_folder_id, 'admin', TRUE);
      foreach ($list as $cid) {
        // Drupal will remove the file attachments automatically when folder node is deleted even if file usage is > 1
        $query = db_query("SELECT drupal_fid FROM {filedepot_files} WHERE cid=:cid", array(':cid' => $cid));
        while ($A = $query->fetchAssoc()) {
          $file = file_load($A['drupal_fid']);
          file_usage_delete($file, 'filedepot');
        }

        $subfolder_nid = db_query("SELECT nid FROM {filedepot_categories} WHERE cid=:cid",
        array(':cid' => $cid))->fetchField();
        db_delete('filedepot_categories')
        ->condition('cid', $cid)
        ->execute();
        db_delete('filedepot_categories')
        ->condition('cid', $cid)
        ->execute();
        db_delete('filedepot_access')
        ->condition('catid', $cid)
        ->execute();
        db_delete('filedepot_recentfolders')
        ->condition('cid', $cid)
        ->execute();
        db_delete('filedepot_notifications')
        ->condition('cid', $cid)
        ->execute();
        db_delete('filedepot_filesubmissions')
        ->condition('cid', $cid)
        ->execute();

        // Call the drupal node delete now for the subfolder node
        //watchdog('filedepot',"Calling node_delete for node id: {$subfolder_nid}");
        node_delete($subfolder_nid);

        // Remove the physical directory
        $uri = $this->root_storage_path . $cid;
        if (file_exists($uri)) {
          $ret = @drupal_rmdir("{$uri}/.htaccess");
          $ret = @drupal_rmdir("{$uri}/submissions/.htaccess");
          $ret = @drupal_rmdir("{$uri}/submissions");
          $ret = @drupal_rmdir($uri);
        }

      }
      return TRUE;
    }
    else {
      return FALSE;
    }

  }

  public function getFileIcon($fname) {
    $ext = end(explode(".", $fname));
    if (array_key_exists($ext, $this->iconmap)) {
      $icon = $this->iconmap[$ext];
    }
    else {
      $icon = $this->iconmap['default'];
    }
    return $icon;
  }



  /* Delete the file and any versions */
  public function deleteFile($fid) {
    $query = db_query("SELECT cid,drupal_fid FROM {filedepot_files} WHERE fid=:fid", array(':fid' => $fid));
    list($cid, $dfid) = array_values($query->fetchAssoc());
    if ($this->checkPermission($cid, 'admin')) {
      $file = file_load($dfid);

      // Drupal is not updating the node when the file (attachment) is deleted
      // Need to cycle thru the attachments and remove it then save the node
      $nid = db_query("SELECT nid FROM {filedepot_categories} WHERE cid=:cid",
      array('cid' => $cid))->fetchField();
      $foldernode = node_load($nid);
      foreach ($foldernode->filedepot_folder_file[LANGUAGE_NONE] as $delta => $attachment) {
        if ($attachment['fid'] == $dfid) {
          unset($foldernode->filedepot_folder_file[LANGUAGE_NONE][$delta]);
          node_save($foldernode);
          break;
        }
      }
      file_usage_delete($file, 'filedepot');
      file_delete($file);
      return TRUE;
    }
    else {
      return FALSE;
    }
  }

  public function deleteSubmission($id) {
    $query = db_query("SELECT cid,drupal_fid,tempname,fname,notify FROM {filedepot_filesubmissions} WHERE id=:id",
    array('id' => $id));
    list($cid, $drupal_fid, $tempname, $fname, $notify) = array_values($query->fetchAssoc());
    if (!empty($tempname) AND file_exists("{$this->root_storage_path}{$cid}/submissions/$tempname")) {
      @unlink("{$this->root_storage_path}{$cid}/submissions/$tempname");
      // Send out notification of submission being deleted to user - before we delete the record as it's needed to create notification message
      if ($notify == 1) {
        filedepot_sendNotification($id, FILEDEPOT_NOTIFY_REJECT);
      }
      db_delete('filedepot_filesubmissions')
      ->condition('id', $id)
      ->execute();
      return TRUE;
    }
    else {
      return FALSE;
    }
  }

  public function moveFile($fid, $newcid) {
    global $user;

    $filemoved = FALSE;
    if ($newcid > 0) {
      $query = db_query("SELECT fname,cid,drupal_fid,version,submitter FROM {filedepot_files} WHERE fid=:fid", array('fid' => $fid));
      list($fname, $orginalCid, $dfid, $curVersion, $submitter) = array_values($query->fetchAssoc());
      if ($submitter == $user->uid OR $this->checkPermission($newcid, 'admin')) {
        if ($newcid !== intval($orginalCid)) {

          /* Need to move the file */
          $query2 = db_query("SELECT fname FROM {filedepot_fileversions} WHERE fid=:fid", array('fid' => $fid));
          while ($A = $query2->fetchAssoc()) {
            $fname = stripslashes($A['fname']);
            $sourcefile = $this->root_storage_path . "{$orginalCid}/{$fname}";

            $private_destination = "private://filedepot/{$newcid}/";

            // Best to call file_prepare_directory() - even if you believe directory exists
            file_prepare_directory($private_destination, FILE_CREATE_DIRECTORY);

            $file = file_load($dfid);
            $private_uri = $private_destination . $file->filename;
            $file = file_move($file, $private_uri, FILE_EXISTS_RENAME);
            $file->display = 1;
            list($scheme, $target) = explode('://', $file->uri, 2);
            $moved_filename = str_replace("filedepot/{$newcid}/", '', $target);
            if ($moved_filename != $fname) {
              db_update('filedepot_fileversions')
              ->fields(array('fname' => $moved_filename))
              ->condition('fid', $fid)
              ->execute();
            }

            // Remove the attached file from the original folder
            $source_folder_nid = db_query("SELECT nid FROM {filedepot_categories} WHERE cid=:cid",    array(':cid' => $orginalCid))->fetchField();
            $node = node_load($source_folder_nid);
            // Remove the moved file now from the source folder
            foreach ($node->filedepot_folder_file[LANGUAGE_NONE] as $delta => $attachment) {
              if ($attachment['fid'] == $file->fid) {
                unset($node->filedepot_folder_file[LANGUAGE_NONE][$delta]);
                node_save($node);
                break;
              }
            }

            // Add the moved file to the target folder
            // Doing node_save changes the file status to permanent in the file_managed table
            $target_folder_nid = db_query("SELECT nid FROM {filedepot_categories} WHERE cid=:cid",    array(':cid' => $newcid))->fetchField();
            $node = node_load($target_folder_nid);
            $node->filedepot_folder_file[LANGUAGE_NONE][] = (array) $file; //the name of the field that requires the files
            node_save($node);

            // Need to clear the cache as the node will still have the original file name
            field_cache_clear();
            db_update('filedepot_files')
            ->fields(array('cid' => $newcid))
            ->condition('fid', $fid)
            ->execute();
          }
          $filemoved = TRUE;
        }
      }
      else {
        watchdog('filedepot', 'User (@user) does not have access to move file(@fid): @name to category: @newcid',
        array('@user' => $user->name, '@fid' => $fid, '@name' => $fname, '@newcid' => $newcid));
      }
    }
    return $filemoved;
  }


  public function deleteVersion($fid, $version) {
    $q1 = db_query("SELECT cid,version FROM {filedepot_files} WHERE fid=:fid", array('fid' => $fid));
    list($cid, $curVersion) = array_values($q1->fetchAssoc());
    $q2 = db_query("SELECT fname, drupal_fid FROM {filedepot_fileversions} WHERE fid=:fid AND version=:version",
    array(
      ':fid' => $fid,
      ':version' => $version,
    )
    );
    list($fname, $dfid) = array_values($q2->fetchAssoc());
    if ($cid > 0 AND !empty($fname) AND $dfid > 0) {
      db_delete('filedepot_fileversions')
      ->condition('fid', $fid)
      ->condition('version', $version)
      ->execute();
      // Need to check there are no other repository entries in this category for the same filename
      if (db_query("SELECT count(fid) FROM {filedepot_files} WHERE cid=:cid and fname=:fname",
      array('cid' => $cid, 'fname' => $fname))->fetchField() > 1) {
        watchdog('filedepot', 'Delete file(@fid), version: @version, File: @fname. Other references found - not deleted.',
        array('@fid' => $fid, '@version' => $version, '@fname' => $fname));
      }
      else {
        if (!empty($fname) AND file_exists("{$this->root_storage_path}{$cid}/{$fname}")) {
          @unlink("{$this->root_storage_path}{$cid}/{$fname}");
        }
        watchdog('filedepot', 'Delete file(@fid), version: @version, File: @fname. Single reference - file deleted.',
        array('@fid' => $fid, '@version' => $version, '@fname' => $fname));
      }
      // If there is at least 1 more version record on file then I may need to update current version
      if (db_query("SELECT count(fid) FROM {filedepot_fileversions} WHERE fid=:fid", array(':fid' => $fid))->fetchField() > 0) {
        if ($version == $curVersion) {
          // Retrieve most current version on record
          $q3 = db_query("SELECT fname,version,date FROM {filedepot_fileversions} WHERE fid=:fid ORDER BY version DESC",
          array(':fid' => $fid), 0, 1);
          list($fname, $version, $date) = array_values($q3->fetchAssoc());
          db_query("UPDATE {$filedepot_files} SET fname=:fname,version=:version, date=:time WHERE fid=:fid",
          array(
            ':fname' => $fname,
            ':version' => $version,
            ':time' => time(),
            ':fid' => $fid,
          ));
        }
      }
      else {
        watchdog('filedepot', 'Delete File final version for fid(@fid), Main file records deleted.',
        array('@fid' => $fid, '@version' => $version, '@fname' => $fname));
        db_delete('filedepot_files')
        ->condition('fid', $fid)
        ->execute();
      }
      return TRUE;

    }
    else {
      return FALSE;
    }

  }

  public function approveFileSubmission($id) {
    $nexcloud =  filedepot_nexcloud();

    $query = db_query("SELECT * FROM {filedepot_filesubmissions} WHERE id=:fid", array('fid' => $id));
    $rec = $query->fetchObject();
    $newfid = 0;

    // @TODO: Check if there have been multiple submission requests for the same file and thus have same new version #
    if ($rec->version == 1) {

      $private_destination = "private://filedepot/{$rec->cid}/";

      // Best to call file_prepare_directory() - even if you believe directory exists
      file_prepare_directory($private_destination, FILE_CREATE_DIRECTORY);

      $file = file_load($rec->drupal_fid);
      $private_uri = $private_destination . $rec->fname;
      $file = file_move($file, $private_uri, FILE_EXISTS_RENAME);

      // Get name of new file in case it was renamed after the file_move()
      list($scheme, $target) = explode('://', $file->uri, 2);
      $filename = str_replace("filedepot/{$rec->cid}/", '', $target);

      if (isset($rec->title) AND !empty($rec->title)) {
        $filetitle = $rec->title;
      }
      else {
        $filetitle = $rec->fname;
      }

      // Load the node for the folder and then update the file usage table
      $nid = db_query("SELECT nid FROM {filedepot_categories} WHERE cid=:cid",
      array(':cid' => $rec->cid))->fetchField();
      $node = node_load($nid);
      file_usage_add($file, 'filedepot', 'node', $node->nid);
      // Remove the record for the core file module from the file usage table
      file_usage_delete($file, 'file');

      $query = db_insert('filedepot_files');
      $query->fields(array('cid', 'fname', 'title', 'description', 'version', 'drupal_fid', 'size', 'mimetype', 'submitter', 'status', 'date', 'version_ctl', 'extension'));
      $query->values(array(
        'cid' => $rec->cid,
        'fname' => $filename,
        'title' => $filetitle,
        'description' => $rec->description,
        'version' => $rec->version,
        'drupal_fid' => $file->fid,
        'size' => $file->filesize,
        'mimetype' => $file->filemime,
        'submitter' => $rec->submitter,
        'status' => 1,
        'date' => $rec->date,
        'version_ctl' => $rec->version_ctl,
        'extension' => $rec->extension,
      )
      );
      $query->execute();

      // Get fileid for the new file record
      $newfid = db_query_range("SELECT fid FROM {filedepot_files} WHERE cid=:cid AND submitter=:uid ORDER BY fid DESC", 0, 1,
      array(':cid' => $rec->cid, ':uid' => $rec->submitter))->fetchField();

      $query = db_insert('filedepot_fileversions');
      $query->fields(array('fid', 'fname', 'drupal_fid', 'version', 'notes', 'size', 'date', 'uid', 'status'));
      $query->values(array(
        'fid' => $newfid,
        'fname' => $filename,
        'drupal_fid' => $file->fid,
        'version' => 1,
        'notes' => $rec->version_note,
        'size' => $file->filesize,
        'date' => time(),
        'uid' => $rec->submitter,
        'status' => 1,
      ));
      $query->execute();

      if (!empty($rec->tags) AND $this->checkPermission($rec->cid, 'view', 0, FALSE)) {
        $nexcloud->update_tags($fid, $rec->tags);
      }

    }

    if ($newfid > 0) {
      if ($rec->notify == 1) {
        filedepot_sendNotification($newfid, FILEDEPOT_NOTIFY_APPROVED);
      }
      db_delete('filedepot_filesubmissions')
      ->condition('id', $id)
      ->execute();

      // Send out notifications of update to all subscribed users
      filedepot_sendNotification($newfid, FILEDEPOT_NOTIFY_NEWFILE);

      // Update related folders last_modified_date
      $workspaceParentFolder = filedepot_getTopLevelParent($rec->cid);
      filedepot_updateFolderLastModified($workspaceParentFolder);

      return TRUE;

    }
    else {
      return FALSE;
    }

  }
}
