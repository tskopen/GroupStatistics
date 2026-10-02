<?php
function initDataStore(){if(!is_dir(DATA_DIR))@mkdir(DATA_DIR,0775,true);if(!is_dir(IMAGES_DIR))@mkdir(IMAGES_DIR,0775,true);}
function iconUrl($icon){if(!$icon)return null;return 'image.php?file='.rawurlencode(basename((string)$icon));}
function readJson($file){if(!is_file($file))return []; $data=json_decode(file_get_contents($file),true);return is_array($data)?$data:[];}
function writeJson($file,$data){return file_put_contents($file,json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),LOCK_EX);}
