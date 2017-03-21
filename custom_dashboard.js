<script type='text/javascript'>

$(document).ready(function() {
		// Nothing right now
});


// COPIED FROM Resources/js/ExternalLinks.js
function updateResourcePanel() {
	if (pid == 'null') return;
	$.post(app_path_webroot+'ExternalLinks/render_resource_panel_ajax.php?pid='+pid, { }, function(data){
		if (data == '0') {
			alert(woops);
		} else {
			// Update the left-hand menu
			$('#extres_panel').remove();
			if ($('#global_ext_links').length) {
				$('#global_ext_links').after(data);
			} else {
				$('#app_panel').after(data);
			}
		}
	});
}

// Get all of the parameters from the page (called by all update functions)
function getDashboardParams() {
	var params = {
		'settings': {
			'title': $('#dashboard_title').val(),
			'ext_id': $('#ext_id').val(),
			'description': $('#dashboard_description').val(),
			'arm': $('#arm_num').val(),
			'record_label': $('#record_label').val(),
			'filter': $('#filter_logic').val(),
			'group_by': $('#group_by').val(),
			'num_per_page': $('#num_per_page').val(),
			'pagenum': $('#pagenum').val(),
			'excluded_forms': $('#excluded_forms').val(),
			'excluded_events': $('#excluded_events').val(),
			'vertical_header': $('#vertical_header').val()
		}
	};
	return params;
}

// Called when saving a dashboard without an existing ext_id
function saveNewDashboard() {
	$('#ext_id').val(null);
	saveDashboard();
}

// Updates the current view to the loaded external link
function saveDashboard() {
	var params = getDashboardParams();
	var fullParams = $.extend({
		'action':'saveDashboard',
		'pid':pid,
		'settings': { }
	},params);
	
	$.ajax({
		type: 'POST',
		url: '',
		cache: false,
		data: fullParams
	}).done(function(data){
		var data = JSON.parse(data);
		
		// Remove any previous popups
		var valPopupId = 'saveResult';
		$('#'+valPopupId).remove();
		
		if(data.errors) {
			simpleDialog(data.errors,'Error Creating Dashboard', valPopupId, 300);
		} else {
			if(data.new_ext_id) {
				// Set the ext_id for future updates
				$('#ext_id').val(data.new_ext_id);
				// Show the 'save' and 'delete' buttons
				$('#btn_save').show();
				$('#btn_delete').show();
				msg = "A new bookmark called " + data.link_label + " has been created with the specified settings.  You can customize the viewing permissions for this bookmark if you wish to control access.";
				title = "Dashboard Created";
			} else {
				// Existing ID updated
				msg = data.link_label + " was updated.";
				title = "Dashboard Updated";
			}
			// Update external bookmarks panel
			updateResourcePanel();
			refreshDashboard();
			$('#configbox').slideToggle();
			simpleDialog(msg,title,valPopupId, 300, 
					"",'Close', 
					"window.location.href = app_path_webroot + 'ExternalLinks/index.php?pid=' + pid","Edit Bookmark Permissions");
		}
	});
}

// Deletes the external link for the dashboard
function deleteDashboard() {
	$.ajax({
		type: 'POST',
		url: '',
		cache: false,
		data: {
			'action':'deleteDashboard',
			'pid':pid,
			settings: {
				'ext_id':$('#ext_id').val()
			}
		}
	}).done(function(data){
		var valPopupId = 'deleteResult';
		// Remove any previous popups
		$('#'+valPopupId).remove();
		var data = JSON.parse(data);
		//console.log('data:');console.log(data);
		if(data.errors) {
			simpleDialog(data.errors,"Error Deleting Custom Dashboard",valPopupId,300);
			//console.log('Found Errors when Deleting!');
		} else {
			$('#ext_id').val(null);
			$('#btn_save').hide();
			$('#btn_delete').hide();
			simpleDialog(data.link_label + " deleted","Success",valPopupId,300);
		}
		// Update external bookmarks panel
		//updateResourcePanel();
		// Redirect to a "clean" custom dashboard
		window.location = window.location.pathname + "?pid=" + pid;
	});
}

// Master function called when a full refresh is needed.
function refreshDashboard() {
	ajaxUpdate('cd_container', 'getDashboard');
}

