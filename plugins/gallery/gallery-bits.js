/*
 * Misc functions for Snapr.
 */

function gal_toggle(elem, img, img_open, img_close)
{
	if ( !img_close || !img_open )
	{
		img_close = scriptPath + '/plugins/gallery/toggle-closed.png';
		img_open  = scriptPath + '/plugins/gallery/toggle-open.png';
	}
	if ( elem.style.display == 'none' || !elem.style.display )
	{
		elem.style.display = 'block';
		try {
			img.src = img_open;
		} catch(e) {};
	}
	else
	{
		elem.style.display = 'none';
		try {
			img.src = img_close;
		} catch(e) {};
	}
}

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

