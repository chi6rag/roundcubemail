<?php

/*
 +-----------------------------------------------------------------------+
 | Roundcube/rcube_ldap_generic.php                                      |
 |                                                                       |
 | This file is part of the Roundcube Webmail client                     |
 | Copyright (C) 2006-2014, The Roundcube Dev Team                       |
 | Copyright (C) 2012-2014, Kolab Systems AG                             |
 |                                                                       |
 | Licensed under the GNU General Public License version 3 or            |
 | any later version with exceptions for skins & plugins.                |
 | See the README file for a full license statement.                     |
 |                                                                       |
 | PURPOSE:                                                              |
 |   Provide basic functionality for accessing LDAP directories          |
 |                                                                       |
 +-----------------------------------------------------------------------+
 | Author: Thomas Bruederli <roundcube@gmail.com>                        |
 |         Aleksander Machniak <machniak@kolabsys.com>                   |
 +-----------------------------------------------------------------------+
*/

/**
 * Model class to access an LDAP directories
 *
 * @package    Framework
 * @subpackage LDAP
 */
class rcube_ldap_generic extends Net_LDAP3
{
    /** private properties */
    protected $cache = null;
    protected $attributes = array('dn');

    function __construct($config = null)
    {
        parent::__construct($config);

        $this->config_set('log_hook', array($this, 'log'));
    }

    /**
     * Establish a connection to the LDAP server
     */
    public function connect($host = null)
    {
        // Net_LDAP3 does not support IDNA yet
        // also parse_host() here is very Roundcube specific
        $host = rcube_utils::idn_to_ascii(rcube_utils::parse_host($host));

        return parent::connect($host);
    }

    /**
     * Get a specific LDAP entry, identified by its DN
     *
     * @param string $dn Record identifier
     *
     * @return array Hash array
     */
    function get_entry($dn)
    {
        return parent::get_entry($dn, $this->attributes);
    }

    /**
     * Prints debug/error info to the log
     */
    public function log($level, $msg)
    {
        $msg = implode("\n", $msg);

        switch ($level) {
        case LOG_DEBUG:
        case LOG_INFO:
        case LOG_NOTICE:
            if ($this->config['debug']) {
                rcube::write_log('ldap', $msg);
            }
            break;

        case LOG_EMERGE:
        case LOG_ALERT:
        case LOG_CRIT:
            rcube::raise_error($msg, true, true);
            break;

        case LOG_ERR:
        case LOG_WARNING:
            rcube::raise_error($msg, true, false);
            break;
        }
    }

    /**
     * @deprecated
     */
    public function set_debug($dbg = true)
    {
        $this->config['debug'] = (bool) $dbg;
    }

    /**
     * @deprecated
     */
    public function set_cache($cache_engine)
    {
        $this->config['cache'] = $cache_engine;
    }

    /**
     * @deprecated
     */
    public static function scope2func($scope, &$ns_function = null)
    {
        return self::scope_to_function($scope, $ns_function);
    }

    /**
     * @deprecated
     */
    public function set_config($opt, $val = null)
    {
        $this->config_set($opt, $val);
    }

    /**
     * @deprecated
     */
    public function add($dn, $entry)
    {
        return $this->add_entry($dn, $entry);
    }

    /**
     * @deprecated
     */
    public function delete($dn)
    {
        return $this->delete_entry($dn);
    }

    /**
     * Wrapper for ldap_mod_replace()
     *
     * @see ldap_mod_replace()
     */
    public function mod_replace($dn, $entry)
    {
        $this->_debug("C: Replace $dn: ".print_r($entry, true));

        if (!ldap_mod_replace($this->conn, $dn, $entry)) {
            $this->_debug("S: ".ldap_error($this->conn));
            return false;
        }

        $this->_debug("S: OK");
        return true;
    }

    /**
     * Wrapper for ldap_mod_add()
     *
     * @see ldap_mod_add()
     */
    public function mod_add($dn, $entry)
    {
        $this->_debug("C: Add $dn: ".print_r($entry, true));

        if (!ldap_mod_add($this->conn, $dn, $entry)) {
            $this->_debug("S: ".ldap_error($this->conn));
            return false;
        }

        $this->_debug("S: OK");
        return true;
    }

    /**
     * Wrapper for ldap_mod_del()
     *
     * @see ldap_mod_del()
     */
    public function mod_del($dn, $entry)
    {
        $this->_debug("C: Delete $dn: ".print_r($entry, true));

        if (!ldap_mod_del($this->conn, $dn, $entry)) {
            $this->_debug("S: ".ldap_error($this->conn));
            return false;
        }

        $this->_debug("S: OK");
        return true;
    }

    /**
     * Wrapper for ldap_rename()
     *
     * @see ldap_rename()
     */
    public function rename($dn, $newrdn, $newparent = null, $deleteoldrdn = true)
    {
        $this->_debug("C: Rename $dn to $newrdn");

        if (!ldap_rename($this->conn, $dn, $newrdn, $newparent, $deleteoldrdn)) {
            $this->_debug("S: ".ldap_error($this->conn));
            return false;
        }

        $this->_debug("S: OK");
        return true;
    }

    /**
     * Wrapper for ldap_list() + ldap_get_entries()
     *
     * @see ldap_list()
     * @see ldap_get_entries()
     */
    public function list_entries($dn, $filter, $attributes = array('dn'))
    {
        $list = array();
        $this->_debug("C: List $dn [{$filter}]");

        if ($result = ldap_list($this->conn, $dn, $filter, $attributes)) {
            $list = ldap_get_entries($this->conn, $result);

            if ($list === false) {
                $this->_debug("S: ".ldap_error($this->conn));
                return array();
            }

            $count = $list['count'];
            unset($list['count']);

            $this->_debug("S: $count record(s)");
        }
        else {
            $this->_debug("S: ".ldap_error($this->conn));
        }

        return $list;
    }

    /**
     * Wrapper for ldap_read() + ldap_get_entries()
     *
     * @see ldap_read()
     * @see ldap_get_entries()
     */
    public function read_entries($dn, $filter, $attributes = null)
    {
        $this->_debug("C: Read $dn [{$filter}]");

        if ($this->conn && $dn) {
            $result = @ldap_read($this->conn, $dn, $filter, $attributes, 0, (int)$this->config['sizelimit'], (int)$this->config['timelimit']);
            if ($result === false) {
                $this->_debug("S: ".ldap_error($this->conn));
                return false;
            }

            $this->_debug("S: OK");
            return ldap_get_entries($this->conn, $result);
        }

        return false;
    }
}

// for backward compat.
class rcube_ldap_result extends Net_LDAP3_Result {}
