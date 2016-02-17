<?php
include dirname(__FILE__) . "/../../include/db.php";
include dirname(__FILE__) . "/../../include/general.php";
include dirname(__FILE__) . "/../../include/resource_functions.php";
include dirname(__FILE__) . "/../../include/image_processing.php";
require "../../vendor/autoload.php";
use Aws\S3\S3Client;

$sapi_type = php_sapi_name();
if (substr($sapi_type, 0, 3) != 'cli')
    {
    exit("Command line execution only.");
    }

if(isset($staticsync_userref))
    {
    # If a user is specified, log them in.
    $userref=$staticsync_userref;
    $userdata=get_user($userref);
    setup_user($userdata);
    }

ob_end_clean();
set_time_limit(60*60*40);

if ($argc == 2)
    {
    if ( in_array($argv[1], array('--help', '-help', '-h', '-?')) )
        {
        echo "To clear the lock after a failed run, ";
        echo "pass in '--clearlock', '-clearlock', '-c' or '--c'." . PHP_EOL;
        exit("Bye!");
        }
    else if ( in_array($argv[1], array('--clearlock', '-clearlock', '-c', '--c')) )
        {
        if ( is_process_lock("staticsync") )
            {
            clear_process_lock("staticsync");
            }
        }
    else
        {
        exit("Unknown argv: " . $argv[1]);
        }
    }

# Check for a process lock
if (is_process_lock("staticsync"))
    {
    echo 'Process lock is in place. Deferring.' . PHP_EOL;
    echo 'To clear the lock after a failed run use --clearlock flag.' . PHP_EOL;
    exit();
    }
set_process_lock("staticsync");

echo "Preloading data... ";
$count = 0;

$done = sql_array("SELECT file_path value FROM resource WHERE LENGTH(file_path)>0 AND file_path LIKE '%/%'");
$done = array_flip($done);

# Load all modification times into an array for speed
$modtimes = array();
$resource_modified_times = sql_query("SELECT file_modified, file_path FROM resource WHERE archive=0 AND LENGTH(file_path) > 0");
foreach ($resource_modified_times as $rmd)
    {
    $modtimes[$rmd["file_path"]] = $rmd["file_modified"];
    }

$lastsync = sql_value("SELECT value FROM sysvars WHERE name='lastsync'","");
$lastsync = (strlen($lastsync) > 0) ? strtotime($lastsync) : '';

echo "done." . PHP_EOL;
echo "Looking for changes..." . PHP_EOL;

# Pre-load the category tree, if configured.
if (isset($staticsync_mapped_category_tree))
    {
    $field = get_field($staticsync_mapped_category_tree);
    $tree  = explode("\n",trim($field["options"]));
    }

function touch_category_tree_level($path_parts)
    {
    # For each level of the mapped category tree field, ensure that the matching path_parts path exists
    global $staticsync_mapped_category_tree, $tree;

    $altered_tree  = false;
    $parent_search = 0;
    $nodename      = '';
    for ($n=0; $n<count($path_parts); $n++)
        {
        # The node name should contain all the subsequent parts of the path
        if ($n > 0) { $nodename .= "/"; }
        $nodename .= $path_parts[$n];

        # Look for this node in the tree.
        $found = false;
        for ($m=0; $m<count($tree); $m++)
            {
            $s = explode(",", $tree[$m]);
            #print_r($s);
            if ((count($s)==3) && ($s[1]==$parent_search) && $s[2]==$nodename)
                {
                # A match!
                $found = true;
                $parent_search = $m+1; # Search for this as the parent node on the pass for the next level.
                }
            }
        if (!$found)
            {
            echo "Not found: " . $nodename . " @ level " . $n  . PHP_EOL;
            # Add this node

            $tree[] = (count($tree)+1) . "," . $parent_search . "," . $nodename;
            $altered_tree = true;
            $parent_search = count($tree); # Search for this as the parent node on the pass for the next level.
            }
        }
    if ($altered_tree)
        {
        # Save the updated tree.
        $rtf_options = escape_check(join("\n", $tree));
        sql_query("UPDATE resource_type_field SET options='$rtf_options' WHERE ref='$staticsync_mapped_category_tree'");
        }
    }

