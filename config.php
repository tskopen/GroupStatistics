<?php
/** Application bootstrap: constants, modules, migrations. */
define('DATA_DIR','/data');
if(!defined('IMAGES_DIR'))define('IMAGES_DIR',DATA_DIR.'/images');
if(!defined('DB_PATH'))define('DB_PATH',DATA_DIR.'/squadron-tracker.db');
require_once __DIR__.'/database-migrations.php';
require_once __DIR__.'/security.php';
require_once __DIR__.'/lib/database.php';
require_once __DIR__.'/lib/scoring.php';
require_once __DIR__.'/lib/auth.php';
require_once __DIR__.'/lib/storage.php';
initDataStore();
initDatabase();