// This is called when we are not changing the filter, but rather changing pagination/group-by options
function refreshTable() {
	ajaxUpdate('table_container', 'getTableContainer');
}

// Called to update a container with the results from the given action method
function ajaxUpdate(container_id, action) {
	var $c = $('#' + container_id);
	
	// Fade in overlay for progress indicator
	$('#overlay').css({
		opacity : 0.8,
		top     : $c.offset().top,
		left    : $c.position().left,
		width   : $c.outerWidth(),
		height  : $c.outerHeight()
	}).fadeIn();
	
	//	showProgress(1);
	//	$c.fadeOut(1000);
	
	// Merge optional params into default values (http://stackoverflow.com/questions/929776/merging-associative-arrays-javascript)
	var params = getDashboardParams();
	var fullParams = $.extend({
		'action':action,
		'pid':pid,
		'settings': { }
	},params);
	//console.log('Dashboard fullParams'); console.log(fullParams);
	
	$.ajax({
		type: 'POST',
		url: '',
		cache: false,
		data: fullParams
	}).done(function(result){
		//console.log('renderDone:'); console.log(result);
		//showProgress(0);
		$c.html(result);
		$("#overlay").fadeOut();
	});
}

// Checks to make sure label is valid on blur
function testRecordLabel() {
	// Don't do anything if this isn't a longitudinal project
	if (!longitudinal) return;
	 
	var params = {
		'action':'testRecordLabel',
		'settings': {
			'record_label': $('#record_label').val()
		}
	};
	
	$.ajax({
		type: 'POST',
		url: '',
		cache: false,
		data: params
	}).done(function(data){
		var valPopupId = 'testResult';
		// Remove any previous popups
		$('#'+valPopupId).remove();
		var data = JSON.parse(data);
		//console.log(data);
		if(data.valid == true) {
			//console.log('looks good');
			// Looks good
		} else {
			simpleDialog(data.msg,'Abmiguous Events In Record Label', 
				valPopupId, 450,
				'$("#record_label").show("highlight").focus();','Cancel',
				'$("#record_label").val("'+data.new_logic+'");','Accept Modified Label');
		}
	});
}

// This function is called when the logic is modified to make sure it is valid
function testFilter() {
	// Don't do anything if this isn't a longitudinal project
	if (!longitudinal) return;
	 
	var params = {
		'action':'testFilter',
		'settings': {
			'filter': $('#filter_logic').val()
		}
	};
	
	$.ajax({
		type: 'POST',
		url: '',
		cache: false,
		data: params
	}).done(function(data){
		var valPopupId = 'testResult';
		// Remove any previous popups
		$('#'+valPopupId).remove();
		var data = JSON.parse(data);
		//console.log(data);
		if(data.valid == true) {
			//console.log('looks good');
			// Looks good
		} else {
			simpleDialog(data.msg,'Abmiguous Events In Filter', 
				valPopupId, 450,
				'$("#filter_logic").show("highlight").focus();','Cancel',
				'$("#filter_logic").val("'+data.filter_explicit+'");','Accept Modified Filter');
		}
	});
}


/* 
	SECTION FOR FANCY EXCLUDE DIV FEATURES

	This whole part of the UI should probably be changed... I tried to reuse some existing REDCap stuff
	but there is a lot of overhead here that is probably unnecessary...

*/

// Called by clicking on a header cell - adds the selected cell to the corresponding exclude list and refreshes the view
function excludeHeader(form_or_event, id) {
	//console.log("form_or_event: " + form_or_event + " / id: " + id);
	if (form_or_event == 'form') {
		$('#___' + id).prop('checked',1);
		excludeFormsUpdate(1);
	}
	if (form_or_event == 'event') {
		var cb = $('input[event_id="' + id + '"]', '#choose_exclude_events_div');
		//console.log("event checkbox");console.log(cb);
		$(cb).prop('checked',1);
		excludeEventsUpdate(1);
	}
}

function toggleExcludeForms() {
	$('#choose_exclude_forms_div').toggle();
	//console.log('getExcludeForms');
}

function toggleExcludeEvents() {
	$('#choose_exclude_events_div').toggle();
	//console.log('getExcludeForms');
}

