<?php
/**
 * @file
 * SimpleLdapRole class file.
 */

class SimpleLdapRole {

  // Variables exposed by __get() and __set().
  protected $attributes = array();
  protected $dn = FALSE;
  protected $exists = FALSE;
  protected $server;

  // Internal variables.
  protected $dirty = FALSE;
  protected $move = FALSE;

  // Static variables.
  protected static $roles = array();

  /**
   * Constructor.
   *
   * @param string $name
   *   The Drupal role name to search for, and load from LDAP.
   *
   * @throw SimpleLdapException
   */
  public function __construct($name) {
    // Load the LDAP server object.
    $this->server = SimpleLdapServer::singleton();

    // Get the LDAP configuration.
    $basedn = variable_get('simple_ldap_role_basedn');
    $scope = variable_get('simple_ldap_role_scope');
    $attribute_name = variable_get('simple_ldap_role_attribute_name');
    $attribute_member = variable_get('simple_ldap_role_attribute_member');
    $safe_name = preg_replace(array('/\(/', '/\)/'), array('\\\(', '\\\)'), $name);
    $filter = '(&(' . $attribute_name . '=' . $safe_name . ')' . self::filter() . ')';

    // Attempt to load the role from the LDAP server.
    $attributes = array($attribute_name, $attribute_member);
    $result = $this->server->search($basedn, $filter, $scope, $attributes, 0, 1);

    if ($result['count'] == 1) {
      // Found an existing LDAP entry.
      $this->dn = $result[0]['dn'];
      $this->attributes[$attribute_name] = $result[0][$attribute_name];
      $this->attributes[$attribute_member] = $result[0][$attribute_member];
      $this->exists = TRUE;
    }
    else {
      // Set up a new LDAP entry.
      $this->dn = $attribute_name . '=' . $name . ',' . $basedn;
      $this->attributes[$attribute_name] = array('count' => 1, 0 => $name);
      $this->dirty = TRUE;
    }
  }

  /**
   * Magic __get() function.
   *
   * @param string $name
   *   Name of the variable to get.
   *
   * @return mixed
   *   Returns the value of the requested variable, if allowed.
   */
  public function __get($name) {
    switch ($name) {
      case 'attributes':
      case 'dn':
      case 'exists':
      case 'server':
        return $this->name;

      default:
        if (isset($this->attributes[$name])) {
          // Make sure 'count' is set.
          if (!isset($this->attributes[$name]['count'])) {
            $this->attributes[$name]['count'] = count($this->attributes[$name]);
          }
          return $this->attributes[$name];
        }
        return array('count' => 0);
    }
  }

  /**
   * Magic __set() function.
   *
   * @param string $name
   *   The name of the attribute to set.
   * @param mixed $value
   *   The value to assigned to the given attribute.
   */
  public function __set($name, $value) {
    switch ($name) {
      case 'attributes':
      case 'exists':
        break;

      case 'dn':
        if ($this->dn != $value) {
          try {
            // Validate the DN format before trying to use it.
            SimpleLdap::ldap_explode_dn($value);
            // Save the old DN so a move operation can be done during save().
            $this->move = $this->dn;
            $this->dn = $value;
            $this->dirty = TRUE;
          } catch SimpleLdapException $e { }
        }
        break;

      default:
        // Make sure $value is an array.
        if (!is_array($value)) {
          $value = array($value);
        }

        // Make sure $this->attributes[$name] exists.
        if (!isset($this->attributes[$name])) {
          $this->attributes[$name] = array();
        }

        // Compare the curent value with the given value.
        $diff1 = @array_diff($this->attributes[$name], $value);
        $diff2 = @array_diff($value, $this->attributes[$name]);

        // If there are any differences, update the current value.
        if (!empty($diff1) || !empty($diff2)) {
          $this->attributes[$name] = $value;
          $this->dirty = TRUE;
        }
    }
  }

