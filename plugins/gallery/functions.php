<?php

/*
 * Snapr
 * Version 0.1 beta 1
 * Copyright (C) 2007 Dan Fuhry
 *
 * This program is Free Software; you can redistribute and/or modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2 of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied
 * warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for details.
 */

/**
 * Generates a random filename for Snapr images.
 * @param int $length Optional - length of filename
 * @return string
 */
 
function gallery_make_filename($length = 24)
{
	$valid_chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
	$valid_chars = enano_str_split($valid_chars);
	$ret = '';
	for ( $i = 0; $i < $length; $i++ )
	{
		$ret .= $valid_chars[mt_rand(0, count($valid_chars)-1)];
	}
	return $ret;
}

/**
 * Returns the extension of a file.
 * @param string file
 * @return string
 */

function get_file_extension($file)
{
	return substr($file, ( strrpos($file, '.') + 1 ));
}

/**
 * For a given image ID, return the folder hierarchy.
 * @param int The image ID
 * @return array
 */

function gallery_imgid_to_folder($img_id)
{
	global $db, $session, $paths, $template, $plugins; // Common objects
	
	if ( !is_int($img_id) )
		return array();
	
	$img_id = strval($img_id);
	$ret = array();
	
	$sanity = 0;
	$sanity_stack = array();
	
	while(true)
	{
		$sanity++;
		$q = $db->sql_query('SELECT img_title, img_id, folder_parent FROM '.table_prefix.'gallery WHERE img_id=' . $img_id . ';');
		if ( !$q )
			$db->_die();
		$row = $db->fetchrow();
		if ( !$row )
		{
			break;
		}
		if ( $sanity > 1 )
		{
			$ret[] = $row['img_title'];
		}
		if ( !$row['folder_parent'] )
		{
			break;
		}
		if ( in_array($row['img_id'], $sanity_stack) )
			return array('Infinite loop');
		$sanity_stack[] = $row['img_id'];
		$img_id = $row['folder_parent'];
	}
	return $ret;
}

/**
 * Generates a hierarchy of Gallery folders.
 * @return array
 */

function gallery_folder_hierarchy()
{
	global $db, $session, $paths, $template, $plugins; // Common objects
	
	$q = $db->sql_query('SELECT img_id, img_title, folder_parent FROM '.table_prefix.'gallery WHERE is_folder=1');
	if ( !$q )
		$db->_die();
	
	if ( $db->numrows() < 1 )
	{
		return array('_id' => 'NULL');
	}
	
	$lookup_table = array();
	$hier = array('_id' => 'NULL');
	$orphans = array();
	$persist_orphans = array();
	
	while ( $row = $db->fetchrow() )
	{
		if ( !$row['folder_parent'] )
		{
			// root-level folder
			$hier[ $row['img_title'] ] = array('_id' => $row['img_id']);
			$lookup_table[$row['img_id']] =& $hier[ $row['img_title'] ];
		}
		else if ( $row['folder_parent'] && isset($lookup_table[$row['folder_parent']]) )
		{
			// child folder, parent is resolved
			$lookup_table[ $row['folder_parent'] ][ $row['img_title'] ] = array('_id' => $row['img_id']);
			$lookup_table[ $row['img_id'] ] =& $lookup_table[ $row['folder_parent'] ][ $row['img_title'] ];
		}
		else if ( $row['folder_parent'] && !isset($lookup_table[$row['folder_parent']]) )
		{
			// child folder, orphan as of yet
			$orphans[] = $row;
		}
	}
	
	// Resolve orphans
	do
	{
		$persist_orphans = array();
		while ( count($orphans) > 0 )
		{
			$orphan =& $orphans[ ( count($orphans) - 1 ) ];
			if ( isset($lookup_table[$orphan['folder_parent']]) )
			{
				$lookup_table[ $orphan['folder_parent'] ][ $orphan['img_title'] ] = array('_id' => $orphan['img_id']);
				$lookup_table[ $orphan['img_id'] ] =& $lookup_table[ $orphan['folder_parent'] ][ $orphan['img_title'] ];
			}
			else
			{
				$persist_orphans[] = $orphans[ ( count($orphans) - 1 ) ];
				//echo 'BUG: ' . htmlspecialchars($orphan['img_title']) . ' (' . $orphan['img_id'] . ') is an orphan folder (parent is ' . $orphan['folder_parent'] . '); placing in root<br />';
				// $hier[ $orphan['img_title'] ] = array();
				// $lookup_table[$orphan['img_id']] =& $hier[ $orphan['img_title'] ];
			}
			unset($orphan, $orphans[ ( count($orphans) - 1 ) ]);
		}
		$orphans = $persist_orphans;
		//die('insanity:<pre>'.print_r($hier,true).print_r($lookup_table,true).print_r($persist_orphans,true).'</pre>');
	}
	while ( count($persist_orphans) > 0 );
	
	return $hier;
	
}