function excludeFormsAll(select_all) {
	var do_select_all = (select_all == 1);
	$('#choose_exclude_forms_div input:checkbox').each(function(){
		$(this).prop('checked',do_select_all);
	});
}

function excludeEventsAll(select_all) {
	var do_select_all = (select_all == 1);
	$('#choose_exclude_events_div input:checkbox').each(function(){
		$(this).prop('checked',do_select_all);
	});
}

function excludeFormsUpdate(num) {
	var update = (num == 1);
	var checked = new Array();
	//toggleExcludeForms
	$('#choose_exclude_forms_div').hide();
	if (update) {
		$('#choose_exclude_forms_div input:checkbox:checked').each(function(){
			var form_name = $(this).attr('id').replace("___","");
			checked.push(form_name);
		});
		$('#excluded_forms').val( checked.join() );
		refreshDashboard();
	}
}

function excludeEventsUpdate(num) {
	var update = (num == 1);
	var checked = new Array();
	//toggleExcludeEvents();
	$('#choose_exclude_events_div').hide();
	if (update) {
		$('#choose_exclude_events_div input:checkbox:checked').each(function(){
			var event_name = $(this).attr('id').replace("___","");
			checked.push(event_name);
		});
		$('#excluded_events').val( checked.join() );
		refreshDashboard();
	}
}







// This allows the text boxes to grow when adding large amounts of logic or code.  The maximum size can be set by the max-height css attribute.  To activate a textarea you simply autosize( $(elements) );
/*!
	Autosize 3.0.8
	license: MIT
	http://www.jacklmoore.com/autosize
*/
!function(e,t){if("function"==typeof define&&define.amd)define(["exports","module"],t);else if("undefined"!=typeof exports&&"undefined"!=typeof module)t(exports,module);else{var o={exports:{}};t(o.exports,o),e.autosize=o.exports}}(this,function(e,t){"use strict";function o(e){function t(){var t=window.getComputedStyle(e,null);"vertical"===t.resize?e.style.resize="none":"both"===t.resize&&(e.style.resize="horizontal"),u="content-box"===t.boxSizing?-(parseFloat(t.paddingTop)+parseFloat(t.paddingBottom)):parseFloat(t.borderTopWidth)+parseFloat(t.borderBottomWidth),i()}function o(t){var o=e.style.width;e.style.width="0px",e.offsetWidth,e.style.width=o,v=t,l&&(e.style.overflowY=t),n()}function n(){var t=window.pageYOffset,o=document.body.scrollTop,n=e.style.height;e.style.height="auto";var i=e.scrollHeight+u;return 0===e.scrollHeight?void(e.style.height=n):(e.style.height=i+"px",document.documentElement.scrollTop=t,void(document.body.scrollTop=o))}function i(){var t=e.style.height;n();var i=window.getComputedStyle(e,null);if(i.height!==e.style.height?"visible"!==v&&o("visible"):"hidden"!==v&&o("hidden"),t!==e.style.height){var r=document.createEvent("Event");r.initEvent("autosize:resized",!0,!1),e.dispatchEvent(r)}}var r=void 0===arguments[1]?{}:arguments[1],d=r.setOverflowX,s=void 0===d?!0:d,a=r.setOverflowY,l=void 0===a?!0:a;if(e&&e.nodeName&&"TEXTAREA"===e.nodeName&&!e.hasAttribute("data-autosize-on")){var u=null,v="hidden",f=function(t){window.removeEventListener("resize",i),e.removeEventListener("input",i),e.removeEventListener("keyup",i),e.removeAttribute("data-autosize-on"),e.removeEventListener("autosize:destroy",f),Object.keys(t).forEach(function(o){e.style[o]=t[o]})}.bind(e,{height:e.style.height,resize:e.style.resize,overflowY:e.style.overflowY,overflowX:e.style.overflowX,wordWrap:e.style.wordWrap});e.addEventListener("autosize:destroy",f),"onpropertychange"in e&&"oninput"in e&&e.addEventListener("keyup",i),window.addEventListener("resize",i),e.addEventListener("input",i),e.addEventListener("autosize:update",i),e.setAttribute("data-autosize-on",!0),l&&(e.style.overflowY="hidden"),s&&(e.style.overflowX="hidden",e.style.wordWrap="break-word"),t()}}function n(e){if(e&&e.nodeName&&"TEXTAREA"===e.nodeName){var t=document.createEvent("Event");t.initEvent("autosize:destroy",!0,!1),e.dispatchEvent(t)}}function i(e){if(e&&e.nodeName&&"TEXTAREA"===e.nodeName){var t=document.createEvent("Event");t.initEvent("autosize:update",!0,!1),e.dispatchEvent(t)}}var r=null;"undefined"==typeof window||"function"!=typeof window.getComputedStyle?(r=function(e){return e},r.destroy=function(e){return e},r.update=function(e){return e}):(r=function(e,t){return e&&Array.prototype.forEach.call(e.length?e:[e],function(e){return o(e,t)}),e},r.destroy=function(e){return e&&Array.prototype.forEach.call(e.length?e:[e],n),e},r.update=function(e){return e&&Array.prototype.forEach.call(e.length?e:[e],i),e}),t.exports=r});



