<?php

if(!defined("API")) { return false; }

/**
 * This program was created to make a set of classes to be used for easy
 * website creation. Usage: simply extend the paObject class to make models
 * and the OUT_Request class to make controllers. HTML is stored in templates
 * with special tags. See the OUT_Template class for more details.
 * Made by Tyler Romeo <tylerromeo@gmail.com>
 *
 * Copyright (C) 2009 Tyler Romeo
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 * 
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

/**
 * Interacts with a MySQL or Mssql database to store and retrieve
 * information.
 *
 * @package API
 * @author Tyler Romeo <tylerromeo@gmail.com>
 * @license http://opensource.org/licenses/gpl-3.0.html GNU General Public License 3.0
 * @copyright Copyright (c) 2009, Tyler Romeo (Some Rights Reserved)
 */
class paDatabase
{
    /**
     * Array of database functions to be used
     * in object.
     * @static
     */
    static $globalfunctions = array( "mysql" => array(
                                         "connect" => "mysql_connect",
                                         "close"   => "mysql_close",
                                         "db"      => "mysql_select_db",
                                         "query"   => "mysql_query",
                                         "result"  => "mysql_result",
                                         "array"   => "mysql_fetch_array",
                                         "assoc"   => "mysql_fetch_assoc",
                                         "escape"  => "mysql_real_escape_string",
                                         "numrows" => "mysql_num_rows" ),
                                     "mssql" => array(
                                         "connect" => "mssql_connect",
                                         "close"   => "mssql_close",
                                         "db"      => "mssql_select_db",
                                         "query"   => "mssql_query",
                                         "result"  => "mssql_result",
                                         "array"   => "mssql_fetch_array",
                                         "escape"  => "addslashes" )
                             );

    /**
     * Stores singleton instance of object.
     * @static
     */
    static $instance;

    /**
     * Stores resource for database connection.
     * @private
     */
    private $conn = false;

    /**
     * Stores connection data for database,
     * i.e. server, username, and password.
     * @private
     */
    private $conndata;

    /**
     * Stores the database name to connect to.
     * @private
     */
    private $dbname;

    /**
     * Stores the functions to interact with
     * the database. Usually loaded from one of the
     * static variables.
     * @private
     */
    private $functions;

    /**
     * Stores table objects.
     * @private
     */
    private $tables;

    /**
     * Stores a MAIN_Logger object.
     * @private
     */
    private $log;

    /**
     * Stores connection data and attempts to connect.
     *
     * The database type is given in the parameters. For MySQL and extensions
     * with similar function calls can be built into the class. Otherwise, child
     * classes will be automatically created if they exist.
     *
     * @param string  $server    Server address to connect to
     * @param string  $username  Username for the server
     * @param string  $password  Password for the server
     * @param string  $database  Database name to connect to
     * @param object &$log       MAIN_Logger object
     * @param string  $type      Name of database type
     *
     * @return bool|object Returns false if connection fails, returns object otherwise
     */
    public function __construct($server, $username, $password, $database, &$log, $type = 'mysql') {
        assert($log instanceof MAIN_Logger);

        if(isset(self::$globalfunctions[$type])) {
            $this->functions =  self::$globalfunctions[$type];
        } elseif(class_exists($classname = "paDatabase_" . ucfirst(strtolower($type)))) {
            return $classname($server, $username, $password, $database, $log);
        } else {
            throw new paParameterError(paParameterError::ERROR, 'paDatabase::__construct', 'Invalid database type.', $log);
        }

        $this->conndata  =  array($server, $username, $password);
        $this->dbname    =  $database;
        $this->log       =& $log;

        // Real connection attempt starts here.
        $this->conn      = false;
        $this->connect();
    }

