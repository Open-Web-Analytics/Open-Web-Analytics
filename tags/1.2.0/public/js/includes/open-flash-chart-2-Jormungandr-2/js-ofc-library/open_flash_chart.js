function ofc_chart() {
	this.set_title = function(title) {
		this.title = title;
	};

	this.elements = [];
	this.add_element = function(new_element) {
		this.elements.push(new_element);
	};

	this.set_x_axis = function(axis) {
		this.x_axis = axis;
	};

	this.set_y_axis = function(axis) {
		this.y_axis = axis;
	};
}

function ofc_element(type) {
	this.type = type;

	this.values = [];
	this.set_values = function(values) {
		this.values = values;
	};

	this.set_key = function(text, size) {
		this.text = text;
		this['font-size'] = size;
	};

	this.set_colour = function(colour) {
		this.colour = colour;
	};
}

function ofc_line() {
	ofc_element.apply(this, ["line"]);
}
ofc_line.prototype = new ofc_element();

function ofc_bar() {
	ofc_element.apply(this, ["bar"]);
}
ofc_bar.prototype = new ofc_element();

function ofc_scatter(colour) {
	ofc_element.apply(this, ["scatter"]);
	this.set_colour(colour);

	this.set_default_dot_style = function(dot_style) {
		this['dot-style'] = dot_style;
	};
}
ofc_scatter.prototype = new ofc_element();

function ofc_scatter_line(colour) {
	ofc_element.apply(this, ["scatter_line"]);
	this.set_colour(colour);

	this.set_default_dot_style = function(dot_style) {
		this['dot-style'] = dot_style;
	};

	this.set_step_horizontal = function() {
		this.stepgraph = "horizontal";
	};

	this.set_step_vertical = function() {
		this.stepgraph = "vertical";
	};
}
ofc_scatter_line.prototype = new ofc_element();

function ofc_title(text, style) {
	this.text = text;
	this.style = style;
}

function ofc_axis() {
	this.set_range = function(min, max) {
		this.min = min;
		this.max = max;
	};

	this.set_steps = function(steps) {
		this.steps = steps;
	};

	this.set_stroke = function(stroke) {
		this.stroke = stroke;
	};

	this.set_colour = function(colour) {
		this.colour = colour;
	};

	this.set_grid_colour = function(grid_colour) {
		this['grid-colour'] = grid_colour;
	};

	this.set_offset = function(offset) {
		this.offset = offset;
	};
}

function ofc_x_axis() {
	this.set_tick_height = function(tick_height) {
		this['tick-height'] = tick_height;
	};

	this.set_3d = function(threeD) {
		this['3d'] = threeD;
	};
}
ofc_x_axis.prototype = new ofc_axis();

function ofc_y_axis() {
	this.set_tick_length = function(tick_length) {
		this['tick-length'] = tick_length;
	};

	this.set_grid_visible = function(grid_visible) {
		this['grid-visible'] = grid_visible;
	};

	this.set_visible = function(visible) {
		this.visible = visible;
	};
}
ofc_y_axis.prototype = new ofc_axis();

function ofc_scatter_value(xVal, yVal, dot_size) {
	this.x = xVal || 0;
	this.y = yVal || 0;
	this['dot-size'] = dot_size;
}

function ofc_dot_base(type, value) {
	this.type = type;
	this.value = value;

	this.position = function position(xVal, yVal) {
		this.x = xVal;
		this.y = yVal;
	};
}

function ofc_dot(value) {
	ofc_dot_base.apply(this, ["dot", value]);
}
ofc_dot.prototype = new ofc_dot();

function ofc_hollow_dot(value) {
	ofc_dot_base.apply(this, ["hollow-dot", value]);
}
ofc_hollow_dot.prototype = new ofc_dot_base();

function ofc_solid_dot(value) {
	ofc_dot_base.apply(this, ["solid-dot", value]);
}
ofc_solid_dot.prototype = new ofc_dot();

function ofc_star(value) {
	ofc_dot_base.apply(this, ["star", value]);
}
ofc_star.prototype = new ofc_dot_base();

function ofc_bow(value) {
	ofc_dot_base.apply(this, ["bow", value]);
}
ofc_bow.prototype = new ofc_dot_base();

function ofc_anchor(value) {
	ofc_dot_base.apply(this, ["anchor", value]);
}
ofc_anchor.prototype = new ofc_dot_base();
