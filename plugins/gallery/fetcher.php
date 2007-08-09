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

##
## IMAGE FILE FETCHER
##

$plugins->attachHook('base_classes_initted', '
  global $paths;
    $paths->add_page(Array(
      \'name\'=>\'Image fetcher pagelet\',
      \'urlname\'=>\'GalleryFetcher\',
      \'namespace\'=>\'Special\',
      \'special\'=>0,\'visible\'=>0,\'comments_on\'=>0,\'protected\'=>1,\'delvotes\'=>0,\'delvote_ips\'=>\'\',
      ));
  ');

function page_Special_GalleryFetcher()
{
  global $db, $session, $paths, $template, $plugins; // Common objects
  
  $type = $paths->getParam(0);
  if ( !in_array($type, array('thumb', 'preview', 'full')) )
  {
    die('Hack attempt');
  }
  
  $id = intval($paths->getParam(1));
  if ( !$id )
  {
    die('Hack attempt');
  }
  
  // Permissions object
  $perms = $session->fetch_page_acl($id, 'Gallery');
  
  if ( !$perms->get_permissions('gal_full_res') && $type == 'full' )
  {
    $type = 'preview';
  }
  
  $q = $db->sql_query('SELECT img_title, img_filename, img_time_mod, is_folder FROM '.table_prefix.'gallery WHERE img_id=' . $id . ';');
  if ( !$q )
    $db->_die();
  
  if ( $db->numrows() < 1 )
    die('Image not found');
  
  $row = $db->fetchrow();
  
  switch ( $type )
  {
    case 'thumb':
      $filename = ENANO_ROOT . '/cache/' . $row['img_filename'] . '-thumb.jpg';
      $mimetype = 'image/jpeg';
      $ext = "jpg";
      break;
    case 'preview':
      $filename = ENANO_ROOT . '/cache/' . $row['img_filename'] . '-preview.jpg';
      $mimetype = 'image/jpeg';
      $ext = "jpg";
      break;
    case 'full':
      $filename = ENANO_ROOT . '/files/' . $row['img_filename'];
      $ext = get_file_extension($filename);
      switch($ext)
      {
        case 'png': $mimetype = 'image/png'; break;
        case 'gif': $mimetype = 'image/gif'; break;
        case 'bmp': $mimetype = 'image/bmp'; break;
        case 'jpg': case 'jpeg': $mimetype = 'image/jpeg'; break;
        case 'tif': case 'tiff': $mimetype = 'image/tiff'; break;
        default: $mimetype = 'application/octet-stream';
      }
      break;
    default:
      die('PHP...insane...');
      break;
  }
  
  // Make sure we have permission to read this image
  if ( !$perms->get_permissions('read') )
  {
    $filename = ENANO_ROOT . '/plugins/gallery/denied.png';
    $mimetype = 'image/png';
  }
  
  if ( $row['is_folder'] == '1' )
  {
    $filename = ENANO_ROOT . '/plugins/gallery/folder.png';
    $mimetype = 'image/png';
  }
  
  if ( !file_exists($filename) )
    die('Can\'t retrieve image file ' . $filename);
  
  $contents = file_get_contents($filename);
  
  header('Content-type: '   . $mimetype);
  header('Content-length: ' . strlen($contents));
  header('Last-Modified: '  . date('r', $row['img_time_mod']));
  
  if ( isset($_GET['download']) )
  {
    // determine an appropriate non-revealing filename
    $filename = str_replace(' ', '_', $row['img_title']);
    $filename = preg_replace('/([^\w\._-]+)/', '-', $filename);
    $filename = trim($filename, '-');
    $filename .= ".$ext";
    header('Content-disposition: attachment; filename=' . $filename);
  }
  
  echo $contents;
  
  gzip_output();
  
  $db->close();
  exit;
  
}

?>