    /**
     * Connects to a database server, e.g. MySQL, and stores
     * the connection.
     *
     * @return bool Returns true for success, false for failure
     */
    public function connect() {
        /*
         * Check for existing connection first.
         * If so, close it then continue.
         */
        global $paHooks;
        if(is_resource($this->conn)) {
            $this->close();
        } $this->log->log(MAIN_Logger::INFO, 'paDatabase::connect',
                         'Starting database connection with connection data: ' . strtr(var_export($this->conndata, true), "\n", ''));

        // Check for connect function and connect to server.
        if(!function_exists($this->functions["connect"])) {
            throw new paDatabaseError(paDatabaseError::ERROR, 'paDatabase::connect', 'Connection function does not exist.', $this->log);
        }
        
        $paHooks->call('db_preconnect', $this->conndata, $this->dbname);
        $retval = call_user_func_array($this->functions["connect"], $this->conndata);

        // See if connection is valid.
        if($retval === false) {
            throw new paDatabaseError(paDatabaseError::ERROR, 'paDatabase::connect', 'Connection failed for unknown reason.', $this->log);
        } else {
            $this->conn = $retval;
        }

        // Check for database function and select database.
        if(!function_exists($this->functions["db"])) {
            throw new paDatabaseError(paDatabaseError::ERROR, 'paDatabase::connect', 'Database selection function does not exist.', $this->log);
        }

        $retval = call_user_func($this->functions["db"], $this->dbname, $this->conn);

        // See if selection was successful.
        if(!$retval) {
            throw new paDatabaseError(paDatabaseError::ERROR, 'paDatabase::connect', 'Database selection failed (it may not exist).', $this->log);
        }
        $paHooks->call('db_postconnect', $retval);
        
        // Get list of tables and load into object.
        $res = $this->query("SHOW TABLES FROM {$this->dbname}");

        if($res === false) {
            throw new paDatabaseError(paDatabaseError::WARNING, 'paDatabase::connect', 'Could not retrieve a list of tables.', $this->log);
        }

        $list = array();
        while($row = $this->result($res)) {
            $list[$row[0]] = false;
        } $this->tables = $paHooks->call('db_tablelist', $list);

        return true;
    }

    /**
     * Checks if a database connection is open, and if so
     * shuts the connection.
     *
     * @return bool Returns true for success, false for failure
     */
    public function close() {
        // If there is no connection, return.
        $this->log->log(MAIN_Logger::INFO, 'paDatabase::close', 'Closing connection.');
        if(!is_resource($this->conn)) {
            throw new paDatabaseError(paDatabaseError::NOTICE, 'paDatabase::connect', 'Closing connection that never opened.', $this->log);
        }

        // Check for close function and close connection.
        if(!function_exists($this->functions["close"])) {
            throw new paDatabaseError(paDatabaseError::ERROR, 'paDatabase::close', 'Function to close connection does not exist.', $this->log);
        }

        $retval = call_user_func($this->functions["close"], $this->conn);

        return $retval;
    }

    /**
     * Submits a query to the database server, then returns
     * the result of the query function.
     *
     * @param string $sql A SQL query to submit
     *
     * @return bool|resource Returns true or false for INSERT, DELETE,
                             and other related queries. Returns a result
                             resource for SELECT queries.
     */
    public function query($sql) {
        global $paHooks;
        $curtime = gmdate('c');
        $sql = "/* Time: $curtime */ /* DB: {$this->dbname} */ " . $sql;

        $this->log->log(MAIN_Logger::INFO, 'paDatabase::query', "Making query: $sql");

        // Check for a connection first.
        if(!is_resource($this->conn)) {
            throw new paUserError(paUserError::WARNING, 'paDatabase::query', 'Database not connected yet.', $this->log);
        }

        // Check for query function and submit query.
        if(!function_exists($this->functions["query"])) {
            throw new paDatabaseError(paDatabaseError::ERROR, 'paDatabase::query', 'Query function does not exist.', $this->log);
        }

        if($paHooks->call('db_query', $sql)) {
            $retval = call_user_func($this->functions["query"], $sql, $this->conn);
        } else {
            throw new paDatabaseError(paDatabaseError::NOTICE, 'paDatabase::query', 'Query cancelled by hook.', $this->log);
        }
        
        return $retval;
    }

