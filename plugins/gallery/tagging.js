function snapr_add_tag()
{
  var image = document.getElementById('snapr_preview_img');
  image.parentNode.onclick = function(e)
  {
    canvas_click(this);
  }
  image.parentNode.setAttribute('canvas:oncomplete', 'snapr_process_canvas_add(this);');
  image.parentNode.setAttribute('canvas:oncancel', 'obj.onclick = null;');
}

function snapr_process_canvas_add(obj)
{
  obj.onclick = null;
  var abs_x = $(obj).Left() + obj.canvas.left;
  var abs_y = $(obj).Top()  + obj.canvas.top;
  var height = obj.canvas.height + 2;
  
  var entry_div = document.createElement('div');
  entry_div.className = 'snapr_tag_entry';
  entry_div.style.position = 'absolute';
  entry_div.style.top = String(abs_y + height) + 'px';
  entry_div.style.left = String(abs_x)+ 'px';
  
  entry_div.appendChild(document.createTextNode('Enter a tag:'));
  entry_div.appendChild(document.createElement('br'));
  
  var ta = document.createElement('textarea');
  ta.rows = '7';
  ta.cols = '30';
  entry_div.appendChild(ta);
  
  entry_div.appendChild(document.createElement('br'));
  
  var a_add = document.createElement('a');
  a_add.href = '#';
  a_add.onclick = function()
  {
    snapr_finalize_canvas_add(this.parentNode, this.parentNode.parentNode.canvas, this.previousSibling.previousSibling.value);
    return false;
  }
  a_add.appendChild(document.createTextNode('Add tag'));
  entry_div.appendChild(a_add);
  
  entry_div.appendChild(document.createTextNode(' | '));
  
  var a_cancel = document.createElement('a');
  a_cancel.href = '#';
  a_cancel.onclick = function()
  {
    snapr_finalize_canvas_cancel(this.parentNode);
    return false;
  }
  a_cancel.appendChild(document.createTextNode('Cancel'));
  entry_div.appendChild(a_cancel);
  
  obj.appendChild(entry_div);
  ta.focus();
}

function snapr_finalize_canvas_add(obj, canvas_data, tag)
{
  // add the new box
  var id = obj.parentNode.getAttribute('snapr:imgid');
  if ( !id )
    return false;
  
  // destroy form, etc.
  var parent = obj.parentNode;
  parent.removeChild(parent.canvas_box_obj);
  parent.removeChild(obj);
  
  var canvas_json = toJSONString(canvas_data);
  ajaxPost(makeUrlNS('Gallery', id), 'ajax=true&act=add_tag&tag=' + escape(tag) + '&canvas_params=' + escape(canvas_json), snapr_process_ajax_tag_packet);
}

function snapr_finalize_canvas_cancel(obj)
{
  var parent = obj.parentNode;
  parent.removeChild(parent.canvas_box_obj);
  parent.removeChild(obj);
}