function sync_to_s3($syncdir, $bucket, $aws_key, $aws_secret_key) {
  try {
    $s3Client = S3Client::factory(array( 
                  'key' => $aws_key,
                  'secret' => $aws_secret_key,
                  'region' => 'ap-southeast-1', 
                  'version' => '2006-03-01' ));

    $options = array(
      'concurrency' => 20
    );
    
    $status = $s3Client->uploadDirectory($syncdir, $bucket, $syncdir, $options);
    echo "Synced image to s3. " . $status . PHP_EOL;
    return true;
  } catch (\Aws\S3\Exception\S3Exception $e) {
      echo "Failed to sync images to s3." . PHP_EOL;
      echo $e->getMessage() . PHP_EOL;
      return false;
    }
}

function word_in_string($words, $string_array) {
   $word_array = explode(',', $words);
   $contains = array_intersect($word_array, $string_array);
   if(!empty($contains)) {
      return true;
   }
   return false;
}

//Make POST calls with data in $fields to the host $host.

function send_sync_status($host, $uri, $protocol="http", $fields=array()) {
	$curl_path = $protocol . '://' . $host . $uri;
	$fields_string = "";
	if(!empty($fields)) {
		foreach($fields as $key=>$value) {
			$fields_string .= $key.'='.$value.'&';
		}
		rtrim($fields_string, '&');
	}
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, $curl_path);
	curl_setopt($ch, CURLOPT_POST, count($fields));
	curl_setopt($ch, CURLOPT_POSTFIELDS, $fields_string);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	$response = curl_exec($ch);
	$httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	return $httpcode;
}

function get_image_dimension($fullpath, $type) {
    $im_identify_path = get_utility_path('im-identify');
    if ($type == "height") {
      $get_dimension_cmd = $im_identify_path . " -format '%[fx:h]' ";
    }
    elseif ($type == "width") {
      $get_dimension_cmd = $im_identify_path . " -format '%[fx:w]' ";
    }
    else {
      echo "The value of the type variable is incorrect. " . PHP_EOL;
    }
    
    $get_dimension_cmd .= $fullpath;

    $value = shell_exec($get_dimension_cmd);
    return $value;
}

function validate_image_size($fullpath, $image_height) {

    $height = get_image_dimension($fullpath, "height");
    if ($height !== $image_height) {
        return false;
    }
    
    $width = get_image_dimension($fullpath, "width");

    $aspect_ratio_array = explode('/', $fullpath);
    array_pop($aspect_ratio_array);
    array_pop($aspect_ratio_array);
    $aspect_ratio = array_pop($aspect_ratio_array);

    $multiply_values = explode('_', $aspect_ratio);
    $calculated_width = $height * $multiply_values[1] / $multiply_values[0];
   
    if ($width !== $calculated_width ) {
        return false;
    }

    return true;
}

