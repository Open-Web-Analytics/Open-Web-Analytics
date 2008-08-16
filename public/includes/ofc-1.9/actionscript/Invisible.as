class Invisible
{
	//
	// This is a FILTHY hack.
	//
	// A flash movie CAN NOT tell when the mouse has left it,
	// so we use this invisible layer to do the job for us.
	//
	// For this to work we need to pass mouse_move events down
	// to the bars and lines.
	//
	private var mc:MovieClip;
	
	// when we hover over an item, it gives us
	// the on_click stuff to deal with (because it
	// never gets the click event (see hack above)
	private var link:String;
	
	function Invisible( lv:Object )
	{
		this.mc = _root.createEmptyMovieClip( "tooltipX_mouse_out", _root.getNextHighestDepth() );
		
		//
		// ask _root to remove our tool tip
		//
		this.mc.onRollOut = function() {
			_root.mouse_over( false );		// <-- tell every item we are NOT over it
			_root.tooltip_x.hide();
			};
			
		this.mc.onMouseMove = _root.mouse_move;
		
		var tmp = this;
		this.mc.onRelease = function() {
			trace( 'Click '+tmp.link );
			if( tmp.link != undefined )
				getURL( tmp.link );
				
		};
		
		this.mc.useHandCursor = false;
	}
	
	function move( b:Box )
	{
		this.mc.clear();
		this.mc.rect2( 0, 0, b.width, b.height, 0, 0 );	// <-- set alpha to 50 for debug!
		this.mc._x = b.left;
		this.mc._y = b.top;
	}
	
	function hitTest( x:Number, y:Number )
	{
		return this.mc.hitTest( x, y );
	}
	
	function use_hand( link:String )
	{
		this.mc.useHandCursor = true;
		this.link = link;
	}
	
	function use_arrow()
	{
		this.mc.useHandCursor = false;
		this.link = undefined;
	}
}