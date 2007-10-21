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
## BROWSER INTERFACE
##

$plugins->attachHook('base_classes_initted', '
  global $paths;
    $paths->add_page(Array(
      \'name\'=>\'Image gallery\',
      \'urlname\'=>\'Gallery\',
      \'namespace\'=>\'Special\',
      \'special\'=>0,\'visible\'=>1,\'comments_on\'=>0,\'protected\'=>1,\'delvotes\'=>0,\'delvote_ips\'=>\'\',
      ));
  ');

/**
 * Class to handle building the HTML for gallery pages. Called by the pagination function.
 * @package Enano
 * @subpackage Snapr
 * @access private
 */
 
class SnaprFormatter
{
  
  /**
   * Counter for how many cells we've printed out in this row.
   * @var int
   */
  
  var $cell_count = 0;
  
  /**
   * Icons to print per row.
   * @var int
   */
  
  var $icons_per_row = 5;
  
  /**
   * Main render method, called from pagination function
   * @access private
   */
  
  function render($column_crap, $row, $row_crap)
  {
    global $db, $session, $paths, $template, $plugins; // Common objects
    
    $out = '';
    
    if ( $this->cell_count == $this->icons_per_row )
    {
      $out .= '</tr><tr>';
      $this->cell_count = 0;
    }
    $this->cell_count++;
    
    $title_safe = $row['img_title'];
    $title_safe = htmlspecialchars($title_safe);
    
    if ( $row['is_folder'] == 1 )
    {
      // It's a folder, show the icon
      $f_url_particle = sanitize_page_id($row['img_title']);
      $f_url_particle = htmlspecialchars($f_url_particle);
      $image_link = makeUrl( $paths->fullpage . '/' . $f_url_particle );
      $image_url = scriptPath . '/plugins/gallery/folder.png';
    }
    else
    {
      // It's an image, show a thumbnail
      $image_link = makeUrlNS('Gallery', $row['img_id']);
      $image_url  = makeUrlNS('Special', 'GalleryFetcher/thumb/' . $row['img_id']);
    }
    
    if ( isset($row['score']) )
    {
      $row['score'] = number_format($row['score'], 2);
    }
    
    $image_url_js = addslashes($image_link);
    $jsclick = ( $session->user_level < USER_LEVEL_ADMIN ) ? ' onclick="window.location=\'' . $image_url_js . '\'"' : '';
    
    $out .= '<td style="text-align: center;">
            <div class="gallery_icon"' . $jsclick . '>';
    
    $out .= '<a href="' . $image_link . '"><img alt="&lt;Thumbnail&gt;" class="gallery_thumb" src="' . $image_url . '" /></a>';
    
    if ( $session->user_level < USER_LEVEL_ADMIN )
    {
      $out .= $title_safe . ( isset($row['score']) ? "<br /><small>Relevance: {$row['score']}</small>" : '' );
    }
    else if ( $session->user_level >= USER_LEVEL_ADMIN )
    {
      $out .= '<div class="menu_nojs" style="text-align: center;"><a href="#" onclick="return false;" style="width: 74px;">' . $title_safe . ( isset($row['score']) ? "<br /><small>Relevance: {$row['score']}</small>" : '' ) . '</a>';
      
      $url_delete = makeUrlNS('Special', 'GalleryUpload', 'rm=' . $row['img_id'], true);
      $url_edit   = makeUrlNS('Special', 'GalleryUpload', 'edit_img=' . $row['img_id'], true);
      
      // Tools menu
      $out .= '<ul style="text-align: left;">';
      $out .= '<li><a href="' . $url_delete . '">Delete ' . ( $row['is_folder'] == 1 ? 'this folder and all contents' : 'this image' ) . '</a></li>';
      $out .= '<li><a href="' . $url_edit   . '">Rename, move, or edit description</a></li>';
      $out .= '</ul>';
      $out .= '</div>';
      $out .= '<span class="menuclear"></span>';
    }
    
    $out .= '  </div>';
    
    $out .= '</td>';
    
    return $out;
  }
  
}

function page_Special_Gallery()
{
  global $db, $session, $paths, $template, $plugins; // Common objects
  
  // die('<pre>' . print_r(gallery_folder_hierarchy(), true) . '</pre>');
  
  $sort_column = ( isset($_GET['sort'])  && in_array($_GET['sort'],  array('img_title', 'img_time_upload', 'img_time_mod')) ) ? $_GET['sort'] : 'img_title';
  $sort_order  = ( isset($_GET['order']) && in_array($_GET['order'], array('ASC', 'DESC')) ) ? $_GET['order'] : 'ASC';
  
  // Determine number of pictures per page
  $template->load_theme();
  
  global $theme;
  $fn = ENANO_ROOT . '/themes/' . $template->theme . '/theme.cfg';
  require( $fn );
  if ( isset($theme['snapr_gallery_rows']) )
  {
    $rows_in_browser = intval($theme['snapr_gallery_rows']);
    if ( empty($rows_in_browser) )
    {
      $rows_in_browser = 5;
    }
  }
  else
  {
    $rows_in_browser = 5;
  }
  
  $where = 'WHERE folder_parent IS NULL ' . "\n  ORDER BY is_folder DESC, $sort_column $sort_order, img_title ASC";
  $parms = $paths->getAllParams();
  
  $sql = "SELECT img_id, img_title, is_folder FROM ".table_prefix."gallery $where;";
  
  // Breadcrumb browser
  $breadcrumbs = array();
  $breadcrumbs[] = '<a href="' . makeUrlNS('Special', 'Gallery') . '">Gallery index</a>';
  
  $breadcrumb_urlcache = '';
  
  // CSS for gallery browser
  // Moved to search.php
  //$template->add_header('<link rel="stylesheet" href="' . scriptPath . '/plugins/gallery/browser.css" type="text/css" />');
  //$template->add_header('<link rel="stylesheet" href="' . scriptPath . '/plugins/gallery/dropdown.css" type="text/css" />');
  
  $header = $template->getHeader();
  
  if ( !empty($parms) )
  {
    $parms = dirtify_page_id($parms);
    if ( strstr($parms, '/') )
    {
      $folders = explode('/', $parms);
    }
    else
    {
      $folders = array(0 => $parms);
    }
    foreach ( $folders as $i => $_crap )
    {
      $folder =& $folders[$i];
      
      $f_url = sanitize_page_id($folder);
      $breadcrumb_urlcache .= '/' . $f_url;
      $breadcrumb_url = makeUrlNS('Special', 'Gallery' . $breadcrumb_urlcache);
      
      $folder = str_replace('_', ' ', $folder);
      
      if ( $i == ( count($folders) - 1 ) )
      {
        $breadcrumbs[] = htmlspecialchars($folder);
      }
      else
      {
        $breadcrumbs[] = '<a href="' . $breadcrumb_url . '">' . htmlspecialchars($folder) . '</a>';
      }
    }
    unset($folder);
    $folders = array_reverse($folders);
    // This is one of the best MySQL tricks on the market. We're going to reverse-travel a folder path using LEFT JOIN and the incredible power of metacoded SQL
    $sql = 'SELECT gm.img_id, gm.img_title, gm.is_folder, g0.img_title AS folder_name, g0.img_id AS folder_id FROM '.table_prefix.'gallery AS gm' . "\n  " . 'LEFT JOIN '.table_prefix.'gallery AS g0' . "\n    " . 'ON ( gm.folder_parent = g0.img_id )';
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
    $sql .= $where . "\n  ORDER BY is_folder DESC, gm.$sort_column $sort_order, gm.img_title ASC" . ';';
  }
  
  $img_query = $db->sql_query($sql);
  if ( !$img_query )
    $db->_die('The folder ID could not be selected.');
  
  if ( $db->numrows() < 1 )
  {
    // Nothing in this folder, for one of two reasons:
    //   1) The folder doesn't exist
    //   2) The folder exists but doesn't have any images in it
    
    if ( sizeof($folders) < 1 )
    {
      // Nothing in the root folder
      
      $first_row['folder_id'] = 'NULL';
      if ( $session->user_level >= USER_LEVEL_ADMIN && isset($_POST['create_folder']) && isset($first_row['folder_id']) )
      {
        if ( empty($_POST['create_folder']) )
        {
          $f_errors[] = 'Please enter a folder name.';
        }
        if ( $_POST['create_folder'] == '_id' )
        {
          $f_errors[] = 'The name "_id" is reserved for internal functions and cannot be used on any image or folder.';
        }
        if ( count($f_errors) < 1 )
        {
          $q = $db->sql_query('INSERT INTO '.table_prefix.'gallery(img_title, is_folder, folder_parent) VALUES(\'' . $db->escape($_POST['create_folder']) . '\', 1, ' . $first_row['folder_id'] . ');');
          if ( !$q )
            $db->_die();
          redirect(makeUrl($paths->fullpage), 'Folder created', 'The folder "' . htmlspecialchars($_POST['create_folder']) . '" has been created. Redirecting to last viewed folder...', 2);
        }
      }
      
      $html = '';
      if ( $session->user_level >= USER_LEVEL_ADMIN )
      {
        $html .= '<p><a href="' . makeUrlNS('Special', 'GalleryUpload') . '">Upload an image</a></p>';
        $html .= '<div class="select-outer">Create new folder';
        $html .= '<div class="select-inner" style="padding-top: 4px;">';
        $html .= '<form action="' . makeUrl($paths->fullpage) . '" method="post">';
        $html .= '<input type="text" name="create_folder" size="30" /> <input type="submit" value="Create" />';
        $html .= '</form></div>';
        $html .= '</div><div class="select-pad">&nbsp;</div><br />';
      }
      
      die_friendly('No images', '<p>No images have been uploaded to the gallery yet.</p>' . $html);
    }
    
    /*
    $folders_old = $folders;
    $folders = array(
      0 => $folders_old[0]
      );
    $x = $folders_old;
    unset($x[0]);
    $folders = array_merge($folders, $x);
    unset($x);
    */
    // die('<pre>' . print_r($folders, true) . '</pre>');
    
    // This next query will try to determine if the folder itself exists
    $sql = 'SELECT g0.img_id, g0.img_title FROM '.table_prefix.'gallery AS g0';
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
    $where .= "\n    AND g0.is_folder=1";
    $sql .= $where . ';';
   
    $nameq = $db->sql_query($sql);
    if ( !$nameq )
      $db->_die();
    
    if ( $db->numrows($nameq) < 1 )
    {
      die_friendly('Folder not found', '<p>The folder you requested doesn\'t exist. Please check the URL and try again, or return to the <a href="' . makeUrlNS('Special', 'Gallery') . '">gallery index</a>.</p>');
    }
    
    $row = $db->fetchrow($nameq);
    
    // Generate title
    $title = dirtify_page_id($row['img_title']);
    $title = str_replace('_', ' ', $title);
    $title = htmlspecialchars($title);
    
    $template->tpl_strings['PAGE_NAME'] = $title;
    
    $first_row = $row;
    
    $db->sql_data_seek(0, $img_query);
    
    /* $folders = $folders_old; */
  }
  else if ( !empty($parms) )
  {
    $row = $db->fetchrow($img_query);
    $first_row = $row;
    
    // Generate title
    $title = htmlspecialchars($row['folder_name']);
    
    $template->tpl_strings['PAGE_NAME'] = $title;
    
    $db->sql_data_seek(0, $img_query);
  }
  else
  {
    $row = $db->fetchrow($img_query);
    $first_row = $row;
    
    $template->tpl_strings['PAGE_NAME'] = 'Image Gallery';
    $breadcrumbs = array('<b>Gallery index</b>');
    
    $db->sql_data_seek(0, $img_query);
  }
  
  $f_errors = array();
  
  if ( $session->user_level >= USER_LEVEL_ADMIN && isset($_POST['create_folder']) )
  {
    if ( !isset($first_row['folder_id']) )
    {
      $first_row['folder_id'] =& $first_row['img_id'];
    }
    if ( !isset($first_row['folder_id']) )
    {
      $f_errors[] = 'Internal error getting parent folder ID';
    }
    if ( empty($_POST['create_folder']) )
    {
      $f_errors[] = 'Please enter a folder name.';
    }
    if ( $_POST['create_folder'] == '_id' )
    {
      $f_errors[] = 'The name "_id" is reserved for internal functions and cannot be used on any image or folder.';
    }
    if ( count($f_errors) < 1 )
    {
      $q = $db->sql_query('INSERT INTO '.table_prefix.'gallery(img_title, is_folder, folder_parent) VALUES(\'' . $db->escape($_POST['create_folder']) . '\', 1, ' . $first_row['folder_id'] . ');');
      if ( !$q )
        $db->_die();
      redirect(makeUrl($paths->fullpage), 'Folder created', 'The folder "' . htmlspecialchars($_POST['create_folder']) . '" has been created. Redirecting to last viewed folder...', 2);
    }
  }
  
  echo $header;
  
  if ( count($f_errors) > 0 )
  {
    echo '<div class="error-box">Error creating folder:<ul><li>' . implode('</li><li>', $f_errors) . '</li></ul></div>';
  }
  
  // From here, this breadcrumb stuff is a piece of... sourdough French bread :-) *smacks lips*
  echo '<div class="breadcrumbs" style="padding: 4px; margin-bottom: 7px;">';
  // Upload image link for admins
  if ( $session->user_level >= USER_LEVEL_ADMIN )
  {
    echo '<div style="float: right; font-size: smaller;">';
    echo '<a href="' . makeUrlNS('Special', 'GalleryUpload') . '">Upload new image(s)</a>';
    echo '</div>';
  }
  // The actual breadcrumbs
  echo '<small>' . implode(' &raquo; ', $breadcrumbs) . '</small>';
  echo '</div>';
  
  // "Edit all" link
  if ( $row = $db->fetchrow($img_query) && $session->user_level >= USER_LEVEL_ADMIN )
  {
    $img_list = array();
    $fol_list = array();
    $all_list = array();
    do
    {
      if ( $row === true && isset($first_row) )
      {
        $row = $first_row;
      }
        // die('<pre>' . var_dump($row) . $db->sql_backtrace() . '</pre>');
      if ( !$row['img_id'] )
        break;
      $all_list[] = $row['img_id'];
      if ( $row['is_folder'] == 1 )
        $fol_list[] = $row['img_id'];
      else
        $img_list[] = $row['img_id'];
    }
    while ( $row = $db->fetchrow($img_query) );
    
    $all_list = implode(',', $all_list);
    $fol_list = implode(',', $fol_list);
    $img_list = implode(',', $img_list);
    
    if ( !empty($all_list) )
    {
      echo '<div style="float: right;">
              Edit all in this folder: ';
      if ( !empty($img_list) )
      {
        $edit_link = makeUrlNS('Special', 'GalleryUpload', 'edit_img=' . $img_list, true);
        echo "<a href=\"$edit_link\">images</a> ";
      }
      if ( !empty($fol_list) )
      {
        $edit_link = makeUrlNS('Special', 'GalleryUpload', 'edit_img=' . $fol_list, true);
        echo "<a href=\"$edit_link\">folders</a> ";
      }
      if ( !empty($img_list) && !empty($fol_list) )
      {
        $edit_link = makeUrlNS('Special', 'GalleryUpload', 'edit_img=' . $all_list, true);
        echo "<a href=\"$edit_link\">both</a> ";
      }
      // " Bypass stupid jEdit bug
      echo '</div>';
    }
  }
  
  $url_sort_name_asc  = makeUrl($paths->fullpage, 'sort=img_title&order=ASC', true);
  $url_sort_name_desc = makeUrl($paths->fullpage, 'sort=img_title&order=DESC', true);
  $url_sort_upl_asc   = makeUrl($paths->fullpage, 'sort=img_time_upload&order=ASC', true);
  $url_sort_mod_asc   = makeUrl($paths->fullpage, 'sort=img_time_mod&order=ASC', true);
  $url_sort_upl_desc  = makeUrl($paths->fullpage, 'sort=img_time_upload&order=DESC', true);
  $url_sort_mod_desc  = makeUrl($paths->fullpage, 'sort=img_time_mod&order=DESC', true);
  
  // "Sort by" selector (pure CSS!)
  echo '<div class="select-outer">
          <span>Sort by...</span>
          <div class="select-inner">
            <a href="' . $url_sort_name_asc  . '">Image title (A-Z) <b>(default)</b></a>
            <a href="' . $url_sort_name_desc . '">Image title (Z-A)</a>
            <a href="' . $url_sort_upl_desc  . '">Time first uploaded (newest first)</a>
            <a href="' . $url_sort_upl_asc   . '">Time first uploaded (oldest first)</a>
            <a href="' . $url_sort_mod_desc  . '">Date of last modification (newest first)</a>
            <a href="' . $url_sort_mod_asc   . '">Date of last modification (oldest first)</a>
          </div>
        </div>
        <div class="select-pad">&nbsp;</div>';
  
  $db->sql_data_seek(0, $img_query);
  
  //
  // Main fetcher
  //
  
  $renderer = new SnaprFormatter();
  $callers = array(
    'img_id' => array($renderer, 'render')
    );
  
  $renderer->icons_per_row = $rows_in_browser;
  
  $start = 0;
  if ( isset($_GET['start']) && preg_match('/^[0-9]+$/', $_GET['start']) )
  {
    $start = intval($_GET['start']);
  }
  
  $per_page = $rows_in_browser * 5;
  
  $html = paginate($img_query, '{img_id}', $db->numrows($img_query), makeUrl($paths->fullpage, 'sort=' . $sort_column . '&order=' . $sort_order . '&start=%s', false), $start, $per_page, $callers, '<table border="0" cellspacing="8"><tr>', '</tr></table>');
  echo $html;
  
  if ( $session->user_level >= USER_LEVEL_ADMIN )
  {
    echo '<div class="select-outer">Create new folder';
    echo '<div class="select-inner" style="padding-top: 4px;">';
    echo '<form action="' . makeUrl($paths->fullpage) . '" method="post">';
    echo '<input type="text" name="create_folder" size="30" /> <input type="submit" value="Create" />';
    echo '</form></div>';
    echo '</div><div class="select-pad">&nbsp;</div><br />';
  }
  
  $template->footer();
  
}

?>