    /**
     * Obtains a result set from a result resource.
     *
     * @param resource $res  Result resource to get data from
     * @param string   $type The function to use to get the data
     *
     * @param array Array of all the rows retrieved
     */
    public function result($res, $type = "array") {
        // Check for a connection first.
        if(!is_resource($this->conn)) {
            throw new paUserError(paUserError::WARNING, 'paDatabase::result', 'Database not connected yet.', $this->log);
        }

        /*
         * Check for function and submit query.
         * The function is taken from the array of database
         * functions, using $type as the key.
         */
        if(!function_exists($this->functions[$type])) {
            throw new paDatabaseError(paDatabaseError::ERROR, 'paDatabase::result', 'Result retrieval function does not exist.', $this->log);
        }

        return call_user_func($this->functions[$type], $res);
    }

    /**
     * Escapes a string for use in a database query.
     *
     * @param string $string The string to escape
     *
     * @return bool|string Returns the escaped string, or false on failure.
     */
    public function escape($string) {
        global $paHooks;
        // Check for a connection first.
        if(!is_resource($this->conn)) {
            throw new paUserError(paUserError::WARNING, 'paDatabase::escape', 'Database not connected yet.', $this->log);
        }

        // Check for escape function and escape string.
        if(!function_exists($this->functions["escape"])) {
            throw new paDatabaseError(paDatabaseError::ERROR, 'paDatabase::escape', 'Escape function does not exist.', $this->log);
        }

        $paHooks->call('db_escape', $string);
        return call_user_func($this->functions["escape"], $string);
    }

    /**
     * Get a table object for query usage.
     *
     * Retrive an object for a specific table, rather than using the
     * general query function.
     *
     * @param string $tablename Name of the table
     *
     * @return object|bool Returns Table object, or false if table does not exist
     */
    public function getTable($tablename) {
        $this->log->log(MAIN_Logger::INFO, 'paDatabase::getTable', "Getting table $tablename.");
        if($this->tables[$tablename] instanceof paTable) {
            return $this->tables[$tablename];
        } elseif($this->tables[$tablename] === false) {
            $this->tables[$tablename] = new paTable($this, $tablename);
            return $this->tables[$tablename];
        } else {
            throw new paUserError(paUserError::WARNING, 'paDatabase::getTable',
                                  "$tablename does not exist.");
        }
    }

    /**
     * Get the log object for the database.
     *
     * @return object MAIN_Logger instance
     */
    public function &getLog() {
        return $this->log;
    }
}


/**
 * Interacts with a MySQL table to store and retrieve
 * information.
 *
 * Creates a number of abstract functions to allow easy database queries
 * as well as automatic escaping of given values.
 *
 * @package API
 * @author Tyler Romeo <tylerromeo@gmail.com>
 * @license http://opensource.org/licenses/gpl-3.0.html GNU General Public License 3.0
 * @copyright Copyright (c) 2009, Tyler Romeo (Some Rights Reserved)
 */
class paTable
{
    /**
     * The parent paDatabase object
     * @private
     */
    private $database;

    /**
     * The name of the table in use
     * @private
     */
    private $tablename;

    /**
     * Stores the database and table information.
     *
     * @param object &$database  Parent paDatabase object to use
     * @param string  $tablename Name of the table to use
     */
    public function __construct(&$database, $tablename) {
        $this->log =& $database->getLog();

        assert($database instanceof paDatabase);

        $this->database  =& $database;
        $this->tablename =  $tablename;
    }

    /**
     * Get the log object for the database.
     *
     * @return object MAIN_Logger instance
     */
    public function &getLog() {
        return $this->log;
    }

    /**
     * Queries the database by using the parent object's function.
     *
     * @param string $sql Query to be submitted
     *
     * @return bool|resource Returns true or false for INSERT, DELETE,
                             and other related queries. Returns a result
                             resource for SELECT queries.
     */
    public function query($sql) {
        $sql = "/* tablename: {$this->tablename} */ " . $sql;
        return $this->database->query($sql);
    }

    /**
     * Obtains a result set from a result resource by using the parent
     * object's function.
     *
     * @param resource $res  Result resource to get data from
     * @param string   $type The function to use to get the data
     *
     * @param mixed By default returns an array, but may change
                    depending on the $type.
     */
    public function result($res, $type = "array") {
        return $this->database->result($res, $type = "array");
    }

