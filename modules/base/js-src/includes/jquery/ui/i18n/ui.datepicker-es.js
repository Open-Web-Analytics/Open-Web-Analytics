/* Inicializaci�n en espa�ol para la extensi�n 'UI date picker' para jQuery. */
/* Traducido por Vester (xvester@gmail.com). */
jQuery(function($){
    $.datepicker.regional['es'] = {clearText: 'Limpiar', clearStatus: '',
        closeText: 'Cerrar', closeStatus: '',
        prevText: '&lt;Ant', prevStatus: '',
        nextText: 'Sig&gt;', nextStatus: '',
        currentText: 'Hoy', currentStatus: '',
        monthNames: ['Enero','Febrero','Marzo','Abril','Mayo','Junio',
        'Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'],
        monthNamesShort: ['Ene','Feb','Mar','Abr','May','Jun',
        'Jul','Ago','Sep','Oct','Nov','Dic'],
        monthStatus: '', yearStatus: '',
        weekHeader: 'Sm', weekStatus: '',
        dayNames: ['Domingo','Lunes','Martes','Mi&eacute;rcoles','Jueves','Viernes','S&aacute;dabo'],
        dayNamesShort: ['Dom','Lun','Mar','Mi&eacute;','Juv','Vie','S&aacute;b'],
        dayNamesMin: ['Do','Lu','Ma','Mi','Ju','Vi','S&aacute;'],
        dayStatus: 'DD', dateStatus: 'D, M d',
        dateFormat: 'dd/mm/yy', firstDay: 0,
        initStatus: '', isRTL: false};
    $.datepicker.setDefaults($.datepicker.regional['es']);
});