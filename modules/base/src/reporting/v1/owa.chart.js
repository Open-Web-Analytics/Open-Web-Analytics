OWA.chart = function() {

    this.config = OWA.config;

    return;
}

OWA.chart.prototype = {

    properties: new Object,

    config: '',

    dom_id: '',

    data: '',

    height: "100%",

    width: "100%",

    render: function() {

         swfobject.embedSWF(this.config.modules_url + "base/js/includes/" + this.config.ofc_version + "/open-flash-chart.swf", this.dom_id, this.width, this.height, "9.0.0", "expressInstall.swf", {"get-data":"OWA.items['"+this.dom_id+"'].getData", id: this.dom_id});

    },

    getData: function() {

         //alert( 'reading data...obj' );
           return JSON.stringify(this.data);
    },

    setData: function(data) {

        this.data = data;
        return;
    },

    setHeight: function(height) {

        this.height = height;
        return;
    },

    setWidth: function(width) {

        this.width = width;
        return;
    },

    setDomId: function(dom_id) {

        this.dom_id = dom_id;
        return;
    }

}