    /**
     * Escapes strings and arrays for use in a database query.
     *
     * If a string is given, it is escaped using the parent object's
     * function. If an array is given, all keys and values are
     * escaped using the same method.
     *
     * @param mixed $var The variable to escape
     *
     * @param mixed The escaped variable
     */
    public function escape($var) {
        if(is_array($var)) {
            // Escape all keys and values.
            $newvar = array();
            foreach($var as $key => $value) {
                $newvar[$this->database->escape($key)] = $this->database->escape($value);
            }
        } elseif(is_string($var)) {
            // Escape just the variable.
            $newvar = $this->database->escape($var);
        } elseif(is_int($var) || is_float($var)) {
            $newvar = $var;
        } else {
            // Bad variable type.
            $newvar = false;
        } return $newvar;
    }

    /**
     * Selects information from a table.
     *
     * The equivalent of a SELECT query, which is created from
     * the parameters and submitted to the database.
     *
     * @param string|array $fields      The fields to select as an array or string
     * @param        array $where       Where to select information from
     * @param string       $conditional The conditional to put in between the WHERE statements
     * @param        array $options     Other options to add to the query
     *
     * @return resource Returns a result resource to get query information
     */
    public function select($fields = '*', $where = '', $conditional = 'AND', $options = array()) {
        // Escape data and implode if an array.
        $fields = $this->escape($fields);
        if(is_array($fields)) {
            $fields = implode(',', $fields);
        }

        // WHERE statement and options.
        $where   = $this->whereStatement($where, $conditional);
        $options = $this->optionsStatement($options);

        // Do the query.
        $sql = "SELECT $fields FROM {$this->tablename} $where $options";
        return $this->query($sql);
    }

    /**
     * Inserts a row into the database.
     *
     * The equivalent of an INSERT query, which is created from
     * the parameters and submitted to the database.
     *
     * @param array $row The columns and values to add
     * @param array $options     Other options to add to the query
     *
     * @return bool Returns true on success, false on failure
     */
    public function insert($row, $options = array()) {
        // Return if not an array.
        assert(is_array($row));

        // Escape and separate list of columns and values.
        $row    = $this->escape($row);
        $cols   = implode(', ', array_keys($row));
        $values = implode('\', \'',            $row );

        // Options
        $options = $this->optionsStatement($options);

        // Do the query.
        $sql = "INSERT INTO {$this->tablename} ($cols) VALUES ('$values') $options";
        return $this->query($sql);
    }

    /**
     * Updates information in a table.
     *
     * The equivalent of an UPDATE query, which is created from
     * the parameters and submitted to the database.
     *
     * @param array  $row         The columns and values to be updated
     * @param array  $where       Where to select information from
     * @param string $conditional The conditional to put in between the WHERE statements
     * @param array  $options     Other options to add to the query
     *
     * @return bool Returns true on success, false on failure
     */
    public function update($row, $where = '', $conditional = 'AND', $options = array()) {
        // Return if not an array.
        assert(is_array($row));

        // Escape them implode into usable statement.
        $row = $this->escape($row);
        foreach($row as $key => &$value) {
            $value = "$key='$value' ";
        } $values = implode(", ", $row);

        // WHERE statement and options.
        $where   = $this->whereStatement($where, $conditional);
        $options = $this->optionsStatement($options);

        // Do the query.
        $sql = "UPDATE {$this->tablename} SET $values $where $options";
        return $this->query($sql);
    }

    /**
     * Deletes information from a table.
     *
     * The equivalent of a DELETE query, which is created from
     * the parameters and submitted to the database.
     *
     * @param        array $where       Where to select information from
     * @param string       $conditional The conditional to put in between the WHERE statements
     * @param        array $options     Other options to add to the query
     *
     * @return bool Returns true on success, false on failure
     */
    public function delete($where = array(), $conditional = 'AND', $options = array()) {
        // WHERE statement and options.
        $where   = $this->whereStatement($where, $conditional);
        $options = $this->optionsStatement($options);

        // Do the query.
        $sql = "DELETE FROM {$this->tablename} $where $options";
        return $this->query($sql);
    }

    /**
     * Returns an associative array of the columns for the table.
     *
     * @return array Columns and their descriptions
     */
    public function columns() {
        $res = $this->query("SHOW COLUMNS FROM {$this->tablename}");
        return $this->result($res ,'assoc');
    }