function snapr_draw_note(obj, tag, canvas_data, note_id, initial_hide, auth_delete)
{
  var newbox = canvas_create_box(obj, canvas_data.left, canvas_data.top, canvas_data.width, canvas_data.height);
  newbox.tag_id = note_id;
  obj.onmouseover = function()
  {
    var boxen = this.getElementsByTagName('div');
    for ( var i = 0; i < boxen.length; i++ )
      if ( boxen[i].className == 'canvasbox' )
        boxen[i].style.display = 'block';
  }
  obj.onmouseout = function()
  {
    var boxen = this.getElementsByTagName('div');
    for ( var i = 0; i < boxen.length; i++ )
      if ( boxen[i].className == 'canvasbox' )
        boxen[i].style.display = 'none';
  }
  newbox.onmouseover = function()
  {
    this.style.borderColor = '#FFFF00';
    this.firstChild.style.borderColor = '#000000';
    snapr_display_note(this.noteObj);
  }
  newbox.onmouseout = function()
  {
    this.style.borderColor = '#000000';
    this.firstChild.style.borderColor = '#FFFFFF';
    snapr_hide_note(this.noteObj);
  }
  if ( auth_delete )
  {
    var p = document.createElement('p');
    p.style.cssFloat = 'right';
    p.style.styleFloat = 'right';
    p.style.fontWeight = 'bold';
    p.style.margin = '5px';
    var a_del = document.createElement('a');
    a_del.style.color = '#FF0000';
    a_del.href = '#';
    a_del.onclick = function()
    {
      snapr_nuke_tag(this.parentNode.parentNode.parentNode);
      return false;
    }
    a_del.appendChild(document.createTextNode('[X]'));
    p.appendChild(a_del);
    newbox.firstChild.appendChild(p);
  }
  var abs_x = $(newbox).Left();
  var abs_y = $(newbox).Top() + $(newbox).Height() + 2;
  var noteObj = document.createElement('div');
  newbox.noteObj = noteObj;
  noteObj.className = 'snapr_tag';
  noteObj.style.display = 'none';
  noteObj.style.position = 'absolute';
  noteObj.style.top = abs_y + 'px';
  noteObj.style.left = abs_x + 'px';
  var re = new RegExp(unescape('%0A'), 'g');
  noteObj.innerHTML = tag.replace(re, "<br />\n");
  obj.appendChild(noteObj);
  if ( initial_hide )
    newbox.style.display = 'none';
}

function snapr_display_note(note)
{
  //domObjChangeOpac(0, note);
  note.style.display = 'block';
  //domOpacity(note, 0, 100, 500);
}

function snapr_hide_note(note)
{
  //domOpacity(note, 100, 0, 500);
  //setTimeout(function()
  //  {
      note.style.display = 'none';
  //  }, 600);
}

function snapr_nuke_tag(obj)
{
  // add the new box
  var parent_obj = document.getElementById('snapr_preview_img').parentNode;
  var id = parent_obj.getAttribute('snapr:imgid');
  if ( !id )
    return false;
  ajaxPost(makeUrlNS('Gallery', id), 'ajax=true&act=del_tag&tag_id=' + obj.tag_id, snapr_process_ajax_tag_packet);
}

function snapr_process_ajax_tag_packet()
{
  if ( ajax.readyState == 4 && ajax.status == 200 )
  {
    var response = String(ajax.responseText + '');
    if ( response.substr(0, 1) != '[' && response.substr(0, 1) != '{' )
    {
      handle_invalid_json(response);
      return false;
    }
    response = parseJSON(response);
    if ( response.mode )
    {
      if ( response.mode == 'error' )
      {
        alert(response.error);
        return false;
      }
    }
    var parent_obj = document.getElementById('snapr_preview_img').parentNode;
    for ( var i = 0; i < response.length; i++ )
    {
      var packet = response[i];
      switch(packet.mode)
      {
        case 'add':
          snapr_draw_note(parent_obj, packet.tag, packet.canvas_data, packet.note_id, packet.initial_hide, packet.auth_delete);
          break;
        case 'remove':
          // Server requested to remove a tag
          var divs = parent_obj.getElementsByTagName('div');
          for ( var i = 0; i < divs.length; i++ )
          {
            var box = divs[i];
            if ( box.className == 'canvasbox' )
            {
              if ( box.tag_id == packet.note_id )
              {
                // You. We have orders to shoot. Stand in front of wall.
                var sibling = box.nextSibling;
                var parent = box.parentNode;
                // BLAM.
                parent.removeChild(sibling);
                parent.removeChild(box);
                break;
              }
            }
          }
          break;
      }
    }
  }
}

var snapr_tags_onload = function()
{
  // add the new box
  var parent_obj = document.getElementById('snapr_preview_img').parentNode;
  var id = parent_obj.getAttribute('snapr:imgid');
  if ( !id )
    return false;
  ajaxPost(makeUrlNS('Gallery', id), 'ajax=true&act=get_tags', snapr_process_ajax_tag_packet);
}

addOnloadHook(snapr_tags_onload);