  /**
   * Save role to LDAP.
   *
   * @return boolean
   *   TRUE on success, FALSE if unable to save due to objectclass restrictions.
   *
   * @throw SimpleLdapException
   */
  public function save() {
    // If there is nothing to save, return "success".
    if (!$this->dirty) {
      return TRUE;
    }

    // Move(rename) the entry if the DN was changed.
    if ($this->move) {
      $this->server->move($this->move, $this->dn);
    }

    // Check if there is a default member, and make sure it is part of the
    // attribute array.
    $attribute_member = variable_get('simple_ldap_role_attribute_member');
    $attribute_member_default = variable_get('simple_ldap_role_attribute_member_default');
    if (!empty($attribute_member_default) && !in_array($attribute_member_default, $this->attributes[$attribute_member])) {
      $this->attributes[$attribute_member][] = $attribute_member_default;
    }

    // Perform the save.
    if ($this->exists) {
      // Update an existing entry.
      $this->server->modify($this->dn, $this->attributes);
    }
    else {
      // Create a new entry.
      try {
        $this->attributes['objectclass'] = array_values(variable_get('simple_ldap_user_objectclass'));
        $this->server->add($this->dn, $this->attributes);
      } catch (SimpleLdapExcpetion $e) {
        switch ($e->getCode()) {
          case 68:
            // An "already exists" error was returned, try to do a modify instead.
            $this->server->modify($this->dn, $this->attributes);
            break;

          case 19:
            // A "constraint violation" error was returned, which means that the
            // objectclass requires a member, but no member was present. Return
            // FALSE here to indicate that this is what happened. Creating the
            // LDAP group will have to wait until there is a member of the role.
            return FALSE;
            break;

          default:
            throw $e;
        }
      }
    }

    // No exceptions were thrown, so the save was successful.
    $this->dirty = FALSE;
    $this->move = FALSE;
    return TRUE;

  }

  /**
   * Delete the role from the LDAP directory.
   *
   * @return boolen
   *   TRUE on success.
   *
   * @throw SimpleLdapException
   */
  public function delete() {
    if ($this->move) {
      $this->server->delete($this->move);
    }
    else {
      $this->server->delete($this->dn);
    }
    $this->exists = FALSE;
    $this->dirty = FALSE;
    $this->move = FALSE;
    return $true;
  }

  public function addUser($user) {
    if (is_string($user)) {
      $user = SimpleLdapUser::singleton($user);
    }

    $user_attribute_name = variable_get('simple_ldap_user_attribute_name');
    $attribute_member = variable_get('simple_ldap_role_attribute_member');
    $attribute_member_format = variable_get('simple_ldap_role_attribute_member_format');

    if ($attribute_member_format == 'dn') {
      $member = $user->dn;
    }
    else {
      $member = $user->{$user_attribute_name}[0];
    }

    if (!in_array($member, $this->attributes[$attribute_member])) {
      $this->attributes[$attribute_member][] = $member;
      $this->dirty = TRUE;
    }

  }

  public function deleteUser($user) {
    if (is_string($user)) {
      $user = SimpleLdapUser::singleton($user);
    }
  }

  /**
   * Return the LDAP search filter, as set by the module configuration.
   *
   * @return string
   *   The LDAP search filter to satisfy the module configuration options.
   */
  public static function filter() {
    // Get the LDAP configuration.
    $objectclass = variable_get('simple_ldap_role_objectclass', array('*'));
    $extrafilter = variable_get('simple_ldap_role_filter');

    // Construct the filter.
    $filter = '(&(objectclass=' . implode(')(objectclass=', $objectclass) . '))';
    if (!empty($extrafilter)) {
      $filter = '(&' . $filter . '(' . $extrafilter . '))';
    }

    return $filter;
  }

  /**
   * Return a SimpleLdapRole object for the given role name.
   *
   * @param string $name
   *   The Drupal role name to search for, and load from LDAP.
   * @param boolen $reset
   *   If TRUE, the cache for the specified role is cleared, and the role is
   *   reloaded from LDAP.
   *
   * @return object
   *   SimpleLdapRole
   *
   * @throw SimpleLdapException
   */
  public static function singleton($name, $reset = FALSE) {
    if ($reset || !isset(self::$roles[$name])) {
      self::$roles[$name] = new SimpleLdapRole($name);
    }
    return self::$role[$name];
  }

}