/**
 * Generates HTML for a folder selector.
 * @param string The form field name, defaults to folder_id.
 * @param bool Whether to auto-select the root or not. Defaults to true.
 * @return string
 */

function gallery_hier_formfield($field_name = 'folder_id', $autosel = true)
{
	$hier = gallery_folder_hierarchy();
	$img_join      = scriptPath . '/images/icons/joinbottom.gif';
	$img_join_term = scriptPath . '/images/icons/join.gif';
	$img_line      = scriptPath . '/images/icons/line.gif';
	$img_empty     = scriptPath . '/images/icons/empty.gif';
	
	$html = _gallery_hier_form_inner($hier, '<Root>', $field_name, -1, array(), $img_join, $img_join_term, $img_line, $img_empty, $autosel);
	
	return $html;
}

// 

/**
 * Inner loop for form field generator (needs to call itself recursively)
 * @access private
 */

function _gallery_hier_form_inner($el, $name, $fname, $depth, $depth_img, $img_join, $img_join_term, $img_line, $img_empty, $sel = false)
{
	$html = '';
	foreach ( $depth_img as $sw )
		$html .= '<img alt="  " src="' . $sw . '" />';
	
	$html .= '<label><input ' . ( $sel ? 'checked="checked"' : '' ) . ' type="radio" name="' . $fname . '" value="' . $el['_id'] . '" /> ' . htmlspecialchars($name) . '</label><br />';
	
	if ( count($el) > 1 )
	{
		// Writing this image logic sucked.
		$count = 0;
		foreach ( $el as $key => $el_lower )
		{
			$count++;
			if ( $key == '_id' )
				continue;
			$depth_mod = $depth_img;
			$last = ( $count == count($el) );
			
			for ( $i = 0; $i < count($depth_mod); $i++ )
			{
				if ( $depth_mod[$i] == $img_join_term || $depth_mod[$i] == $img_empty )
					$depth_mod[$i] = $img_empty;
				else
					$depth_mod[$i] = $img_line;
			}
			
			if ( $last )
				$depth_mod[] = $img_join_term;
			else
				$depth_mod[] = $img_join;
			
			$html .= _gallery_hier_form_inner($el_lower, $key, $fname, ( $depth + 1 ), $depth_mod, $img_join, $img_join_term, $img_line, $img_empty);
		}
	}
	return $html;
}

/**
 * Returns an array containing the IDs of all of the given folder ID's children. Recursive function.
 * @param int ID of folder
 */

function gal_fetch_all_children($id)
{
	global $db, $session, $paths, $template, $plugins; // Common objects
	
	if ( !is_int($id) )
	{
		die('not int');
		return false;
	}
	
	$children = array();
	
	$q = $db->sql_query('SELECT img_id,is_folder FROM '.table_prefix.'gallery WHERE folder_parent=' . $id . ';');
	if ( !$q )
		$db->_die();
	if ( $db->numrows() < 1 )
	{
		return $children;
	}
	$folders = array();
	while ( $row = $db->fetchrow() )
	{
		$children[] = intval($row['img_id']);
		if ( $row['is_folder'] == 1 )
			$folders[] = intval($row['img_id']);
	}
	foreach ( $folders as $folder )
	{
		$grandchildren = gal_fetch_all_children($folder);
		if ( $grandchildren === false )
		{
			return false;
		}
		$children = array_merge($children, $grandchildren);
	}
	
	return $children;
	
}

