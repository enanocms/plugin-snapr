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

// Add an [[:Image:foo]] tag handler to the wiki formatter
$plugins->attachHook('render_wikiformat_pre', 'snapr_process_image_tags($text);');

function snapr_process_image_tags(&$text)
{
  $text = snapr_image_tags_stage1($text, $taglist);
  $text = snapr_image_tags_stage2($text, $taglist);
}

/*
 * Functions copied from render.php
 */

/**
 * Changes wikitext image tags to HTML.
 * @param string The wikitext to process
 * @param array Will be overwritten with the list of HTML tags (the system uses tokens for TextWiki compatibility)
 * @return string
 */

function snapr_image_tags_stage1($text, &$taglist)
{
  global $db, $session, $paths, $template, $plugins; // Common objects
  
  static $idcache = array();
  
  $s_delim = "\xFF";
  $f_delim = "\xFF";
  $taglist = array();
  
  // Wicked huh?
  $regex = '/\[\[:' . str_replace('/', '\\/', preg_quote($paths->nslist['Gallery'])) . '([\w\s0-9_\(\)!@%\^\+\|\.-]+?)((\|thumb)|(\|([0-9]+)x([0-9]+)))?(\|left|\|right)?(\|raw|\|(.+))?\]\]/i';
  
  preg_match_all($regex, $text, $matches);
  
  foreach ( $matches[0] as $i => $match )
  {
    $full_tag   =& $matches[0][$i];
    $imagename  =& $matches[1][$i];
    $scale_type =& $matches[2][$i];
    $width      =& $matches[5][$i];
    $height     =& $matches[6][$i];
    $clear      =& $matches[7][$i];
    $caption    =& $matches[8][$i];
    
    // determine the image name
    $imagename = sanitize_page_id($imagename);
    if ( isset($idcache[$imagename]) )
    {
      $found_image_id = true;
      $filename =& $idcache[$imagename];
    }
    else
    {
      $found_image_id = false;
      // get the image ID
      // Ech... he sent us a string... parse it and see what we get
      if ( strstr($imagename, '/') )
      {
        $folders = explode('/', $imagename);
      }
      else
      {
        $folders = array($imagename);
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
      $sql = 'SELECT g0.img_id, g0.img_title, g0.img_desc, g0.print_sizes, g0.img_time_upload, g0.img_time_mod, g0.img_filename, g0.folder_parent, g0.img_tags FROM '.table_prefix.'gallery AS g0';
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
      
      if ( $db->numrows() > 0 )
      {
        $found_image_id = true;
        $row = $db->fetchrow();
        $db->free_result();
        $idcache[$imagename] = $row['img_id'];
        $filename =& $idcache[$imagename];
      }
    }
    
    if ( !$found_image_id )
    {
      $text = str_replace($full_tag, '[[' . makeUrlNS('Gallery', $imagename) . ']]', $text);
      continue;
    }
    
    if ( $scale_type == '|thumb' )
    {
      $r_width  = 225;
      $r_height = 225;
      
      $url = makeUrlNS('Special', 'GalleryFetcher/embed/' . $filename, 'width=' . $r_width . '&height=' . $r_height, true);
    }
    else if ( !empty($width) && !empty($height) )
    {
      $r_width = $width;
      $r_height = $height;
      
      $url = makeUrlNS('Special', 'GalleryFetcher/embed/' . $filename, 'width=' . $r_width . '&height=' . $r_height, true);
    }
    else
    {
      $url = makeUrlNS('Special', 'GalleryFetcher/' . $filename);
    }
    
    $img_tag = '<img src="' . $url . '" ';
    
    // if ( isset($r_width) && isset($r_height) && $scale_type != '|thumb' )
    // {
    //   $img_tag .= 'width="' . $r_width . '" height="' . $r_height . '" ';
    // }
    
    $img_tag .= 'style="border-width: 0px; /* background-color: white; */" ';
    
    $code = $plugins->setHook('snapr_img_tag_parse_img');
    foreach ( $code as $cmd )
    {
      eval($cmd);
    }
    
    $img_tag .= '/>';
    
    $complete_tag = '';
    
    if ( !empty($scale_type) && $caption != '|raw' )
    {
      $complete_tag .= '<div class="thumbnail" ';
      $clear_text = '';
      if ( !empty($clear) )
      {
        $side = ( $clear == '|left' ) ? 'left' : 'right';
        $opposite = ( $clear == '|left' ) ? 'right' : 'left';
        $clear_text .= "float: $side; margin-$opposite: 20px; width: {$r_width}px;";
        $complete_tag .= 'style="' . $clear_text . '" ';
      }
      $complete_tag .= '>';
      
      $complete_tag .= '<a href="' . makeUrlNS('Gallery', $filename) . '" style="display: block;">';
      $complete_tag .= $img_tag;
      $complete_tag .= '</a>';
      
      $mag_button = '<a href="' . makeUrlNS('Gallery', $filename) . '" style="display: block; float: right; clear: right; margin: 0 0 10px 10px;"><img alt="[ + ]" src="' . scriptPath . '/images/thumbnail.png" style="border-width: 0px;" /></a>';
    
      if ( !empty($caption) )
      {
        $cap = substr($caption, 1);
        $complete_tag .= $mag_button . $cap;
      }
      
      $complete_tag .= '</div>';
    }
    else if ( $caption == '|raw' )
    {
      $complete_tag .= "$img_tag";
      $taglist[$i] = $complete_tag;
      
      $repl = "{$s_delim}e_img_{$i}{$f_delim}";
      $text = str_replace($full_tag, $repl, $text);
      continue;
    }
    else
    {
      $complete_tag .= '<a href="' . makeUrlNS('Gallery', $filename) . '" style="display: block;"';
      $code = $plugins->setHook('snapr_img_tag_parse_link');
      foreach ( $code as $cmd )
      {
        eval($cmd);
      }
      $complete_tag .= '>';
      $complete_tag .= $img_tag;
      $complete_tag .= '</a>';
    }
    
    $complete_tag .= "\n\n";
    $taglist[$i] = $complete_tag;
    
    $pos = strpos($text, $full_tag);
    
    while(true)
    {
      $check1 = substr($text, $pos, 3);
      $check2 = substr($text, $pos, 1);
      if ( $check1 == '<p>' || $pos == 0 || $check2 == "\n" )
      {
        // die('found at pos '.$pos);
        break;
      }
      $pos--;
    }
    
    $repl = "{$s_delim}e_img_{$i}{$f_delim}";
    $text = substr($text, 0, $pos) . $repl . substr($text, $pos);
    
    $text = str_replace($full_tag, '', $text);
    
    unset($full_tag, $filename, $scale_type, $width, $height, $clear, $caption, $r_width, $r_height);
    
  }
  
  return $text;
}

/**
 * Finalizes processing of image tags.
 * @param string The preprocessed text
 * @param array The list of image tags created by RenderMan::process_image_tags()
 */
 
function snapr_image_tags_stage2($text, $taglist)
{
  $s_delim = "\xFF";
  $f_delim = "\xFF";
  foreach ( $taglist as $i => $tag )
  {
    $repl = "{$s_delim}e_img_{$i}{$f_delim}";
    $text = str_replace($repl, $tag, $text);
  }               
  return $text;
}

?>