</script>



<style type='text/css'>
#configbox textarea {
	max-height: 300px;
}


#configbox .td1 {
	font-weight:bold;
	width:20%;
}

#configbox .td2 {
	width:80%;
}

/* Enable a red-x when hovering over headers for exclusion */
.excludable:hover {
		cursor:pointer;
		background: rgba(255,0,0,0.4);
		background-image: url('data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAkAAAAJCAYAAADgkQYQAAAAB3RJTUUH2goaDCooWaGnBQAAAAlwSFlzAAALEgAACxIB0t1+/AAAALRJREFUeNpj/P//PwMhwAIiVjEyegCpFiCu+cPAsAMoCOeH/f+/gwFk0hIGhjP/Skr+g2ggrkbmg20CEfOAOucCBX4kJ/9HphcyMBjDFU0FmgvE1UB85oO3938QPYWBobCHASLPBHLTN6BJ3xkYAoMNDY3nbN16FkT/YGCI/g9xG0RlM1DnQ1nZ/yAa6NpCZD5InhFE5AF9B/RViwADQzoXA8PZV0ATQHygL2smAX3HSEw4AQBKK2empQli4AAAAABJRU5ErkJggg==');
		background-position: 100% 0%;
		background-repeat: no-repeat;
}



/* Vertical CSS form https://github.com/kizu/kizu.github.com/blob/master/demos/rotated-text.html */
.vertical-text {
    display: inline-block;
    overflow: hidden;
    width: 1.0em;
    line-height: 1.0;
}
.vertical-text-inner {
    display: inline-block;
      white-space: nowrap;
      -webkit-transform: translate(0,100%) rotate(-90deg);
         -moz-transform: translate(0,100%) rotate(-90deg);
          -ms-transform: translate(0,100%) rotate(-90deg);
           -o-transform: translate(0,100%) rotate(-90deg);
              transform: translate(0,100%) rotate(-90deg);
      -webkit-transform-origin: 0 0;
         -moz-transform-origin: 0 0;
          -ms-transform-origin: 0 0;
           -o-transform-origin: 0 0;
              transform-origin: 0 0;
}
.vertical-text-inner:after {
     content: "";
     float: left;
     margin-top: 100%;
}



/* This is the strip for a progress when refreshing the dashboard...  If you like effect, can add the actual image to resources: https://i.stack.imgur.com/tQTRW.gif */
#overlay {
	display:none;
	position:absolute;
	background: url('data:image/gif;base64,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') repeat;
}
/*
#img-load {
	position:absolute;
}
*/

#chose_exclude_events_table td {
	vertical-align:top;
}

#chose_exclude_events_table th {
	font-weight:bold;
}


#choose_exclude_forms_div, #choose_exclude_events_div {
	min-width:280px;
	background: transparent url(<?php echo APP_PATH_IMAGES ?>upArrow.png) no-repeat center top;
	position:absolute;
	z-index:10;
	padding:9px 0 0;
	display: none;
	font-size:11px;
}
#choose_exclude_forms_div_sub, #choose_exclude_events_div_sub {
	background-color: #fafafa;
	padding:3px 6px 10px;
	border:1px solid #000;
}


</style>