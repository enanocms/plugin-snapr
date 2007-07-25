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
## UPLOAD INTERFACE
##

$plugins->attachHook('base_classes_initted', '
  global $paths;
    $paths->add_page(Array(
      \'name\'=>\'Image gallery upload\',
      \'urlname\'=>\'GalleryUpload\',
      \'namespace\'=>\'Special\',
      \'special\'=>0,\'visible\'=>1,\'comments_on\'=>0,\'protected\'=>1,\'delvotes\'=>0,\'delvote_ips\'=>\'\',
      ));
  ');

function page_Special_GalleryUpload()
{
  global $db, $session, $paths, $template, $plugins; // Common objects
  
  if ( $session->user_level < USER_LEVEL_ADMIN )
  {
    die_friendly('Access denied', '<p>You need to have administrative rights to use the gallery\'s upload features.</p>');
  }
  
  $zip_support = ( class_exists('ZipArchive') || ( file_exists('/usr/bin/unzip') && is_executable('/usr/bin/unzip') ) );
  
  $errors = array();
  $template->add_header('<link rel="stylesheet" type="text/css" href="' . scriptPath . '/plugins/gallery/dropdown.css" />');
  $template->add_header('<script type="text/javascript" src="' . scriptPath . '/plugins/gallery/gallery-bits.js"></script>');
  
  $max_size = @ini_get('upload_max_filesize');
  $max_size_field = '';
  if ( $max_size )
  {
    if ( preg_match('/M$/i', $max_size) )
    {
      $max_size = intval($max_size) * 1048576;
    }
    else if ( preg_match('/K$/i', $max_size) )
    {
      $max_size = intval($max_size) * 1024;
    }
    else if ( preg_match('/G$/i', $max_size) )
    {
      $max_size = intval($max_size) * 1048576 * 1024;
    }
    $max_size = intval($max_size);
    $max_size_field = "\n" . '<input type="hidden" name="MAX_FILE_SIZE" value="' . $max_size . '" />' . "\n";
  }
  
  if ( isset($_GET['edit_img']) )
  {
    $edit_parms = $_GET['edit_img'];
    $regex = '/^((([0-9]+),)*)?([0-9]+?)$/';
    if ( !preg_match($regex, $edit_parms) )
    {
      die_friendly('Bad request', '<p>$_GET[\'edit_img\'] must be a comma-separated list of image IDs.</p>');
    }
    
    $idlist = explode(',', $edit_parms);
    $num_edit = count($idlist);
    $idlist = "SELECT img_id,img_title,img_desc,img_filename,is_folder FROM ".table_prefix."gallery WHERE img_id=" . implode(' OR img_id=', $idlist) . ';';
    
    if ( !$e = $db->sql_query($idlist) )
      $db->_die();
    
    $template->header();
    
    if ( isset($_POST['edit_do_save']) )
    {
      @set_time_limit(0);
      
      $arr_img_data = array();
      while ( $row = $db->fetchrow($e) )
        $arr_img_data[$row['img_id']] = $row;
      
      // Allow breaking out
      switch(true):case true:
        
        if ( !is_array($_POST['img']) )
        {
          $errors[] = 'No images passed to processor.';
          break;
        }
        
        // Main updater loop
        foreach ( $_POST['img'] as $img_id => $img_data )
        {
          
          if ( !preg_match('/^[0-9]+$/', $img_id) )
          {
            $errors[] = 'SQL injection attempted!';
            break 2;
          }
          
          // Array of columns to update
          $to_update = array();
          
          $key = 'reupload_' . $img_data['id'];
          if ( isset($_FILES[$key]) )
          {
            $file =& $_FILES[ $key ];
            if ( $file['tmp_name'] != '' )
            {
              // Reupload
              $filename = ENANO_ROOT . '/files/' . $arr_img_data[ $img_data['id'] ]['img_filename'];
              if ( !unlink($filename) )
              {
                $errors[] = "Could not delete $filename";
                break 2;
              }
              if ( !@move_uploaded_file($file['tmp_name'], $filename) )
              {
                $errors[] = "Could not move uploaded file to $filename";
                break 2;
              }
              
              //
              // Create scaled images
              //
              
              // Create thumbnail image
              $thumb_filename = ENANO_ROOT . '/cache/' . $arr_img_data[ $img_data['id'] ]['img_filename'] . '-thumb.jpg';
              if ( !unlink($thumb_filename) )
              {
                $errors[] = "Could not delete $thumb_filename";
                break 2;
              }
              $magick = getConfig('imagemagick_path');
              $command = "$magick '{$filename}' -resize ".'"'."80x80>".'"'." -quality 85 $thumb_filename";
              
              @system($command, $stat);
              
              if ( !file_exists($thumb_filename) )
              {
                $errors[] = 'Couldn\'t scale image '.$i.': ImageMagick failed us';
                break 2;
              }
              
              // Create preview image
              $preview_filename = ENANO_ROOT . '/cache/' . $arr_img_data[ $img_data['id'] ]['img_filename'] . '-preview.jpg';
              if ( !unlink($preview_filename) )
              {
                $errors[] = "Could not delete $preview_filename";
                break 2;
              }
              $magick = getConfig('imagemagick_path');
              $command = "$magick '{$filename}' -resize ".'"'."640x640>".'"'." -quality 85 $preview_filename";
              
              @system($command, $stat);
              
              if ( !file_exists($preview_filename) )
              {
                $errors[] = 'Couldn\'t scale image '.$i.': ImageMagick failed us';
                break 2;
              }
              $to_update['img_time_mod'] = strval(time());
            }
          }
          
          $vars = array(
            'year' => date('Y'),
            'month' => date('F'),
            'day' => date('d'),
            'time12' => date('g:i A'),
            'time24' => date('G:i')
          );
          
          // Image name/title
          
          $title = $template->makeParserText($img_data['title']);
          $title->assign_vars($vars);
          $executed = $title->run();
          if ( $executed == '_id' )
          {
            $errors[] = 'You cannot name an image or folder "_id", this name is reserved for internal functions.';
            break 2;
          }
          if ( $executed == '' )
          {
            $errors[] = 'Please enter a name for the item with unique ID ' . $img_data['id'] . '. <pre>' . print_r($_POST,true) . '</pre>';
            break 2;
          }
          $to_update['img_title'] = $executed;
          
          // Image description
          
          if ( isset($img_data['desc']) )
          {
            $desc = $template->makeParserText($img_data['desc']);
            $desc->assign_vars($vars);
            $executed = $desc->run();
            $executed = RenderMan::preprocess_text($executed, false, false);
            $to_update['img_desc'] = $executed;
          }
          
          // Folder
          $target_folder = false;
          
          if ( !empty($_POST['override_folder']) )
          {
            if ( $_POST['override_folder'] == 'NULL' || preg_match('/^[0-9]+$/', $_POST['override_folder']) )
            {
              $target_folder = $_POST['override_folder'];
            }
          }
          
          if ( !empty($img_data['folder']) )
          {
            if ( $img_data['folder'] == 'NULL' || preg_match('/^[0-9]+$/', $img_data['folder']) )
            {
              $target_folder = $img_data['folder'];
            }
          }
          
          if ( $target_folder )
          {
            $to_update['folder_parent'] = $target_folder;
          }
          
          if ( count($to_update) > 0 )
          {
            $up_keys = array_keys($to_update);
            $up_vals = array_values($to_update);
            
            $bin_cols = array('folder_parent');
            
            $sql = 'UPDATE ' . table_prefix.'gallery SET ';
            
            foreach ( $up_keys as $i => $key )
            {
              if ( in_array($key, $bin_cols) )
              {
                $sql .= $key . '=' . $up_vals[$i] . ',';
              }
              else
              {
                $sql .= $key . '=\'' . $db->escape($up_vals[$i]) . '\',';
              }
            }
            
            $sql = preg_replace('/,$/i', '', $sql) . ' WHERE img_id=' . $img_data['id'] . ';';
            
            if ( !$db->sql_query($sql) )
            {
              $db->_die();
            }
            
          }
          
        }
        
        echo '<div class="info-box" style="margin-left: 0;">Your changes have been saved.</div>';
        
      endswitch;
      
      // Rerun select query to make sure information in PHP memory is up-to-date
      if ( !$e = $db->sql_query($idlist) )
        $db->_die();
      
    }
    
    if ( count($errors) > 0 )
    {
      echo '<div class="error-box" style="margin-left: 0;">
              <b>The following errors were encountered while updating the image data:</b><br />
              <ul>
                <li>' . implode("</li>\n        <li>", $errors) . '</li>
              </ul>
            </div>';
    }
    
    ?>
    <script type="text/javascript">
    
      function gal_unset_radios(name)
      {
        var radios = document.getElementsByTagName('input');
        for ( var i = 0; i < radios.length; i++ )
        {
          var radio = radios[i];
          if ( radio.name == name )
          {
            radio.checked = false;
          }
        }
      }
    
    </script>
    <?php
    
    echo '<form action="' . makeUrlNS('Special', 'GalleryUpload', 'edit_img=' . $edit_parms, true) . '" method="post" enctype="multipart/form-data">';
    
    echo $max_size_field;
    
    if ( $row = $db->fetchrow($e) )
    {
      
      echo '<div class="tblholder">
              <table border="0" cellspacing="1" cellpadding="4">';
      echo '    <tr><th class="subhead">Information</th></tr>';
      echo '    <tr><td class="row3">
                  As with the upload form, the following variables can be used. <b>Note that when editing images, the {id} and {autotitle} variables will be ignored.</b>';
      ?>
          <ul>
            <li>{year}: The current year (<?php echo date('Y'); ?>)</li>
            <li>{month}: The current month (<?php echo date('F'); ?>)</li>
            <li>{day}: The day of the month (<?php echo date('d'); ?>)</li>
            <li>{time12}: 12-hour time (<?php echo date('g:i A'); ?>)</li>
            <li>{time24}: 24-hour time (<?php echo date('G:i'); ?>)</li>
          </ul>
      <?php
      echo '        </td></tr>';
      echo '  </table>
            </div>';
      
      $i = 0;
      do
      {
        $thumb_url = makeUrlNS('Special', 'GalleryFetcher/thumb/' . $row['img_id'], false, true);
        
        # Type: folder
        if ( $row['is_folder'] == 1 ):
        
        // Image ID tracker
        echo '<input type="hidden" name="img[' . $i . '][id]" value="' . $row['img_id'] . '" />';
        
        //
        // Editor table
        //
        
        $folders = gallery_imgid_to_folder(intval($row['img_id']));
        foreach ( $folders as $j => $xxx )
        {
          $folder =& $folders[$j];
          $folder = sanitize_page_id($folder);
        }
        $folders = array_reverse($folders);
        $gal_href = implode('/', $folders) . '/' . sanitize_page_id($row['img_title']);
        
        echo '<div class="tblholder">
                <table border="0" cellspacing="1" cellpadding="4">';
        
        echo '<tr><th colspan="2">Folder: ' . htmlspecialchars($row['img_title']) . '</th></tr>';
        
        // Primary key
        echo '<tr>
                <td class="row2">Unique ID:</td>
                <td class="row1">' . $row['img_id'] . ' (<a href="' . makeUrlNS('Special', 'Gallery/' . $gal_href) . '">view folder contents</a>)</td>
              </tr>';
              
        // Path info
        echo '<tr>
                <td class="row2">Parent folders:</td>
                <td class="row1">' . /* Yeah it's dirty, but hey, it gets the job done ;-) */ ( ( $x = str_replace('&amp;raquo;', '&raquo;', htmlspecialchars(str_replace('_', ' ', implode(' &raquo; ', $folders)))) ) ? $x : '&lt;in root&gt;' ) . '</td>
              </tr>';
        
        // Image name
        
        echo '<tr>
                <td class="row2">Folder name:</td>
                <td class="row1"><input type="text" style="width: 98%;" name="img[' . $i . '][title]" value="' . htmlspecialchars($row['img_title']) . '" size="43" /></td>
              </tr>';
              
        // Mover widget
        ?>
        <tr>
          <td class="row2">Move to folder:</td>
          <td class="row1">
            <div class="toggle">
              <div class="head" onclick="gal_toggle(this.nextSibling.nextSibling, this.childNodes[1]);">
                <img alt="&gt;&gt;" src="<?php echo scriptPath; ?>/plugins/gallery/toggle-closed.png" class="toggler" />
                Select folder
              </div>
              <div class="body">
                <?php
                  echo gallery_hier_formfield('img[' . $i . '][folder]', false);
                ?>
                <br />
                <a href="#" onclick="gal_unset_radios('img[<?php echo $i; ?>][folder]'); return false;">Unselect field</a>
              </div>
            </div>
          </td>
        </tr>
        <?php
        
        // Finish table
        echo '</table>';
        echo '</div>';
        
        # Type: image
        else:
        
        // Image ID tracker
        echo '<input type="hidden" name="img[' . $i . '][id]" value="' . $row['img_id'] . '" />';
        
        //
        // Editor table
        //
        
        echo '<div class="tblholder">
                <table border="0" cellspacing="1" cellpadding="4">';
        
        echo '<tr><th colspan="2">Image: ' . htmlspecialchars($row['img_title']) . '</th></tr>';
        
        // Primary key
        echo '<tr>
                <td class="row2">Unique ID:</td>
                <td class="row1">' . $row['img_id'] . ' (<a href="' . makeUrlNS('Gallery', $row['img_id']) . '">view image\'s page</a>)</td>
              </tr>';
              
        // Thumbnail
        
        echo '<tr>
                <td class="row2">Thumbnail:</td>
                <td class="row1"><img alt="Thumbnail image" src="' . $thumb_url . '" /></td>
              </tr>';
        
        // Image name
        
        echo '<tr>
                <td class="row2">Image title:</td>
                <td class="row1"><input type="text" style="width: 98%;" name="img[' . $i . '][title]" value="' . htmlspecialchars($row['img_title']) . '" size="43" /></td>
              </tr>';
              
        // Image description
        
        echo '<tr>
                <td class="row2">Image description:</td>
                <td class="row1"><textarea rows="10" cols="40" style="width: 98%;" name="img[' . $i . '][desc]">' . htmlspecialchars($row['img_desc']) . '</textarea></td>
              </tr>';
              
        // ACL editor trigger
        
        echo '<tr>
                <td class="row2">Permissions:</td>
                <td class="row1"><input type="button" onclick="ajaxOpenACLManager(\'' . $row['img_id'] . '\', \'Gallery\');" value="Edit permissions" /><br /><small>Only works in Firefox 1.5 or later, Safari 3.x or later, or Opera 9.0 or later.</small></td>
              </tr>';
              
        // Mover widget
        ?>
        <tr>
          <td class="row2">Move to folder:</td>
          <td class="row1">
            <div class="toggle">
              <div class="head" onclick="gal_toggle(this.nextSibling.nextSibling, this.childNodes[1]);">
                <img alt="&gt;&gt;" src="<?php echo scriptPath; ?>/plugins/gallery/toggle-closed.png" class="toggler" />
                Select folder
              </div>
              <div class="body">
                <?php
                  echo gallery_hier_formfield('img[' . $i . '][folder]', false);
                ?>
                <br />
                <a href="#" onclick="gal_unset_radios('img[<?php echo $i; ?>][folder]'); return false;">Unselect field</a>
              </div>
            </div>
          </td>
        </tr>
        <?php
              
        // File replacer
        
        echo '<tr>
                <td class="row2">Upload new version:</td>
                <td class="row1"><input type="file" name="reupload_' . $row['img_id'] . '" size="30" style="width: 98%;" /></td>
              </tr>';
              
        // Finish table
        echo '</table>';
        echo '</div>';
        
        endif;
        
        $i++;
      }
      while ( $row = $db->fetchrow($e) );
      $db->free_result();
      
      echo '<div class="tblholder">
              <table border="0" cellspacing="1" cellpadding="4">';
      // Mover widget
      if ( $num_edit > 1 ):
      ?>
      <tr>
        <td class="row2">Move all to folder:<br /><small>Other folder fields on this page can override this for individual images.</small></td>
        <td class="row1" style="width: 70%;">
          <div class="toggle">
            <div class="head" onclick="gal_toggle(this.nextSibling.nextSibling, this.childNodes[1]);">
              <img alt="&gt;&gt;" src="<?php echo scriptPath; ?>/plugins/gallery/toggle-closed.png" class="toggler" />
              Select folder
            </div>
            <div class="body">
              <?php
                echo gallery_hier_formfield('override_folder', false);
              ?>
              <br />
              <a href="#" onclick="gal_unset_radios('override_folder'); return false;">Unselect field</a>
            </div>
          </div>
        </td>
      </tr>
      <?php
      endif;
        
      echo '    <tr><th class="subhead" colspan="2"><input type="submit" name="edit_do_save" value="Save changes" /></th></tr>';
      echo '  </table>
            </div>';
      
    }
    else
    {
      echo '<p>No images that matched the ID list could be found.</p>';
    }
    
    echo '</form>';
    
    $template->footer();
    return;
  }
  
  if ( isset($_GET['rm']) )
  {
    $warnings = array();
    
    if ( !preg_match('/^[0-9]+$/', $_GET['rm']) )
      die_friendly('Bad Request', '<p>$_GET[rm] needs to be an integer.</p>');
    
    $rm_id = intval($_GET['rm']);
    
    if ( isset($_POST['confirmed']) )
    {
      // The user confirmed the request. Start plowing through data to decide what to delete.
      
      // Array of images and folder rows to delete
      $del_imgs = array($rm_id);
      // Array of files to delete
      $del_files = array();
      // Array of comment entries to delete
      $del_comments = array();
      
      $all_children = gal_fetch_all_children($rm_id);
      $del_imgs = array_merge($del_imgs, $all_children);
      
      $imglist = 'img_id=' . implode(' OR img_id=', $del_imgs);
      $sql = "SELECT img_id, img_filename FROM ".table_prefix."gallery WHERE ( $imglist ) AND is_folder!=1;";
      
      if ( !$db->sql_query($sql) )
      {
        $db->_die();
      }
      
      while ( $row = $db->fetchrow() )
      {
        $files = array(
            ENANO_ROOT . '/files/' . $row['img_filename'],
            ENANO_ROOT . '/cache/' . $row['img_filename'] . '-thumb.jpg',
            ENANO_ROOT . '/cache/' . $row['img_filename'] . '-preview.jpg'
          );
        $del_files = array_merge($del_files, $files);
        
        $del_comments[] = intval($row['img_id']);
      }
      
      $commentlist = 'page_id=\'' . implode('\' OR page_id=\'', $del_imgs) . '\'';
      
      // Main deletion cycle
      
      foreach ( $del_files as $file )
      {
        @unlink($file) or $warnings[] = 'Could not delete file ' . $file;
      }
      
      if ( !$db->sql_query('DELETE FROM '.table_prefix.'gallery WHERE ' . $imglist . ';') )
      {
        $warnings[] = 'Main delete query failed: ' . $db->get_error();
      }
      
      if ( !$db->sql_query('DELETE FROM '.table_prefix.'comments WHERE ( ' . $commentlist . ' ) AND namespace=\'Gallery\';') )
      {
        $warnings[] = 'Comment delete query failed: ' . $db->get_error();
      }
      
      if ( count($warnings) > 0 )
      {
        $template->header();
        
        echo '<h3>Error during deletion process</h3>';
        echo '<p>The deletion process generated some warnings which are shown below.</p>';
        echo '<ul><li>' . implode('</li><li>', $warnings) . '</li></ul>';
        
        $template->footer();
      }
      else
      {
        redirect(makeUrlNS('Special', 'Gallery'), 'Deletion successful', 'The selected item has been deleted from the gallery. You will now be transferred to the gallery index.', 2);
      }
      
    }
    else
    {
      // Removal form
      $template->header();
      
      echo '<form action="' . makeUrlNS('Special', 'GalleryUpload', 'rm=' . $rm_id, true) . '" method="post" enctype="multipart/form-data">';
      echo $max_size_field;
      
      echo '<h3>Are you sure you want to delete this item?</h3>';
      echo '<p>If you continue, this item will be permanently deleted from the gallery &ndash; no rollbacks.</p>';
      echo '<p>If this is an image, the image files will be removed from the filesystem, and all comments associated with the image will be deleted, as well as the image\'s title, description, and location.</p>';
      echo '<p>If this is a folder, all of its contents will be removed. Any images will be removed from the filesystem and all comments and metadata associated with images in this folder or any folders in it will be permanently deleted.</p>';
      
      echo '<p><input type="submit" name="confirmed" value="Continue with delete" /></p>';
      
      echo '</form>';
      
      $template->footer();
    }
    return;
  }
  
  if ( isset($_POST['do_stage2']) )
  {
    // Allow breaking out of the validation in the case of an error
    switch(true):case true:
      
      if ( empty($_POST['img_name']) )
      {
        $errors[] = 'Please enter an image name.';
      }
      
      // Validate files
      $n_files = intval($_POST['img_count']);
      if ( $n_files < 1 )
      {
        $errors[] = 'Cannot get image count';
        break;
      }
      
      $files = array();
      
      for ( $i = 0; $i < $n_files; $i++ )
      {
        $key = "img_$i";
        if ( isset($_FILES[$key]) && !empty($_FILES[$key]['name']) )
        {
          $files[] =& $_FILES[$key];
        }
      }
      
      if ( count($files) < 1 )
      {
        $errors[] = 'No files specified.';
        break;
      }
      
      $allowed = array('png', 'jpg', 'jpeg', 'tiff', 'tif', 'bmp', 'gif');
      $is_zip = false;
      foreach ( $files as $i => $file )
      {
        $ext = substr($file['name'], ( strrpos($file['name'], '.') + 1 ));
        $ext = strtolower($ext);
        if ( !in_array($ext, $allowed) && ( !$zip_support || ( $ext != 'zip' || $i > 0 ) ) )
        {
          $errors[] = htmlspecialchars($file['name']) . ' is an invalid extension (' . htmlspecialchars($ext) . ').';
        }
        else if ( $ext == 'zip' && $i == 0 && $zip_support )
        {
          $is_zip = true;
        }
      }
      
      if ( count($errors) > 0 )
      {
        // Send error messages
        break;
      }
      
      // Parent folder
      $folder = $_POST['folder_id'];
      if ( $folder != 'NULL' && !preg_match('/^[0-9]+$/', $folder) )
      {
        $folder = 'NULL';
      }
      
      // Format title and description fields
      $title = $template->makeParserText($_POST['img_name']);
      $desc  = $template->makeParserText($_POST['img_desc']);
      
      $vars = array(
          'year' => date('Y'),
          'month' => date('F'),
          'day' => date('d'),
          'time12' => date('g:i A'),
          'time24' => date('G:i')
        );
      
      $title->assign_vars($vars);
      $desc->assign_vars($vars);
      
      $idlist = array();
      
      // Try to disable the time limit
      @set_time_limit(0);
      
      // Move uploaded files to the files/ directory
      foreach ( $files as $i => $__trash )
      {
        $file =& $files[$i];
        $ext = substr($file['name'], ( strrpos($file['name'], '.') + 1 ));
        $ext = strtolower($ext);
        if ( $ext == 'zip' && $is_zip && $zip_support )
        {
          //
          // Time for some unzipping fun.
          //
          
          // for debugging only
          system('rm -fr ' . ENANO_ROOT . '/cache/temp');
          
          error_reporting(E_ALL);
          
          mkdir(ENANO_ROOT . '/cache/temp') or $errors[] = 'Could not create temporary directory for extraction.';
          if ( count($errors) > 0 )
            break 2;
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
              $errors[] = 'Could not open the zip file.';
              break 2;
            }
            $op = $zip->extractTo($temp_dir);
            if ( !$op )
            {
              $errors[] = 'Could not extract the zip file.';
              break 2;
            }
          }
          else if ( file_exists('/usr/bin/unzip') )
          {
            $cmd = "/usr/bin/unzip -qq -d $temp_dir {$file['tmp_name']}";
            system($cmd);
          }
          
          // Any files?
          $file_list = gal_dir_recurse($temp_dir, $dirs);
          if ( !$file_list )
          {
            $errors[] = 'Could not get file list for temp directory.';
            break 2;
          }
          if ( count($file_list) < 1 )
          {
            $errors[] = 'There weren\'t any files in the uploaded zip file.';
          }
          
          $dirs = array_reverse($dirs);
          $img_files = array();
          
          // Loop through and add files
          foreach ( $file_list as $file )
          {
            $ext = get_file_extension($file);
            
            if ( in_array($ext, $allowed) )
            {
              $img_files[] = $file;
            }
            else
            {
              unlink($file);
            }
          }
          
          // Main storage loop
          $j = 0;
          foreach ( $img_files as $file )
          {
            $ext = get_file_extension($file);
            $stored_name = gallery_make_filename() . ".$ext";
            $store = ENANO_ROOT . '/files/' . $stored_name;
            if ( !rename($file, $store) )
            {
              $errors[] = 'Could not move file ' . $file . ' to permanent storage location ' . $store . '.';
              break 3;
            }
            
            $autotitle = capitalize_first_letter(basename($file));
            $autotitle = substr($autotitle, 0, ( strrpos($autotitle, '.') ));
            $autotitle = str_replace('_', ' ', $autotitle);
            
            $title->assign_vars(array('id' => ( $j + 1 ), 'autotitle' => $autotitle));
            $desc->assign_vars(array('id' => ( $j + 1 ), 'autotitle' => $autotitle));
            
            $local_t = $title->run();
            $local_t = RenderMan::preprocess_text($local_t, true, false);
            
            $local_d = $desc->run();
            $local_d = RenderMan::preprocess_text($local_d, true, false);
            
            $subq = '(\'' . $stored_name . '\', \'' . $db->escape($local_t) . '\', \'' . $db->escape($local_d) . '\',\'a:0:{}\', UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), ' . $folder . ')';
            $sql = "INSERT INTO ".table_prefix."gallery(img_filename,img_title,img_desc,print_sizes,img_time_upload,img_time_mod,folder_parent) VALUES{$subq};";
            
            if ( !$db->sql_query($sql) )
              $db->_die();
            
            $idlist[] = $db->insert_id();
            
            // Create thumbnail image
            $thumb_filename = ENANO_ROOT . '/cache/' . $stored_name . '-thumb.jpg';
            $magick = getConfig('imagemagick_path');
            $command = "$magick '{$store}' -resize ".'"'."80x80>".'"'." -quality 85 $thumb_filename";
            
            @system($command, $stat);
            
            if ( !file_exists($thumb_filename) )
            {
              $errors[] = 'Couldn\'t scale image '.$i.': ImageMagick failed us';
              break 2;
            }
            
            // Create preview image
            $preview_filename = ENANO_ROOT . '/cache/' . $stored_name . '-preview.jpg';
            $magick = getConfig('imagemagick_path');
            $command = "$magick '{$store}' -resize ".'"'."640x640>".'"'." -quality 85 $preview_filename";
            
            @system($command, $stat);
            
            if ( !file_exists($preview_filename) )
            {
              $errors[] = 'Couldn\'t scale image '.$i.': ImageMagick failed us';
              break 2;
            }
            
            $j++;
          }
          
          // clean up
          foreach ( $dirs as $dir )
          {
            rmdir($dir);
          }
          
          rmdir( $temp_dir ) or $errors[] = 'Couldn\'t delete the unzip directory.';
          rmdir( ENANO_ROOT . '/cache/temp' ) or $errors[] = 'Couldn\'t delete the temp directory.';
          if ( count($errors) > 0 )
            break 2;
          
          $idlist = implode(',', $idlist);
          $url = makeUrlNS('Special', 'GalleryUpload', "edit_img=$idlist");
          
          redirect($url, 'Upload successful', 'Your images have been uploaded successfully. Please wait while you are transferred...', 2);
          
          break 2;
        }
        $file['stored_name'] = gallery_make_filename() . '.' . $ext;
        $store = ENANO_ROOT . '/files/' . $file['stored_name'];
        if ( !@move_uploaded_file($file['tmp_name'], $store) )
        {
          $errors[] = "[Internal] Couldn't move temporary file {$file['tmp_name']} to permanently stored file $store";
          break 2;
        }
        
        $autotitle = capitalize_first_letter(basename($file['name']));
        $autotitle = substr($autotitle, 0, ( strrpos($autotitle, '.') ));
        $autotitle = str_replace('_', ' ', $autotitle);
        
        $title->assign_vars(array('id' => ( $i + 1 ), 'autotitle' => $autotitle));
        $desc->assign_vars (array('id' => ( $i + 1 ), 'autotitle' => $autotitle));
        
        $local_t = $title->run();
        $local_t = RenderMan::preprocess_text($local_t, true, false);
        
        $local_d = $desc->run();
        $local_d = RenderMan::preprocess_text($local_d, true, false);
        
        $subq = '(\'' . $file['stored_name'] . '\', \'' . $db->escape($local_t) . '\', \'' . $db->escape($local_d) . '\',\'a:0:{}\', UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), ' . $folder . ')';
        $sql = "INSERT INTO ".table_prefix."gallery(img_filename,img_title,img_desc,print_sizes,img_time_upload,img_time_mod,folder_parent) VALUES{$subq};";
        
        if ( !$db->sql_query($sql) )
          $db->_die();
        
        $idlist[] = $db->insert_id();
        
        // Create thumbnail image
        $thumb_filename = ENANO_ROOT . '/cache/' . $file['stored_name'] . '-thumb.jpg';
        $magick = getConfig('imagemagick_path');
        $command = "$magick '{$store}' -resize ".'"'."80x80>".'"'." -quality 85 $thumb_filename";
        
        @system($command, $stat);
        
        if ( !file_exists($thumb_filename) )
        {
          $errors[] = 'Couldn\'t scale image '.$i.': ImageMagick failed us';
          break 2;
        }
        
        // Create preview image
        $preview_filename = ENANO_ROOT . '/cache/' . $file['stored_name'] . '-preview.jpg';
        $magick = getConfig('imagemagick_path');
        $command = "$magick '{$store}' -resize ".'"'."640x640>".'"'." -quality 85 $preview_filename";
        
        @system($command, $stat);
        
        if ( !file_exists($preview_filename) )
        {
          $errors[] = 'Couldn\'t scale image '.$i.': ImageMagick failed us';
          break 2;
        }
        
      }
      
      $idlist = implode(',', $idlist);
      $url = makeUrlNS('Special', 'GalleryUpload', "edit_img=$idlist");
      
      redirect($url, 'Upload successful', 'Your images have been uploaded successfully. Please wait while you are transferred...', 2);
      
      return;
      
    endswitch;
  }
  
  // Smart batch-upload interface
  $template->header();
  
  ?>
  <!-- Some Javascript magic :-) -->
  <script type="text/javascript">
    function gal_upload_addimg()
    {
      var id = 0;
      var td = document.getElementById('gal_upload_td');
      for ( var i = 0; i < td.childNodes.length; i++ )
      {
        var child = td.childNodes[i];
        if ( child.tagName == 'INPUT' && child.type == 'hidden' )
        {
          var file = document.createElement('input');
          file.type = 'file';
          file.size = '43';
          file.name = 'img_' + id;
          file.style.marginBottom = '3px';
          td.insertBefore(file, child);
          td.insertBefore(document.createElement('br'), child);
          child.value = String(id);
          return;
        }
        else if ( child.tagName == 'INPUT' && child.type == 'file' )
        {
          id++;
        }
      }
    }
  </script>
  <?php
  
  echo '<form action="' . makeUrlNS('Special', 'GalleryUpload') . '" enctype="multipart/form-data" method="post">';
  echo $max_size_field;
  if ( count($errors) > 0 )
  {
    echo '<div class="error-box">
            <b>The following errors were encountered during the upload:</b><br />
            <ul>
              <li>' . implode("</li>\n        <li>", $errors) . '</li>
            </ul>
          </div>';
  }
  ?>
  <div class="tblholder">
    <table border="0" cellspacing="1" cellpadding="4">
      <tr>
        <th colspan="2">Upload images to gallery</th>
      </tr>
      <tr>
        <td class="row2">Image name template:</td>
        <td class="row1"><input type="text" name="img_name" size="43" style="width: 98%;" /></td>
      </tr>
      <tr>
        <td class="row2">Image description template:</td>
        <td class="row1"><textarea rows="10" cols="40" name="img_desc" style="width: 98%;"></textarea></td>
      </tr>
      <tr>
        <td colspan="2" class="row3" style="font-size: smaller;">
          <p>The name and description templates can contain the following variables:</p>
          <ul>
            <li>{id}: The number of the image (different for each image)</li>
            <li>{autotitle}: Let the uploader automatically generate a title, based on the filename (david_in_the_barn.jpg will become "David in the barn"). Sometimes this process can be very dumb (mtrooper2k5.jpg will become "Mtrooper2k5").</li>
            <li>{year}: The current year (<?php echo date('Y'); ?>)</li>
            <li>{month}: The current month (<?php echo date('F'); ?>)</li>
            <li>{day}: The day of the month (<?php echo date('d'); ?>)</li>
            <li>{time12}: 12-hour time (<?php echo date('g:i A'); ?>)</li>
            <li>{time24}: 24-hour time (<?php echo date('G:i'); ?>)</li>
          </ul>
          <p>Example: <input type="text" readonly="readonly" value="Photo #{id} - uploaded {month} {day}, {year} {time12}" size="50" /></p>
        </td>
      </tr>
      <tr>
        <td class="row2">
          Image files:
          <?php
          if ( $zip_support )
          {
            ?>
            <br />
            <small><b>Your server has support for zip files.</b>
                   Instead of uploading many image files, you can upload a single zip file here. Note that if you send a zip file through,
                   it must be the first and only file or it will be ignored. Any files in the zip archive that are not supported image
                   files will be ignored.
                   <?php
                     if ( $sz = ini_get('upload_max_filesize') )
                     {
                       echo "<b>The maximum file size is <u>{$sz}B</u>.</b>";
                     }
                   ?>
                   </small>
            <?php
          }
          ?>
        </td>
        <td class="row1" id="gal_upload_td">
          <input type="file" name="img_0" size="43" style="margin-bottom: 3px" /><br />
          <input type="file" name="img_1" size="43" style="margin-bottom: 3px" /><br />
          <input type="file" name="img_2" size="43" style="margin-bottom: 3px" /><br />
          <input type="file" name="img_3" size="43" style="margin-bottom: 3px" /><br />
          <input type="file" name="img_4" size="43" style="margin-bottom: 3px" /><br />
          <input type="hidden" name="img_count" value="4" />
          <input type="button" value="+  Add image" onclick="gal_upload_addimg();" title="Add another image field" />
        </td>
      </tr>
      <tr>
        <td class="row2">Upload to folder:</td>
        <td class="row1">
          <div class="toggle">
            <div class="head" onclick="gal_toggle(this.nextSibling.nextSibling, this.childNodes[1]);">
              <img alt="&gt;&gt;" src="<?php echo scriptPath; ?>/plugins/gallery/toggle-closed.png" class="toggler" />
              Select folder
            </div>
            <div class="body">
              <?php
                echo gallery_hier_formfield();
              ?>
            </div>
          </div>
        </td>
      </tr>
    </table>
    <table border="0" cellspacing="1" cellpadding="4" style="padding-top: 0;">
      <tr>
        <th class="subhead" style="text-align: left;">
          <small>Please press the Upload button only once! Depending on the size of your image files and the speed of your connection, the upload may take several minutes.</small>
        </th>
        <th class="subhead" style="text-align: right;">
          <input type="submit" name="do_stage2" value="Upload images" /><br />
        </th>
      </tr>
    </table>
  </div>
  <?php
  echo '</form>';
  
  $template->footer();
  
}

?>
