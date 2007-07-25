/*
 * Misc functions for Enano.Img Gallery.
 */

function gal_toggle(elem, img, img_open, img_close)
{
  if ( !img_close || !img_open )
  {
    img_close = scriptPath + '/plugins/gallery/toggle-closed.png';
    img_open  = scriptPath + '/plugins/gallery/toggle-open.png';
  }
  if ( elem.style.display == 'block' )
  {
    elem.style.display = 'none';
    img.src = img_close;
  }
  else
  {
    elem.style.display = 'block';
    img.src = img_open;
  }
}