    /**
     * Takes a user-given array of information and makes a SQL WHERE statement.
     *
     * @param array  $where       Where to select information from
     * @param string $conditional The conditional to put in between the WHERE statements
     *
     * @return string Returns a valid WHERE statement
     */
    private function whereStatement($where, $conditional) {
        // Check if it is an array.
        if(is_array($where)) {
            // Start off the statement and escape.
            $tmp = 'WHERE ';
            $where = $this->escape($where);

            // Combine and implode values.
            foreach($where as $key => &$value) {
                $value = "$key='$value' ";
            } $tmp .= implode(" $conditional ", $where);

            // Return final value
            return $tmp;
        } else {
            // Bad variable type.
            return false;
        }
    }

    /**
     * Takes a user-given array of extra query options and
     * sticks them together for use in a query.
     *
     * @param array $options The database options
     *
     * @return string Returns a valid list of options
     */
    private function optionsStatement($options) {
        // Escape, combine, implode.
        $options = $this->escape($options);
        foreach($options as $key => &$value) {
            $value = "$key $value ";
        } return implode(' ', $options);
    }
}


/**
 * Interacts with a MySQL or Mssql database to store and retrieve
 * information for a defined object, such as a user object. Classes
 * should inherit this class in order to become a Database object.
 *
 * @package API
 * @author Tyler Romeo <tylerromeo@gmail.com>
 * @license http://opensource.org/licenses/gpl-3.0.html GNU General Public License 3.0
 * @copyright Copyright (c) 2009, Tyler Romeo (Some Rights Reserved)
 */
class paObject
{
    /**
     * Determines whether to enable password protection on a global scope. Should
     * be customized in the child class definition.
     * @static
     * @private
     */
    protected static $enableprotect = true;

    /**
     * Stores information about the object that has been retrieved
     * from the database.
     * @private
     */
    protected $info = array();

    /**
     * A paTable object to be used for all database queries.
     * @private
     */
    protected $table;

    /**
     * Store the database and given information within the object. If an empty password
     * is given then password protection is disabled. If the $curpass variable is empty then
     * client hashing will be disabled.
     *
     * @param object &$db         A paDatabase object used for database queries
     * @param int     $info       The ID of the object, a mapping of column name to value, or False if object is new
     */
    public function __construct(&$db, $info = False) {
        // Check the parmeters.
        assert(!is_string($column) || !is_string($value) || !$db instanceof paDatabase);

        // Store local table, log, and options.
        $this->db            =& $db;
        $this->log           =& $db->getLog();
        $this->table         =& $db->getTable(strtolower(get_class()));
        $this->protect       =  self::$enableprotect;

        // Initiate the object.
        if(is_array($info)) {
            // Object is old, get info from database.
            $this->updateFromDatabase($info);
        } elseif(is_int($info)) {
            $this->updateFromDatabase(array('id' => $info));
        } elseif($info === False) {
            // Object is new; put together info array and push to database.
            $this->info = array();
        } else {
            throw new paUserError(paUserError::ERROR, 'paObject::__construct', 'Invalid object info.', $this->log);
        }
        
        if($this->info === false) {
            throw new paUserError(paUserError::WARNING, 'paObject::__construct', 'Object does not exist.', $this->log);
        }
        
        $this->log->log(paLogger::NOTICE, get_class() . '::__construct',
                          "Creating new object with info: " . strtr(var_export($info, true), "\n", ''));
    }

    /**
     * Get the ID of the object.
     *
     * @return int The ID of the object
     */
    public function getId() {
        if(!isset($this->info['id'])) {
            return false;
        }
        return $this->info['id'];
    }

    /**
     * Get any property of an object, checking if the object
     * is allowed to access the property first.
     *
     * @param object $obj  A copy of the calling object (just give $this)
     * @param string $name Name of the property to access
     *
     * @return mixed The requested property
     */
    public function getInfo($name) {
        if($name == "id" || $name == "password") {
            return false;
        }
        return $this->info[$name];
    }