/**
 * Lists all normal files within a given directory. Recursive function. Can also return the list of directories in the second parameter by reference.
 * @param string Directory to search
 * @param array Variable in which to store 
 * @return array Not multi-depth
 */

function gal_dir_recurse($dir, &$dirlist)
{
	$dir_handle = opendir($dir);
	if ( !$dir_handle )
		return false;
	$entries = array();
	$dirlist = array();
	while ( true )
	{
		$file = readdir($dir_handle);
		if ( !$file )
			break;
		if ( $file == '.' || $file == '..' )
			continue;
		$file = $dir . '/' . $file;
		if ( is_dir($file) )
		{
			$children = gal_dir_recurse($file, $dirtemp);
			$dirlist[] = $file;
			$dirlist = array_merge($dirlist, $dirtemp);
			$entries = array_merge($entries, $children);
		}
		else if ( is_file($file) )
		{
			$entries[] = $file;
		}
		else
		{
			die($file . ' is not a file or directory');
		}
	}
	closedir($dir_handle);
	return $entries;
}

/**
 * Wrapper for JSON decoding that works on Enano 1.0.x and 1.1.x
 * @param string JSON datastream...
 * @return mixed
 */

function snapr_json_decode($data)
{
	if ( defined('ENANO_ATLEAST_1_1') )
	{
		try
		{
			$decoded = enano_json_decode($data);
		}
		catch ( Exception $e )
		{
			$response = array(
				'mode' => 'error',
				'error' => 'Exception in JSON parser.'
			);
			die(enano_json_encode($response));
		}
	}
	else
	{
		$json = new Services_JSON(SERVICES_JSON_LOOSE_TYPE);
		$decoded = $json->decode($data);
	}
	return ( isset($decoded) ) ? $decoded : false;
}

/**
 * Wrapper for JSON encoding that works on Enano 1.0.x and 1.1.x
 * @param mixed Data to encode
 * @return string
 */

function snapr_json_encode($data)
{
	if ( defined('ENANO_ATLEAST_1_1') )
	{
		try
		{
			$encoded = enano_json_encode($data);
		}
		catch ( Exception $e )
		{
			$response = array(
				'mode' => 'error',
				'error' => 'Exception in JSON encoder.'
			);
			die(enano_json_encode($response));
		}
	}
	else
	{
		$json = new Services_JSON(SERVICES_JSON_LOOSE_TYPE);
		$encoded = $json->encode($data);
	}
	return ( isset($encoded) ) ? $encoded : false;
}

/**
 * Is the given file extension allowed?
 * @param string
 * @return bool
 */

function snapr_extension_allowed($ext)
{
	$allowedext = array('png', 'jpg', 'jpeg', 'tiff', 'tif', 'bmp', 'gif');
	return in_array(strtolower($ext), $allowedext);
}

/**
 * Process (make thumbnails for) an uploaded image.
 * @param int image_id
 * @return bool
 */

function snapr_process_image($image_id)
{
	global $db, $session, $paths, $template, $plugins; // Common objects
	
	$q = $db->sql_query('SELECT img_filename FROM ' . table_prefix . "gallery WHERE img_id = $image_id AND processed = 0 AND is_folder = 0;");
	if ( !$q )
		$db->_die();
	if ( $db->numrows() < 1 )
	{
		$db->free_result();
		return false;
	}
	list($filename) = $db->fetchrow_num($q);
	$db->free_result();
	
	$orig_path = ENANO_ROOT . "/files/$filename";
	$thumb = ENANO_ROOT . "/cache/$filename-thumb.jpg";
	$preview = ENANO_ROOT . "/cache/$filename-preview.jpg";
	
	// create thumbnail
	if ( !scale_image($orig_path, $thumb, 80, 80, true) )
		return false;
	// create preview
	if ( !scale_image($orig_path, $preview, 640, 1000, true) )
		return false;
	
	$q = $db->sql_query('UPDATE ' . table_prefix . "gallery SET processed = 1 WHERE img_id = $image_id;");
	if ( !$q )
		$db->_die();
	
	return true;
}

