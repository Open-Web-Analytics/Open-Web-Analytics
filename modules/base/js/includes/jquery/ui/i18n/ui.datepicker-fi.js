/* Finnish initialisation for the jQuery UI date picker plugin. */
/* Written by Harri Kilpi� (harrikilpio@gmail.com). */

$(document).ready(function(){
    $.datepicker.regional['fi'] = {
        clearText: 'Tyhjenn&auml;', clearStatus: '',
        closeText: 'Sulje', closeStatus: '',
        prevText: '&laquo;Edellinen', prevStatus: '',
        nextText: 'Seuraava&raquo;', nextStatus: '',
        currentText: 'T&auml;n&auml;&auml;n', currentStatus: '',
        monthNames: ['Tammikuu','Helmikuu','Maaliskuu','Huhtikuu','Toukokuu','Kes&auml;kuu',
        'Hein&auml;kuu','Elokuu','Syyskuu','Lokakuu','Marraskuu','Joulukuu'],
        monthNamesShort: ['Tammi','Helmi','Maalis','Huhti','Touko','Kes&auml;',
        'Hein&auml;','Elo','Syys','Loka','Marras','Joulu'],
        monthStatus: '', yearStatus: '',
        weekHeader: 'Vk', weekStatus: '',
        dayNamesShort: ['Su','Ma','Ti','Ke','To','Pe','Su'],
        dayNames: ['Sunnuntai','Maanantai','Tiistai','Keskiviikko','Torstai','Perjantai','Lauantai'],
        dayNamesMin: ['Su','Ma','Ti','Ke','To','Pe','La'],
        dayStatus: 'DD', dateStatus: 'D, M d',
        dateFormat: 'dd.mm.yy', firstDay: 1,
        initStatus: '', isRTL: false};
    $.datepicker.setDefaults($.datepicker.regional['fi']);
});
