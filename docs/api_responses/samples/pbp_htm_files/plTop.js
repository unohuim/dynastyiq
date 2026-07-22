( function(window, aDocument, undefined) {

    window.onload = highlight_selection;

    function highlight_selection() {
        var myurl = window.location.href;
        var urlelems = myurl.split('#');
        if (urlelems.length == 2) {
            var event_ids = urlelems[1].split(',');
            for (var ii=0; ii < event_ids.length; ii++) {
                var ev_id = event_ids[ii];
                var ev = document.getElementById(event_ids[ii]);
                if (ev) {
                    ev.style.background = "#CCAA99";
                }
            }
        }
    }
} )(window, document, undefined);

document.write('<script language="JavaScript" src="http://www.nhl.com/scripts/omniture/s_code.js"></script>\n');

/************* GoogAnal **************/
var gaJsHost = (("https:" == document.location.protocol) ? "https://ssl." : "http://www.");
document.write(unescape("%3Cscript src='" + gaJsHost + "google-analytics.com/ga.js' type='text/javascript'%3E%3C/script%3E"));