/**
 * Simple function to add an image to the database. Needs only the file path and the folder to put it in.
 * @param string Filename
 * @param int Folder, defaults to NULL (root)
 * @return int image ID
 */

function snapr_insert_image($path, $folder_id = NULL, $orig_filename = false)
{
	global $db, $session, $paths, $template, $plugins; // Common objects
	
	$usename = $orig_filename ? $orig_filename : $path;
	$ext = get_file_extension($usename);
	$ourfilename = gallery_make_filename() . "." . strtolower($ext);
	if ( !snapr_extension_allowed($ext) )
	{
		echo "Banned extension: $ext";
		return false;
	}
	
	// copy the file to the storage folder
	if ( !rename($path, ENANO_ROOT . "/files/$ourfilename") )
	{
		echo "Rename failed: $path -&gt; files/$ourfilename";
		return false;
	}
	
	// insert the image into the database
	$folder = $folder_id === NULL ? 'NULL' : strval(intval($folder_id));
	$title = ucwords(str_replace('_', ' ', $usename));
	$title = preg_replace("/\.{$ext}\$/i", '', $title);
	$sz = serialize(array());
	$now = time();
	$q = $db->sql_query('INSERT INTO ' . table_prefix . "gallery(is_folder, folder_parent, img_title, print_sizes, img_filename, img_time_upload, img_time_mod, img_tags, img_author, processed) VALUES\n"
		              . "	(0, $folder, '$title', '$sz', '$ourfilename', $now, $now, '[]', $session->user_id, 0);");
	if ( !$q )
		$db->_die();
	
	return $db->insert_id();
}

/**
 * Process an uploaded zip file.
 * @param string Zip file
 * @param int Folder ID, defaults to NULL (root)
 * @return array of image IDs
 */

function snapr_process_zip($path, $folder_id = NULL)
{
	error_reporting(E_ALL);

	if ( !mkdir(ENANO_ROOT . '/cache/temp') )
		return false;
	$temp_dir = tempnam(ENANO_ROOT . '/cache/temp', 'galunz');
	if ( file_exists($temp_dir) )
		unlink($temp_dir);
	@mkdir($temp_dir);
	
	// Extract the zip file
	if ( class_exists('ZipArchive') )
	{
		$zip = new ZipArchive();
		$op = $zip->open($file['tmp_name']);
		if ( !$op )
		{
			return false;
		}
		$op = $zip->extractTo($temp_dir);
		if ( !$op )
		{
			return false;
		}
	}
	else if ( file_exists('/usr/bin/unzip') )
	{
		$cmd = "/usr/bin/unzip -qq -d '$temp_dir' {$path}";
		system($cmd);
	}
	
	// Any files?
	$file_list = gal_dir_recurse($temp_dir, $dirs);
	if ( !$file_list )
	{
		return false;
	}
	if ( count($file_list) < 1 )
	{
		return false;
	}
	
	$dirs = array_reverse($dirs);
	$img_files = array();
	
	// Loop through and add files
	foreach ( $file_list as $file )
	{
		$ext = get_file_extension($file);
		
		if ( snapr_extension_allowed($ext) )
		{
			$img_files[] = $file;
		}
		else
		{
			unlink($file);
		}
	}
	
	// Main storage loop
	$results = array();
	foreach ( $img_files as $file )
	{
		$result = snapr_insert_image($file, $folder_id);
		if ( $result !== false )
			$results[] = $result;
	}
	
	// clean up
	foreach ( $dirs as $dir )
	{
		rmdir($dir);
	}
	
	if ( !rmdir( $temp_dir ) )
		return false;
	if ( !rmdir( ENANO_ROOT . '/cache/temp' ) )
		return false;
		
	return $results;
}