    /**
     * Set any property of an object, checking if the object
     * is allowed to access the property first.
     *
     * @param string $name  Name of the property to give a new value
     * @param string $value Value to give the property
     *
     * @return mixed The requested property
     */
    public function putInfo($property, $value) {
        if($name == "id" || $name == "password") {
            return false;
        }
        $this->info[$name] = $value;
        return true;
    }        

    /**
     * Sets this object as the parent of another object
     *
     * @param object $child Child object to store
     *
     * @return bool True on success, false otherwise
     */
    public function addChild($child) {
        if(!$this->parent || get_parent_class($child) != 'paObject') {
            return false;
        }

        // Put classname in lowercase and change to plural.
        $child->putInfo(str_to_lower(get_class()), $this->getId());
        return true;
    }
    
    /**
     * Gets an array of objects that are children of this object.
     * 
     * @param string $class The classname of the child
     * 
     * @return array List of objects
     */
    public function getChildren($class) {
        $table = $this->db->getTable(strtolower($class));
        if($table === false) {
            throw new paDatabaseError(paDatabaseError::ERROR, get_class() . '::getChildren',
                                 'Table does not exist.');
        }
        
        $res = $table->select('id', array(str_to_lower(get_class()) => $this->getId()));
        $info = $table->result($res);
        foreach($info as $id) {
            $objs[] = new $class($this->db, $id);
        } return $objs;
    }

    /**
     * Compare a given password to the stored password hash.
     *
     * @param string $new      The password to check
     *
     * @return bool True if the password is correct, false otherwise
     */
    public function checkPassword($new) {
        global $paHooks;
        if(!$this->protect || !self::$enableprotect) {
            return true;
        }

        // Compare the password to the original hash
        // check if: h(original) == h(given)
        $hash = self::hashPassword($new)
        $paHooks->call('obj_passwordentry', $hash);
        $match = $this->info['password'] == $hash;
        $retval = $match || $paHooks->call('obj_passwordcheck', $this, $match);
        return $retval
    }

    /**
     * Change the password for the object.
     *
     * @param string $new The new plaintext password
     *
     * @return bool True on success, false on failure.
     */
    public function changePassword($new) {
        global $wgHooks;
        if(!$this->protect || !self::$enableprotect) {
            return true;
        }

        # FIXME: Use a salt.
        $password = self::hashPassword($new);
        $paHooks->call('obj_passwordchange', $this, $password);
        $this->info['password'] = $password;
        return $this->updateToDatabase();
    }

    /**
     * Get properties about the object from the database.
     *
     * @param string $column Column to search for info from.
     * @param string $value  Value in the column to match the object to.
     */
    public function updateFromDatabase($info) {
        $res   = $this->table->select('*', $info);
        $this->info = $this->table->result($res);
        if($this->info === false) {
            return false;
        }
    }

    /**
     * Update properties about the object to the database.
     *
     * @return bool True on success, false on failure.
     */
    public function updateToDatabase($insert = False) {
        # FIXME: Inserts when it should update.
        return $this->table->insert($this->info);
    }

    /**
     * Hash a given plaintext password a given number of times (1 hash is the default).
     *
     * @param string $password   The password to hash
     * @param int    $iterations Number of times to hash the password
     *
     * @return string Hash of the password
     */
    public static function hashPassword($password, $salt = '', $iterations = 1) {
        for($i = 0; $i < $iterations; $i++) {
            $hash = hash('sha512', $salt . $password);
        } return $hash;
    }

    /**
     * Get a list of objects for this class.
     *
     * @param object &$db     The paDatabase to retrieve from
     * @param array   $where  List of conditions to filter objects
     * @param int     $offset Where to start retrieving from
     * @param int     $limit  How many objects to get
     * @return object List of objects for this class.
     */
    public static function getObjects(&$db, $where = array(), $offset = 0, $limit = 500) {
        $classname = __CLASS__;
        $table = $db->getTable(strtolower(get_class()));
        $res = $table->select('id', $where, 'AND', array('LIMIT' => $limit, 'OFFSET' => $offset));
        $rows = $table->result($res);
        $objs = array();
        while($row = $table->result($res)) {
            $objs[] = new $classname($db, $row);
        } return $objs;
    }
}
