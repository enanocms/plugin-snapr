var canvas_mousemove_temp;
var canvas_keyup_temp;
var CANVAS_KEY_ESC = 27;

function canvas_click(obj)
{
  var click_x = mouseX - $(obj).Left();
  var click_y = mouseY - $(obj).Top() + getScrollOffset();
  
  if ( obj.canvas_in_draw )
  {
    canvas_close_draw(obj, click_x, click_y);
  }
  else
  {
    canvas_open_draw(obj, click_x, click_y);
  }
}

function canvas_open_draw(obj, x, y)
{
  obj.canvas_box_obj = canvas_create_box(obj, x, y, 1, 1);
  obj.canvas_in_draw = true;
  obj.onclick = function(e)
  {
    canvas_click(this);
    var onclose = this.getAttribute('canvas:oncomplete');
    if ( onclose )
    {
      eval(onclose);
    }
  }
  canvas_replace_mousemove(obj);
}

function canvas_replace_mousemove(obj)
{
  canvas_mousemove_temp = document.onmousemove;
  canvas_mousemove_temp.box_obj = obj;
  canvas_keyup_temp = document.onkeyup;
  document.onmousemove = function(e)
  {
    canvas_mousemove_temp(e);
    canvas_redraw_box(canvas_mousemove_temp.box_obj);
  }
  document.onkeyup = function(e)
  {
    if ( typeof(canvas_keyup_temp) == 'function' )
      canvas_keyup_temp(e);
    
    if ( e.keyCode == CANVAS_KEY_ESC )
      canvas_cancel_draw(canvas_mousemove_temp.box_obj);
  }
}

function canvas_restore_mousemove()
{
  document.onmousemove = canvas_mousemove_temp;
  document.onkeyup = canvas_keyup_temp;
}

function canvas_create_box(obj, x, y, width, height)
{
  var inner_width = width - 2;
  var inner_height = height - 2;
  var top = $(obj).Top() + y;
  var left = $(obj).Left() + x;
  
  // draw outer box
  var div_outer = document.createElement('div');
  div_outer.className = 'canvasbox';
  div_outer.style.border = '1px solid #000000';
  div_outer.style.position = 'absolute';
  div_outer.style.width = String(width) + 'px';
  div_outer.style.height = String(height) + 'px';
  div_outer.style.top = String(top) + 'px';
  div_outer.style.left = String(left) + 'px';
  
  div_outer.rootY = y;
  div_outer.rootX = x;
  
  var div_inner = document.createElement('div');
  div_inner.style.border = '1px solid #FFFFFF';
  if ( IE )
  {
    div_inner.style.width = '100%';
    div_inner.style.height = '100%';
  }
  else
  {
    div_inner.style.width = String(inner_width) + 'px';
    div_inner.style.height = String(inner_height) + 'px';
  }
  
  div_outer.appendChild(div_inner);
  
  obj.appendChild(div_outer);
  return div_outer;
}

function canvas_redraw_box(obj)
{
  if ( !obj.canvas_box_obj )
    return false;
  var rel_x = mouseX - $(obj).Left();
  var rel_y = mouseY - $(obj).Top() + getScrollOffset();
  var new_width = rel_x - obj.canvas_box_obj.rootX;
  var new_height = rel_y - obj.canvas_box_obj.rootY;
  var rootX = obj.canvas_box_obj.rootX;
  var rootY = obj.canvas_box_obj.rootY;
  // Limit dimensions to width - origin_x and height - origin_y
  if ( new_width + rootX > $(obj).Width() )
    new_width = $(obj).Width() - rootX;
  if ( new_height + rootY > $(obj).Height() )
    new_height = $(obj).Height() - rootY;
  // If going to the top or left of the origin, avoid negative width/height by moving the box
  if ( new_width < 1 )
  {
    new_width = rootX - rel_x;
    obj.canvas_box_obj.style.left = String(mouseX + 2) + 'px';
  }
  if ( new_height < 1 )
  {
    new_height = rootY - rel_y;
    obj.canvas_box_obj.style.top = String(mouseY + getScrollOffset() + 2) + 'px';
  }
  obj.canvas_box_obj.style.width = String(new_width) + 'px';
  obj.canvas_box_obj.style.height = String(new_height) + 'px';
  new_width = new_width - 2;
  new_height = new_height - 2;
  if ( IE )
  {
    var nw = new_width;
    var nh = new_height;
    obj.canvas_box_obj.firstChild.style.width = String(nw) + 'px';
    obj.canvas_box_obj.firstChild.style.height = String(nh) + 'px';
  }
  else
  {
    obj.canvas_box_obj.firstChild.style.width = String(new_width) + 'px';
    obj.canvas_box_obj.firstChild.style.height = String(new_height) + 'px';
  }
}

function canvas_close_draw(obj, x, y)
{
  canvas_restore_mousemove();
  obj.canvas_in_draw = false;
  obj.canvas = {
    top: $(obj.canvas_box_obj).Top() - $(obj).Top(),
    left: $(obj.canvas_box_obj).Left() - $(obj).Left(),
    width: $(obj.canvas_box_obj).Width(),
    height: $(obj.canvas_box_obj).Height()
  }
  obj.onclick = function(e)
  {
    canvas_click(this);
  }
}

function canvas_cancel_draw(obj)
{
  canvas_restore_mousemove();
  obj.canvas_in_draw = false;
  obj.removeChild(obj.canvas_box_obj);
  obj.canvas_box_obj = null;
  obj.onclick = function(e)
  {
    canvas_click(this);
  }
  var ga = obj.getAttribute('canvas:oncancel');
  if ( ga )
  {
    eval(ga);
  }
}

