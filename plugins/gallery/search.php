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

$plugins->attachHook('compile_template', '
  // CSS for gallery browser
  $template->add_header(\'<link rel="stylesheet" href="' . scriptPath . '/plugins/gallery/browser.css" type="text/css" />\');
  $template->add_header(\'<link rel="stylesheet" href="' . scriptPath . '/plugins/gallery/dropdown.css" type="text/css" />\');
  ');

function gal_searcher($q, $offset)
{
  global $db, $session, $paths, $template, $plugins; // Common objects
  
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
    echo '<table border="0" cellspacing="8"><tr>';
    $renderer = new SnaprFormatter();
    $fullpage = $paths->fullpage;
    $paths->fullpage = $paths->nslist['Special'] . 'Gallery';
    do
    {
      echo $renderer->render(false, $row, false);
    }
    while ( $row = $db->fetchrow() );
    $paths->fullpage = $fullpage;
    echo '</tr></table>';
  }
  else
  {
    echo '<p>No image results.</p>';
  }
}

?>