function ProcessFolder($folder,$version_dir, &$resource_array, &$resource_error)
    {
    global $lang, $syncdir, $nogo, $staticsync_max_files, $count, $done, $modtimes, $lastsync, $ffmpeg_preview_extension,
           $staticsync_autotheme, $staticsync_folder_structure, $staticsync_extension_mapping_default,
           $staticsync_extension_mapping, $staticsync_mapped_category_tree, $staticsync_title_includes_path,
           $staticsync_ingest, $staticsync_mapfolders, $staticsync_alternatives_suffix, $theme_category_levels, $staticsync_defaultstate,
           $additional_archive_states,$staticsync_extension_mapping_append_values, $image_alternatives, $exclude_resize, $post_host, $media_endpoint,
           $image_required_height, $sync_bucket, $aws_key, $aws_secret_key;

    $collection = 0;

    echo "Processing Folder: $folder" . PHP_EOL;
    #$alt_path = get_resource_path(59, TRUE, '', FALSE, 'png', -1, 1, FALSE, '', 4);
    # List all files in this folder.
    $dh = opendir($folder);
    while (($file = readdir($dh)) !== false)
        {
        if ( $file == '.' || $file == '..')
            {
            continue;
            }
        $filetype  = filetype($folder . "/" . $file);
        $fullpath  = $folder . "/" . $file;
        $shortpath = str_replace($syncdir . "/", '', $fullpath);
        # Work out extension
        $extension = explode(".", $file);
        if(count($extension)>1)
            {
            $extension = trim(strtolower($extension[count($extension)-1]));
            }
        else
            {
            //No extension
            $extension="";
            }

        if(strpos($fullpath, $nogo)) {
            echo "This directory is to be ignored." . PHP_EOL;
            continue;
        }

        if ($staticsync_mapped_category_tree)
            {
            $path_parts = explode("/", $shortpath);
            array_pop($path_parts);
            touch_category_tree_level($path_parts);
            }

        # -----FOLDERS-------------
        if ((($filetype == "dir") || $filetype == "link") &&
            (strpos($nogo, "[$file]") === false) &&
            (strpos($file, $staticsync_alternatives_suffix) === false))
            {
            # Get current version direcotries.
            if (preg_match("/[0-9]{2}-[0-9]{2}-[0-9]{4}$/", $file)) {
              if(!in_array($file, $version_dir)) {
                    array_push($version_dir, $file);
              }

              if (preg_match('/in_progress*/', $file)) {
                   echo "The Barcode is still being processed." . PHP_EOL;
                   continue;
              }
            }
            # Recurse
            ProcessFolder($folder . "/" . $file,$version_dir, $resource_array, $resource_error);
            }

        $psd_files = array();

        if (preg_match('/images/', $fullpath)) {
          $path_array = explode('/', $fullpath);
          $psd_array = array_splice($path_array, 0 , array_search('images', $path_array));
          $psd_path = implode('/', $psd_array) . '/psd/';
          $psd_files = array_diff(scandir($psd_path), array('..', '.'));
          foreach ($psd_files as $index => $psd_file) {
            $psd_files[$index] = pathinfo($psd_file, PATHINFO_FILENAME);
          }
        }

        # -------FILES---------------
        if (($filetype == "file") && (substr($file,0,1) != ".") && (strtolower($file) != "thumbs.db"))
            {
            /* Below Code Adapted  from CMay's bug report */
            global $banned_extensions;
            # Check to see if extension is banned, do not add if it is banned
            if(array_search($extension, $banned_extensions)){continue;}
            /* Above Code Adapted from CMay's bug report */

            $count++;
            if ($count > $staticsync_max_files) { return(true); }

            $last_sync_date = sql_value("select value from sysvars where name = 'last_sync'", "");
            $file_creation_date = date("Y-m-d H:i:s" , filectime($fullpath));
            if ((isset($last_sync_date)) && $last_sync_date > $file_creation_date)
            {
                echo "No new file found.." . PHP_EOL;
                continue;
            }
            # Already exists?
            if (!isset($done[$shortpath]))
                {
                echo "Processing file: $fullpath" . PHP_EOL;

                if ($collection == 0 && $staticsync_autotheme)
                    {
                    # Make a new collection for this folder.
                    $e = explode("/", $shortpath);
                    $theme        = ucwords($e[0]);
                    $themesql     = "theme='" . ucwords(escape_check($e[0])) . "'";
                    $themecolumns = "theme";
                    $themevalues  = "'" . ucwords(escape_check($e[0])) . "'";

                    if ($staticsync_folder_structure)
                        {
                        for ($x=0;$x<count($e)-1;$x++)
                            {
                            if ($x != 0)
                                {
                                $themeindex = $x+1;
                                if ($themeindex >$theme_category_levels)
                                    {
                                    $theme_category_levels = $themeindex;
                                    if ($x == count($e)-2)
                                        {
                                        echo PHP_EOL . PHP_EOL .
                                             "UPDATE THEME_CATEGORY_LEVELS TO $themeindex IN CONFIG!!!!" .
                                             PHP_EOL . PHP_EOL;
                                        }
                                    }
                                $th_name       = ucwords(escape_check($e[$x]));
                                $themesql     .= " AND theme{$themeindex} = '$th_name'";
                                $themevalues  .= ",'$th_name'";
                                $themecolumns .= ",theme{$themeindex}";
                                }
                            }
                        }

                    $name = (count($e) == 1) ? '' : $e[count($e)-2];
                    echo "Collection $name, theme=$theme" . PHP_EOL;
                    $ul_username = $theme;
                    $escaped_name = escape_check($name);
                    $collection = sql_value("SELECT ref value FROM collection WHERE name='$escaped_name' AND $themesql", 0);
                    if ($collection == 0)
                        {
                        sql_query("INSERT INTO collection (name,created,public,$themecolumns,allow_changes)
                                                   VALUES ('$escaped_name', NOW(), 1, $themevalues, 0)");
                        $collection = sql_insert_id();
                        }
                    }

                # Work out a resource type based on the extension.
                $type = $staticsync_extension_mapping_default;
                reset($staticsync_extension_mapping);
                foreach ($staticsync_extension_mapping as $rt => $extensions)
                    {
                    if (in_array($extension,$extensions)) { $type = $rt; }
                    }
                $modified_type = hook('modify_type', 'staticsync', array( $type ));
                if (is_numeric($modified_type)) { $type = $modified_type; }

                # Formulate a title
                if ($staticsync_title_includes_path)
                    {
                    $title_find = array('/',   '_', ".$extension" );
                    $title_repl = array(' - ', ' ', '');
                    $title      = ucfirst(str_ireplace($title_find, $title_repl, $shortpath));
                    }
                else
                    {
                    $title = str_ireplace(".$extension", '', $file);
                    }
                $modified_title = hook('modify_title', 'staticsync', array( $title ));
                if ($modified_title !== false) { $title = $modified_title; }

                # Import this file
                #$r = import_resource($shortpath, $type, $title, $staticsync_ingest);
                #Check for file name containing the psd.

                if(!empty($psd_files)) {
                  $image_file_array = explode('/', $fullpath);
                  $image_file = $image_file_array[count($image_file_array)-1];
                  $image_psd_name = explode('_', $image_file)[0];
                  if(array_search($image_psd_name, $psd_files)) {
                    #Image name is in right format.
                    if (!validate_image_size($fullpath, $image_required_height)) {
                     $resource_error['size'][$file] =  $fullpath;
                    }
                    $r = import_resource($fullpath, $type, $title, $staticsync_ingest);

                    sql_query("INSERT INTO resource_data (resource,resource_type_field,value)
                               VALUES ('$r', (SELECT ref FROM resource_type_field WHERE name = 'logical_id'), '$image_psd_name')");

                    $original_filepath = sql_query("SELECT value FROM resource_data WHERE resource = '$r' AND
                                                     resource_type_field = (SELECT ref FROM resource_type_field where name = 'original_filepath')");
                    if (isset($original_filepath)) {
                      sql_query("INSERT INTO resource_data (resource,resource_type_field,value)
                                 VALUES ('$r',(SELECT ref FROM resource_type_field WHERE name = 'original_filepath'), '$fullpath')");
                     }
                  }
                  else {
                    echo "Filename '$fullpath' is not in right format.." . PHP_EOL;
                    $resource_error['name'][$file] =  $fullpath;
                    continue;
                  }
                }
                elseif(word_in_string($exclude_resize, explode('/', $fullpath))) {
                  $r = import_resource($fullpath, $type, $title, $staticsync_ingest);
                }


                if ($r !== false)
                    {
                    array_push($resource_array, $r);
                    # Create current version for resource.
                    #print_r($version_dir);
                    if(count($version_dir) == 1) {
                        sql_query("INSERT into resource_data (resource,resource_type_field,value)
                                    VALUES ('$r',(SELECT ref FROM resource_type_field WHERE name = 'current'), 'TRUE')");
                    }

                    $sync_status = sync_to_s3($syncdir, $sync_bucket, $aws_key, $aws_secret_key);
                    if (!$sync_status) { echo "Failed to sync"; }
                    # Add to mapped category tree (if configured)
                    if (isset($staticsync_mapped_category_tree))
                        {
                        $basepath = '';
                        # Save tree position to category tree field

                        # For each node level, expand it back to the root so the full path is stored.
                        for ($n=0;$n<count($path_parts);$n++)
                            {
                            if ($basepath != '')
                                {
                                $basepath .= "~";
                                }
                            $basepath .= $path_parts[$n];
                            $path_parts[$n] = $basepath;
                            }

                        update_field($r, $staticsync_mapped_category_tree, "," . join(",", $path_parts));
                        }

                    #This is an override to add user data to the resouces
                    if(!isset($userref))
                        {
                            $ul_username = ucfirst(strtolower($ul_username));
                            $current_user_ref = sql_query("Select ref from user where username = '$ul_username' ");
                            if(!empty($current_user_ref))
                            {
                                $current_user_ref = $current_user_ref[0]['ref'];
                                sql_query("UPDATE resource SET created_by='$current_user_ref' where ref = $r");
                            }
                        }

                    # default access level. This may be overridden by metadata mapping.
                    $accessval = 0;

                    # StaticSync path / metadata mapping
                    # Extract metadata from the file path as per $staticsync_mapfolders in config.php
                    if (isset($staticsync_mapfolders))
                        {
                        foreach ($staticsync_mapfolders as $mapfolder)
                            {
                            $match = $mapfolder["match"];
                            $field = $mapfolder["field"];
                            $level = $mapfolder["level"];

                            if (strpos("/" . $shortpath, $match) !== false)
                                {
                                # Match. Extract metadata.
                                $path_parts = explode("/", $shortpath);
                                if ($level < count($path_parts))
                                    {
                                    // special cases first.
                                    if ($field == 'access')
                                        {
                                        # access level is a special case
                                        # first determine if the value matches a defined access level

                                        $value = $path_parts[$level-1];

                                        for ($n=0; $n<3; $n++){
                                            # if we get an exact match or a match except for case
                                            if ($value == $lang["access" . $n] || strtoupper($value) == strtoupper($lang['access' . $n]))
                                                {
                                                $accessval = $n;
                                                echo "Will set access level to " . $lang['access' . $n] . " ($n)" . PHP_EOL;
                                                }
                                            }

                                        }
                                    else if ($field == 'archive')
										{
										# archive level is a special case
										# first determin if the value matches a defined archive level

										$value = $mapfolder["archive"];
										$archive_array=array_merge(array(-2,-1,0,1,2,3),$additional_archive_states);

										if(in_array($value,$archive_array))
											{
											$archiveval = $value;
											echo "Will set archive level to " . $lang['status' . $value] . " ($archiveval)". PHP_EOL;
											}

										}
                                    else
                                        {
                                        # Save the value
                                        #print_r($path_parts);
                                        $value = $path_parts[$level-1];

                                        if($staticsync_extension_mapping_append_values){
											$given_value=$value;
											// append the values if possible...not used on dropdown, date, categroy tree, datetime, or radio buttons
											$field_info=get_resource_type_field($field);
											if(in_array($field['type'],array(0,1,2,4,5,6,7,8))){
												$old_value=sql_value("select value value from resource_data where resource=$r and resource_type_field=$field","");
												$value=append_field_value($field_info,$value,$old_value);
											}
										}

                                        update_field ($r, $field, trim($value));
                                        if(strtotime(trim($value))) {
                                          add_keyword_mappings($r, trim($value), $field, false, true);
                                        }
                                        else {
                                          add_keyword_mappings($r, trim($value), $field);
                                        }
                                        if($staticsync_extension_mapping_append_values){
											$value=$given_value;
										}

                                        echo " - Extracted metadata from path: $value" . PHP_EOL;
                                        }
                                    }
                                }
                            }
                        }

                    #Resize only original images.
                    if (!word_in_string($exclude_resize, explode('/', $fullpath))) {
                      echo "Creating preview..";
                      create_previews($r, false, $extension, false, false, -1, false, $staticsync_ingest);
                    }
                    # update access level
                    sql_query("UPDATE resource SET access = '$accessval',archive='$staticsync_defaultstate' WHERE ref = '$r'");

                    # Add any alternative files
                    $altpath = $fullpath . $staticsync_alternatives_suffix;
                    if ($staticsync_ingest && file_exists($altpath))
                        {
                        $adh = opendir($altpath);
                        while (($altfile = readdir($adh)) !== false)
                            {
                            $filetype = filetype($altpath . "/" . $altfile);
                            if (($filetype == "file") && (substr($file,0,1) != ".") && (strtolower($file) != "thumbs.db"))
                                {
                                # Create alternative file
                                # Find extension
                                $ext = explode(".", $altfile);
                                $ext = $ext[count($ext)-1];

                                $description = str_replace("?", strtoupper($ext), $lang["originalfileoftype"]);
                                $file_size   = filesize_unlimited($altpath . "/" . $altfile);

                                $aref = add_alternative_file($r, $altfile, $description, $altfile, $ext, $file_size);
                                $path = get_resource_path($r, true, '', true, $ext, -1, 1, false, '', $aref);
                                rename($altpath . "/" . $altfile,$path); # Move alternative file
                                }
                            }
                        }

                    # Add to collection
                    if ($staticsync_autotheme)
                        {
                        $test = '';
                        $test = sql_query("SELECT * FROM collection_resource WHERE collection='$collection' AND resource='$r'");
                        if (count($test) == 0)
                            {
                            sql_query("INSERT INTO collection_resource (collection, resource, date_added)
                                            VALUES ('$collection', '$r', NOW())");
                            }
                        }
                    }
                else
                    {
                    # Import failed - file still being uploaded?
                    echo " *** Skipping file - it was not possible to move the file (still being imported/uploaded?)" . PHP_EOL;
                    }
                }
            else
                {
                # check modified date and update previews if necessary
                $filemod = filemtime($fullpath);
                if (array_key_exists($shortpath,$modtimes) && ($filemod > strtotime($modtimes[$shortpath])))
                    {
                    # File has been modified since we last created previews. Create again.
                    $rd = sql_query("SELECT ref, has_image, file_modified, file_extension FROM resource
                                        WHERE file_path='" . escape_check($shortpath) . "'");
                    if (count($rd) > 0)
                        {
                        $rd   = $rd[0];
                        $rref = $rd["ref"];

                        echo "Resource $rref has changed, regenerating previews: $fullpath" . PHP_EOL;
                        extract_exif_comment($rref,$rd["file_extension"]);

                        # extract text from documents (e.g. PDF, DOC).
                        global $extracted_text_field;
                        if (isset($extracted_text_field)) {
                            if (isset($unoconv_path) && in_array($extension,$unoconv_extensions)){
                                // omit, since the unoconv process will do it during preview creation below
                                }
                            else {
                            extract_text($rref,$extension);
                            }
                        }

                        # Store original filename in field, if set
                        global $filename_field;
                        if (isset($filename_field)) {
                            update_field($rref,$filename_field,$file);
                        }

                        create_previews($rref, false, $rd["file_extension"], false, false, -1, false, $staticsync_ingest);
                        sql_query("UPDATE resource SET file_modified=NOW() WHERE ref='$rref'");
                        }
                    }
                }
            }
        }
    }

# Recurse through the folder structure.

$resource_error = array();
$resource_array = array();
$version_dir = array();
try {
  ProcessFolder($syncdir, $version_dir, $resource_array, $resource_error);
} catch (Exception $e) {
    echo "Failed to sync the images due to : ", $e->getMessage() . PHP_EOL;
} finally {
    $last_sync = sql_query("select name from sysvars where name = 'last_sync'");
    if (empty($last_sync)) {
      sql_query("insert into sysvars(name,value) values('last_sync', (select creation_date from resource order by ref desc limit 1))");
    }
    else {
      sql_query("update sysvars set value = (select creation_date from resource order by ref desc limit 1) where name = 'last_sync'");
    }
    
    $resources = join('\',\'', $resource_array);
    
    $barcodes = sql_query("select distinct value from resource_data where resource_type_field =
                          (select ref from resource_type_field where name = 'barcode') and resource in ('$resources')");
    if (!empty($barcodes)) {
      foreach($barcodes as $index => $barcode) {
        $uri = $media_endpoint . $barcode['value'] . '/refresh.json';
        $response = send_sync_status($post_host, $uri);
        if ($response == 200 ) {
          echo "Sent the response of " . $barcode['value'] . " barcode to media service" . PHP_EOL;
        }
        else {
          echo "The barcode update call to media service failed. For barcode " . $barcode['value'] . PHP_EOL;
        }
      }
    }

    foreach ($resource_error as $type_error => $errors) {
      $subject = "Errors during syncing due to image ." . $type_error;
      $message = "";
      $message = '<html><body>';
      $message .= '<table width="100%"; rules="all" style="border:1px solid #3A5896;" cellpadding="10">';
      $message .= "<tr><th>" . "Index" . "</th><th>" . "Filename" . "</th><th>" . "Filepath" . "</th></tr>";

      if ($type_error == "size") {
        $subject = "Errors during syncing due to incorrect image dimensions.";
        $message = "";
        $message = '<html><body>';
        $message .= '<table width="100%"; rules="all" style="border:1px solid #3A5896;" cellpadding="10">';
        $message .= "<tr><th>" . "Index" . "</th><th>" . "Filename" . "</th><th>" . "Filepath" . "</th><th>" . "Image Size" . "</th></tr>";
      }

      $i = 1;
        foreach ($errors as $index => $value) {
          if ($type_error == "name") {
            $message .= "<tr><td>" . $i . "</td><td>" . $index . "</td><td>" . $value . "</td></tr>";
          }
          elseif ($type_error == "size") {
            $height = get_image_dimension($value, "height");
            $width = get_image_dimension($value, "width");
            $message .= "<tr><td>" . $i . "</td><td>" . $index . "</td><td>" . $value . "</td><td>" . "$width x $height" . "</td></tr>" ;
          }
          $i += 1;
        }
      $message .= "</table>";
      $message .= "</body></html>";
      $email_status = send_mail($error_email_list, $subject, $message);
      if(!$email_status) { echo "Failed to send the mail. " . PHP_EOL; }
    }
}
 
if (!$staticsync_ingest)
    {
    # If not ingesting files, look for deleted files in the sync folder and archive the appropriate file from ResourceSpace.
    echo "Looking for deleted files..." . PHP_EOL;
    # For all resources with filepaths, check they still exist and archive if not.
    $resources_to_archive = sql_query("SELECT ref,file_path FROM resource WHERE archive=0 AND LENGTH(file_path)>0 AND file_path LIKE '%/%'");

    # ***for modified syncdir directories:
    $syncdonemodified = hook("modifysyncdonerf");
    if (!empty($syncdonemodified)) { $resources_to_archive = $syncdonemodified; }

    foreach ($resources_to_archive as $rf)
        {
        $fp = $syncdir . "/" . $rf["file_path"];

        # ***for modified syncdir directories:
        if (isset($rf['syncdir']) && $rf['syncdir'] != '')
            {
            $fp = $rf['syncdir'].$rf["file_path"];
            }

        if (!file_exists($fp))
            {
            echo "File no longer exists: {$rf["ref"]} ($fp)" . PHP_EOL;
            # Set to archived.
            sql_query("UPDATE resource SET archive=2 WHERE ref='{$rf["ref"]}'");
            sql_query("DELETE FROM collection_resource WHERE resource='{$rf["ref"]}'");
            }
        }
    # Remove any themes that are now empty as a result of deleted files.
    sql_query("DELETE FROM collection WHERE theme IS NOT NULL AND LENGTH(theme) > 0 AND
                (SELECT count(*) FROM collection_resource cr WHERE cr.collection=collection.ref) = 0;");

    echo "...Complete" . PHP_EOL;
    }

sql_query("UPDATE sysvars SET value=now() WHERE name='lastsync'");

clear_process_lock("staticsync");
