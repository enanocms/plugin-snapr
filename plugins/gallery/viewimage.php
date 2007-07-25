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
## GALLERY NAMESPACE HANDLER
##

$plugins->attachHook('page_not_found', 'gallery_namespace_handler($this);');

function gallery_namespace_handler(&$page)
{
  global $db, $session, $paths, $template, $plugins; // Common objects
  
  if ( $page->namespace != 'Gallery' )
    return false;
  
  if ( $page->page_id == 'Root' )
  {
    page_Special_Gallery();
    return true;
  }
  
  if ( preg_match('/^[0-9]+$/', $page->page_id) )
  {
    $img_id = intval($page->page_id);
    if ( !$img_id )
      return false;
    $q = $db->sql_query('SELECT img_id, img_title, img_desc, print_sizes, img_time_upload, img_time_mod, folder_parent FROM '.table_prefix.'gallery WHERE img_id=' . $img_id . ';');
    if ( !$q )
      $db->_die();
  }
  else
  {
    // Ech... he sent us a string... parse it and see what we get
    if ( strstr($page->page_id, '/') )
    {
      $folders = explode('/', $page->page_id);
    }
    else
    {
      $folders = array($page->page_id);
    }
    foreach ( $folders as $i => $_crap )
    {
      $folder =& $folders[$i];
      $folder = dirtify_page_id($folder);
      $folder = str_replace('_', ' ', $folder);
    }
    unset($folder);
    
    $folders = array_reverse($folders);
    // This is one of the best MySQL tricks on the market. We're going to reverse-travel a folder path using LEFT JOIN and the incredible power of metacoded SQL
    $sql = 'SELECT g0.img_id, g0.img_title, g0.img_desc, g0.print_sizes, g0.img_time_upload, g0.img_time_mod, g0.folder_parent FROM '.table_prefix.'gallery AS g0';
    $where = "\n  " . 'WHERE g0.img_title=\'' . $db->escape($folders[0]) . '\'';
    foreach ( $folders as $i => $folder )
    {
      if ( $i == 0 )
        continue;
      $i_dec = $i - 1;
      $folder = $db->escape($folder);
      $sql .= "\n  LEFT JOIN ".table_prefix."gallery AS g{$i}\n    ON ( g{$i}.img_id=g{$i_dec}.folder_parent AND g{$i}.img_title='$folder' )";
      $where .= "\n    ".'AND g'.$i.'.img_id IS NOT NULL';
    }
    $where .= "\n    AND g{$i}.folder_parent IS NULL";
    $sql .= $where . ';';
    
    if ( !$db->sql_query($sql) )
    {
      $db->_die('The image metadata could not be loaded.');
    }
    
    // Now that the folder data is no longer needed, we can fool around with it a little
    $folders = $page->page_id;
    if ( !strstr($folders, '/') )
    {
      $hier = '/';
    }
    else
    {
      $hier = preg_replace('/\/([^\/]+)$/', '/', $folders);
      $hier = sanitize_page_id($hier);
    }
    
  }
  if ( $db->numrows() < 1 )
  {
    // Image not found - show custom error message
    $template->header();
    echo '<h3>There is no image in the gallery with this ID.</h3>';
    echo '<p>You have requested an image that couldn\'t be looked up. Please check the URL and try again, or visit the <a href="' . makeUrlNS('Special', 'Gallery') . '">Gallery index</a>.</p>';
    $template->footer();
    return false;
  }
  $row = $db->fetchrow();
  
  $db->free_result();
  
  $img_id = $row['img_id'];
  
  if ( !$row['folder_parent'] )
    $row['folder_parent'] = ' IS NULL';
  else
    $row['folder_parent'] = '=' . $row['folder_parent'];
  
  // Fetch image parent properties
  $q = $db->sql_query('SELECT img_id, img_title FROM '.table_prefix.'gallery WHERE folder_parent' . $row['folder_parent'] . ' AND is_folder!=1 ORDER BY img_title ASC;');
  if ( !$q )
    $db->_die();
  
  $folder_total = $db->numrows();
  $folder_this = 0;
  $prev = false;
  $next = false;
  $next_title = '';
  $prev_title = '';
  
  $i = 0;
  
  while ( $r = $db->fetchrow() )
  {
    $i++;
    if ( $i == $folder_total && $r['img_id'] == $img_id )
    {
      $folder_this = $i;
      $next = false;
    }
    else if ( $i < $folder_total && $r['img_id'] == $img_id )
    {
      $folder_this = $i;
      $next = true;
    }
    else
    {
      if ( $next )
      {
        $next = $r['img_id'];
        $next_title = $r['img_title'];
        break;
      }
      $prev = $r['img_id'];
      $prev_title = $r['img_title'];
    }
  }
  
  if ( $next )
  {
    $next_sanitized = sanitize_page_id($next_title);
    $next_url = ( isset($hier) ) ? makeUrlNS('Gallery', $hier . $next_sanitized ) : makeUrlNS('Gallery', $next);
  }
  if ( $prev )
  {
    $prev_sanitized = sanitize_page_id($prev_title);
    $prev_url = ( isset($hier) ) ? makeUrlNS('Gallery', $hier . $prev_sanitized ) : makeUrlNS('Gallery', $prev);
  }
  
  $db->free_result();
  
  $template->tpl_strings['PAGE_NAME'] = 'Gallery image: ' . htmlspecialchars($row['img_title']);
  $title_spacey = strtolower(htmlspecialchars($row['img_title']));
  
  $perms = $session->fetch_page_acl(strval($img_id), 'Gallery');
  
  $template->header();
  
  $img_id = intval($img_id);
  $bc_folders = gallery_imgid_to_folder($img_id);
  $bc_folders = array_reverse($bc_folders);
  $bc_url = '';
  $breadcrumbs = array();
  $breadcrumbs[] = '<a href="' . makeUrlNS('Special', 'Gallery') . '">Gallery index</a>';
  
  foreach ( $bc_folders as $folder )
  {
    $bc_url .= '/' . dirtify_page_id($folder);
    $breadcrumbs[] = '<a href="' . makeUrlNS('Special', 'Gallery' . $bc_url, false, true) . '">' . htmlspecialchars($folder) . '</a>';
  }
  
  $breadcrumbs[] = htmlspecialchars($row['img_title']);
  
  // From here, this breadcrumb stuff is a piece of... sourdough French bread :-) *smacks lips*
  echo '<div class="tblholder" style="padding: 4px; margin-bottom: 7px;">';
  // The actual breadcrumbs
  echo '<b><small>' . implode(' &raquo; ', $breadcrumbs) . '</small></b>';
  echo '</div>';
  
  echo '<div style="text-align: center; margin: 10px auto; border: 1px solid #DDDDDD; padding: 7px 10px; display: table;">';
  $img_url  = makeUrlNS('Special', 'GalleryFetcher/preview/' . $img_id);
  $img_href = makeUrlNS('Special', 'GalleryFetcher/full/' . $img_id);
  
  if ( $perms->get_permissions('gal_full_res') )
  {
    echo '<a href="' . $img_href . '" title="Click to view this image at full resolution, right click to save image" onclick="window.open(this.href, \'\', \'toolbar=no,address=no,menus=no,status=no\'); return false;">';
  }
  
  echo '<img alt="Image preview (640px max width)" src="' . $img_url . '" style="border-width: 0; margin-bottom: 5px; display: block;" />';
  
  if ( $perms->get_permissions('gal_full_res') )
  {
    echo '</a>';
  }
  
  echo '<table border="0" width="100%"><tr><td style="text-align: left; width: 24px;">';
  
  // Prev button
  if ( $prev )
    echo '<a href="' . $prev_url . '"><img style="border-width: 0px;" alt="&lt; Previous" src="' . scriptPath . '/plugins/gallery/prev.gif" /></a>';
  //echo '</td><td style="text-align: left;">';
  // if ( $prev )
  //   echo '<a href="' . $prev_url . '">previous image</a>';
  
  echo '</td><td style="text-align: center; letter-spacing: 5px;">';
  
  // Image title
  echo $title_spacey;
  
  echo '</td><td style="text-align: right; width: 24px;">';
  
  // Next button
  if ( $next )
  //  echo '<a href="' . $next_url . '">next image</a>';
  //echo '</td><td style="text-align: right;">';
  if ( $next )
    echo '<a href="' . $next_url . '"><img style="border-width: 0px;" alt="&lt; Previous" src="' . scriptPath . '/plugins/gallery/next.gif" /></a>';
  
  echo '</td></tr>';
  echo '<tr><td colspan="3">' . "image $folder_this of $folder_total" . '</td></tr>';
  echo '</table>';
  echo '</div>';
  
  if ( $session->user_level >= USER_LEVEL_ADMIN )
  {
    echo '<div style="float: right;">[ <a href="' . makeUrlNS('Special', 'GalleryUpload', 'edit_img=' . $img_id, true) . '">edit image</a> ]</div>';
  }
  
  if ( !empty($row['img_desc']) )
  {
    echo '<h2>Image description</h2>';
    
    $desc = RenderMan::render($row['img_desc']);
    echo $desc;
  }
  
  echo '<div class="tblholder" style="font-size: smaller; display: table;' . ( empty($row['img_desc']) ? '' : 'margin: 0 auto;' ) . '">
          <table border="0" cellspacing="1" cellpadding="3">';
  
  // By the time I got to this point, it was 1:32AM (I was on vacation) and my 5-hour playlist on my iPod had been around about 3 times today.
  // So I'm glad this is like the last thing on the list tonight.
  
  echo '<tr><th colspan="2">Image details</th></tr>';
  echo '<tr><td class="row2">Uploaded:</td><td class="row1">' . date('F d, Y h:i a', $row['img_time_upload']) . '</td></tr>';
  echo '<tr><td class="row2">Last modified:</td><td class="row1">' . date('F d, Y h:i a', $row['img_time_mod']) . '</td></tr>';
          
  echo '</table></div>';
  
  $template->footer();
    
}

?>
