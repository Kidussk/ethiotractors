<?php
/**
 * MySQL connection settings — copy this file to config.php and edit.
 *
 * On the server (after first Git clone/pull):
 *   cp config.example.php config.php
 * Then set the values from Plesk → Databases.
 *
 * config.php is gitignored so deploys will not overwrite your live credentials.
 */

define('ET_DB_HOST', 'localhost');
define('ET_DB_PORT', '3306'); // Plesk / most hosts use 3306 (local XAMPP often uses 3307)
define('ET_DB_NAME', 'ethiotractors');
define('ET_DB_USER', 'ethiotractors_user');
define('ET_DB_PASS', 'CHANGE_ME');
