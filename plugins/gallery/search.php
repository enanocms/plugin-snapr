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
 
//
// Search results hook
//

$plugins->attachHook('search_results', 'gal_searcher($q, $offset);');
$plugins->attachHook('search_global_inner', 'snapr_search_new_api($query, $query_phrase, $scores, $page_data, $case_sensitive, $word_list);');

$plugins->attachHook('compile_template', '
  // CSS for gallery browser
  $template->add_header(\'<link rel="stylesheet" href="' . scriptPath . '/plugins/gallery/browser.css" type="text/css" />\');
  $template->add_header(\'<link rel="stylesheet" href="' . scriptPath . '/plugins/gallery/dropdown.css" type="text/css" />\');
  ');

function gal_searcher($q, $offset)
{
  global $db, $session, $paths, $template, $plugins; // Common objects
  if ( defined('SNAPR_SEARCH_USING_NEW_API') || version_compare(enano_version(true), '1.0.2', '>=') )
    return false;
  
  $fulltext_col = 'MATCH(img_title, img_desc) AGAINST (\'' . $db->escape($q) . '\' IN BOOLEAN MODE)';
  $sql = "SELECT img_id, img_title, img_desc, is_folder, $fulltext_col AS score, CHAR_LENGTH(img_desc) AS length FROM ".table_prefix."gallery
              WHERE $fulltext_col > 0
                AND ( ( is_folder=1 AND folder_parent IS NULL ) OR is_folder!=1 )
              ORDER BY is_folder DESC, score DESC, img_title ASC;";
  if ( !$db->sql_unbuffered_query($sql) )
  {
    echo $db->get_error();
    return false;
  }
  echo "<h3>Image results</h3>";
  if ( $row = $db->fetchrow() )
  {
    echo '<ul class="snapr-gallery">';
    $renderer = new SnaprFormatter();
    $fullpage = $paths->fullpage;
    $paths->fullpage = $paths->nslist['Special'] . 'Gallery';
    do
    {
      echo $renderer->render(false, $row, false);
    }
    while ( $row = $db->fetchrow() );
    $paths->fullpage = $fullpage;
    echo '</ul><span class="menuclear"></span>';
  }
  else
  {
    echo '<p>No image results.</p>';
  }
}

function snapr_search_new_api(&$query, &$query_phrase, &$scores, &$page_data, &$case_sensitive, &$word_list)
{
  global $db, $session, $paths, $template, $plugins; // Common objects
  
  if ( !defined('SNAPR_SEARCH_USING_NEW_API') )
    define('SNAPR_SEARCH_USING_NEW_API', 1);
  
  // Let's do this all in one query
  $terms = array(
      'any' => array_merge($query['any'], $query_phrase['any']),
      'req' => array_merge($query['req'], $query_phrase['req']),
      'not' => $query['not']
    );
  $where = array('any' => array(), 'req' => array(), 'not' => array());
  $where_any =& $where['any'];
  $where_req =& $where['req'];
  $where_not =& $where['not'];
  $title_col = ( $case_sensitive ) ? 'img_title' : 'lcase(img_title)';
  $desc_col = ( $case_sensitive ) ? 'img_desc' : 'lcase(img_desc)';
  foreach ( $terms['any'] as $term )
  {
    $term = escape_string_like($term);
    if ( !$case_sensitive )
      $term = strtolower($term);
    $where_any[] = "( $title_col LIKE '%{$term}%' OR $desc_col LIKE '%{$term}%' )";
  }
  foreach ( $terms['req'] as $term )
  {
    $term = escape_string_like($term);
    if ( !$case_sensitive )
      $term = strtolower($term);
    $where_req[] = "( $title_col LIKE '%{$term}%' OR $desc_col LIKE '%{$term}%' )";
  }
  foreach ( $terms['not'] as $term )
  {
    $term = escape_string_like($term);
    if ( !$case_sensitive )
      $term = strtolower($term);
    $where_not[] = "$title_col NOT LIKE '%{$term}%' AND $desc_col NOT LIKE '%{$term}%'";
  }
  if ( empty($where_any) )
    unset($where_any, $where['any']);
  if ( empty($where_req) )
    unset($where_req, $where['req']);
  if ( empty($where_not) )
    unset($where_not, $where['not']);
  
  $where_any = '(' . implode(' OR ', $where_any) . '' . ( isset($where['req']) || isset($where['not']) ? ' OR 1 = 1' : '' ) . ')';
  
  if ( isset($where_req) )
    $where_req = implode(' AND ', $where_req);
  if ( isset($where_not) )
  $where_not = implode( 'AND ', $where_not);
  
  $where = implode(' AND ', $where);
  $sql = "SELECT img_id, img_title, img_desc FROM " . table_prefix . "gallery WHERE ( $where ) AND is_folder = 0;";
  
  if ( !($q = $db->sql_unbuffered_query($sql)) )
  {
    $db->_die('Error is in auto-generated SQL query in the Snapr plugin search module');
  }
  
  if ( $row = $db->fetchrow() )
  {
    do
    {
      $idstring = 'ns=Gallery;pid=' . $row['img_id'];
      foreach ( $word_list as $term )
      {
        if ( $case_sensitive )
        {
          if ( strstr($row['img_title'], $term) || strstr($row['img_desc'], $term) )
          {
            ( isset($scores[$idstring]) ) ? $scores[$idstring]++ : $scores[$idstring] = 1;
          }
        }
        else
        {
          if ( strstr(strtolower($row['img_title']), strtolower($term)) || strstr(strtolower($row['img_desc']), strtolower($term)) )
          {
            ( isset($scores[$idstring]) ) ? $scores[$idstring]++ : $scores[$idstring] = 1;
          }
        }
      }
      // Generate text...
      $text = highlight_and_clip_search_result(htmlspecialchars($row['img_desc']), $word_list);
      
      $preview_and_text = '
        <table border="0" width="100%" cellspacing="0" cellpadding="0">
          <tr>
            <td valign="top">
              ' . $text . '
            </td>
            <td valign="top" style="text-align: right; width: 80px; padding-left: 10px;">
              <a href="' . makeUrlNS('Gallery', $row['img_id']) . '"><img alt="[thumbnail]" src="' . makeUrlNS('Special', "GalleryFetcher/thumb/{$row['img_id']}") . '" /></a>
            </td>
          </tr>
        </table>
      ';
      
      // Inject result
      
      if ( isset($scores[$idstring]) )
      {
        // echo('adding image "' . $row['img_title'] . '" to results<br />');
        $page_data[$idstring] = array(
          'page_name' => highlight_search_result(htmlspecialchars($row['img_title']), $word_list),
          'page_text' => $preview_and_text,
          'score' => $scores[$idstring],
          'page_note' => '[Gallery image]',
          'page_id' => strval($row['img_id']),
          'namespace' => 'Gallery',
          'page_length' => strlen($row['img_desc']),
        );
      }
    }
    while ( $row = $db->fetchrow() );
    
  }
}

?>
