;(function (GravityFlowFeedSettings, $) {

	"use strict";

	$(document).ready(function () {

		var multiSelectWithAjaxSearch = {
			selectableHeader: "<input type='text' class='search-input' autocomplete='off' placeholder='" + gravityflow_form_settings_js_strings.assigneeSearchPlaceholder + "'>",
			selectionHeader: "<input type='text' class='search-input' autocomplete='off' placeholder='" + gravityflow_form_settings_js_strings.assigneeSearchPlaceholder + "'>",

			afterInit: function ( ms ) {
				var that = this,
					selectionSearchString = '#' + ms.attr( 'id' ) + ' .ms-elem-selection.ms-selected',
					allOptions = {},
					initialOptions = {};

				that.$selectableSearch = that.$selectableUl.prev(); // Store as a property
				that.$selectionSearch = that.$selectionUl.prev();

				if ( $( '#'+ms.attr('id' )+' .ms-elem-selectable').length > 10 ) {
					$( '.ms-container .search-input' ).show();
				}

				that.$selectableSearch.show();
				that.$selectionSearch.show();

				// Fetch users with ajax.
				function fetchUsers( query, is_init ) {
					is_init = typeof is_init !== 'undefined' ? is_init : false;
					var selectedValues = that.$element.find( 'option:selected' ).map(function () {
						return $( this ).val();
					} ).get();

					$.ajax( {
						url: '/wp-admin/admin-ajax.php',
						method: 'POST',
						data: {
							action: 'gravityflow_fetch_assignees',
							search: query,
							form_id: gravityflow_form_settings_js_strings['formId'],
							nonce: gravityflow_form_settings_js_strings.ajax_search_nonce,
						},
						success: function ( response ) {
							if ( ! response.success ) {
								console.error( 'Error fetching assignees:', response.data.message );
								return;
							}

							// Initial load: populate full list. This will be populated by the settings API, but we need to load it in JS here too.
							if ( query.length === 0 && $.isEmptyObject( allOptions ) ) {
								allOptions = {};
								initialOptions = {};
								populateInitialOptions( response.data.results, selectedValues, is_init );
							}

							// Update selectable list only for subsequent calls.
							if ( query.length > 0 || ( query.length === 0 && !$.isEmptyObject( allOptions ) ) ) {
								that.$selectableUl.empty();
								if ( query.length >= 3 && response.data.results ) {
									populateSearchResults( response.data.results, selectedValues );
								} else if ( query.length === 0 ) {
									rebuildWithOptGroups( initialOptions, selectedValues );
								}
							}
						},
						error: function ( xhr, status, error ) {
							console.error( 'AJAX request failed:', status, error );
						}
					} );
				}

				function populateInitialOptions( choices, selectedValues, is_init ) {
					$.each( choices, function ( groupIndex, group ) {
						var $optgroup = that.$element.find( 'optgroup[label="' + group.label + '"]' );
						$.each( group.choices, function ( choiceIndex, choice ) {
							allOptions[choice.value] = { text: choice.label, group: group.label };
							initialOptions[choice.value] = { text: choice.label, group: group.label };
							if ( ! is_init || that.$element.find( 'option[value="' + choice.value + '"]').length === 0 ) {
								var $option = $( '<option>', {
									value: choice.value,
									text: choice.label,
									selected: $.inArray( choice.value, selectedValues ) !== -1
								} );
								$optgroup.append( $option );
							}
						} );
					} );

					if ( ! is_init ) {
						that.$element.empty();
						that.$selectableUl.empty();
						that.$selectionUl.empty();
						rebuildWithOptGroups( initialOptions, selectedValues );
					}
				}

				function populateSearchResults( results, selectedValues ) {
					that.$selectableUl.empty();

					$.each( results, function( groupName, group ) {
						if ( ! group || ! group.choices || ! group.choices.length ) {
							var length = group.choices.length;
							return true; // Skip empty groups.
						}

						var $groupContainer = $( '<li class="ms-optgroup-container"></li>' ).append(
							$( '<ul class="ms-optgroup"></ul>' ).append(
								$( '<li class="ms-optgroup-label"><span>' + group.label + '</span></li>' )
							)
						);

						var hasItems = false;
						$.each( group.choices, function( index, item ) {
							var value = item.value;
							var text = item.label;

							if ( ! allOptions[value] ) {
								allOptions[value] = { text: text, group: group.label };
								var $option = $( '<option>', {
									value: value,
									text: text,
									selected: $.inArray( value, selectedValues ) !== -1
								} );
								var $optgroup = that.$element.find( 'optgroup[label="' + group.label + '"]' );
								if ( $optgroup.length === 0 ) {
									$optgroup = $( '<optgroup>', { label: group.label } );
									that.$element.append( $optgroup );
								}

								// Check if option already exists before appending
								if ( $optgroup.find('option[value="' + value + '"]').length === 0 ) {
									var $option = $( '<option>', {
										value: value,
										text: text,
										selected: $.inArray( value, selectedValues ) !== -1
									} );
									$optgroup.append( $option );
								}
							}

							if ( $.inArray( value, selectedValues ) === -1 ) {
								var $li = $( '<li>', {
									'class': 'ms-elem-selectable',
									'id': that.sanitize( value ) + '-selectable',
									'data-ms-value': value
								} ).append( '<span>' + text + '</span>' );
								$groupContainer.find( '.ms-optgroup' ).append( $li );
								hasItems = true;
							}
						});

						if ( hasItems ) {
							that.$selectableUl.append( $groupContainer );
						}
					});
				}

				function rebuildWithOptGroups( options, selectedValues ) {
					var groups = {};
					$.each( options, function ( value, option ) {
						if ( ! groups[option.group] ) {
							groups[option.group] = [];
						}
						groups[option.group].push( { value: value, text: option.text } );
					});

					$.each( groups, function ( groupLabel, options ) {
						var $groupContainer = $( '<li class="ms-optgroup-container"></li>' ).append(
							$( '<ul class="ms-optgroup"></ul>' ).append(
								$( '<li class="ms-optgroup-label"><span>' + groupLabel + '</span></li>' )
							)
						);
						var hasItems = false;
						$.each( options, function ( index, opt ) {
							if ( $.inArray( opt.value, selectedValues ) === -1 ) {
								var $li = $( '<li>', {
									'class': 'ms-elem-selectable',
									'id': that.sanitize( opt.value ) + '-selectable',
									'data-ms-value': opt.value
								} ).append( '<span>' + opt.text + '</span>' );
								$groupContainer.find( '.ms-optgroup' ).append( $li );
								hasItems = true;
							}
						} );
						if ( hasItems ) {
							that.$selectableUl.append( $groupContainer );
						}
					} );
				}

				fetchUsers( '', true );

				// Handle the search input in the selectable list to run the ajax search.
				that.$selectableSearch.on( 'input', window.gform.tools.debounce( function ( e ) {
					var query = $( this ).val().trim();
					fetchUsers( query );
				}, 300 ) );

				// The selected side still just uses quicksearch since there's no need for ajax here.
				that.qs2 = that.$selectionSearch.quicksearch( selectionSearchString )
					.on( 'keydown', function ( e ) {
						if ( e.which === 40 ) {
							that.$selectionUl.focus();
							return false;
						}
					} );
			},

			afterSelect: function ( values ) {
				var that = this;
				this.qs2.cache();
				$.each( values, function ( i, value ) {
					var $option = that.$element.find( 'option[value="' + value + '"]' );
					$option.prop( 'selected', true );
					var $optgroup = $option.closest( 'optgroup' );
					var groupLabel = $optgroup.length ? $optgroup.attr( 'label' ) : null;
					var $li = that.$selectionUl.find( '#' + that.sanitize( value ) + '-selection' );

					if ( $li.length === 0 ) {
						$li = $( '<li>', {
							'class': 'ms-elem-selection ms-selected',
							'id': that.sanitize( value ) + '-selection',
							'data-ms-value': value
						} ).append( '<span>' + $option.text() + '</span>' ).show();
					} else {
						$li.addClass( 'ms-selected' ).show();
					}

					if ( groupLabel ) {
						var $groupContainer = that.$selectionUl.find( '.ms-optgroup-container' ).filter( function () {
							return $( this ).find( '.ms-optgroup-label span' ).text() === groupLabel;
						} );
						if ( $groupContainer.length === 0 ) {
							$groupContainer = $( '<li class="ms-optgroup-container"></li>' ).append(
								$( '<ul class="ms-optgroup"></ul>' ).append(
									$( '<li class="ms-optgroup-label"><span>' + groupLabel + '</span></li>' )
								)
							);
							that.$selectionUl.append( $groupContainer );
						}
						$groupContainer.find( '.ms-optgroup' ).append( $li );
						$groupContainer.find( '.ms-optgroup-label' ).show();
					} else {
						that.$selectionUl.append( $li );
					}

					that.$selectableUl.find( '#' + that.sanitize( value ) + '-selectable' ).addClass( 'ms-selected' ).hide();
				} );
			},

			afterDeselect: function ( values ) {
				var that = this;
				this.qs2.cache();
				$.each( values, function ( i, value ) {
					var $option = that.$element.find( 'option[value="' + value + '"]' );
					$option.prop( 'selected', false );
					var $selectableLi = that.$selectableUl.find( '#' + that.sanitize( value ) + '-selectable' );
					var currentQuery = that.$selectableSearch.val().trim().toLowerCase();
					var text = $option.text().toLowerCase();
					var shouldShow = currentQuery.length < 3 || text.indexOf( currentQuery ) !== -1;
					if ( $selectableLi.length === 0 ) {
						$selectableLi = $( '<li>', {
							'class': 'ms-elem-selectable',
							'id': that.sanitize( value ) + '-selectable',
							'data-ms-value': value
						} ).append( '<span>' + $option.text() + '</span>' );
						var $optgroup = $option.closest( 'optgroup' );
						var groupLabel = $optgroup.length ? $optgroup.attr( 'label' ) : 'Users';
						var $groupContainer = that.$selectableUl.find( '.ms-optgroup-container' ).filter( function () {
							return $( this ).find( '.ms-optgroup-label span' ).text() === groupLabel;
						} );
						if ( $groupContainer.length === 0 ) {
							$groupContainer = $( '<li class="ms-optgroup-container"></li>' ).append(
								$( '<ul class="ms-optgroup"></ul>' ).append(
									$( '<li class="ms-optgroup-label"><span>' + groupLabel + '</span></li>' )
								)
							);
							that.$selectableUl.append( $groupContainer );
						}
						$groupContainer.find( '.ms-optgroup' ).append( $selectableLi );
					}
					that.$selectionUl.find('#' + that.sanitize(value) + '-selection').remove();
					$selectableLi.removeClass( 'ms-selected' ).toggle( shouldShow );
				} );

				that.$selectionUl.children( '.ms-optgroup-container' ).each( function () {
					var $group = $( this );
					var hasSelected = $group.find( '.ms-elem-selection.ms-selected' ).length > 0;
					$group.find( '.ms-optgroup-label' ).toggle( hasSelected );
					if ( ! hasSelected ) {
						$group.remove();
					}
				} );
			}
		};

		var multiSelectWithSearch = {
			selectableHeader: "<input type='text' class='search-input' autocomplete='off' placeholder='"+gravityflow_form_settings_js_strings.assigneeSearchPlaceholder+"'>",
			selectionHeader: "<input type='text' class='search-input' autocomplete='off' placeholder='"+gravityflow_form_settings_js_strings.assigneeSearchPlaceholder+"'>",
			afterInit: function(ms){
				var that = this,
					$selectableSearch = that.$selectableUl.prev(),
					$selectionSearch = that.$selectionUl.prev(),
					selectableSearchString = '#'+ms.attr('id')+' .ms-elem-selectable:not(.ms-selected)',
					selectionSearchString = '#'+ms.attr('id')+' .ms-elem-selection.ms-selected';

				$('.ms-container .search-input').show();

				that.qs1 = $selectableSearch.quicksearch(selectableSearchString)
					.on('keydown', function(e){
						if (e.which === 40){
							that.$selectableUl.focus();
							return false;
						}
					});

				that.qs2 = $selectionSearch.quicksearch(selectionSearchString)
					.on('keydown', function(e){
						if (e.which == 40){
							that.$selectionUl.focus();
							return false;
						}
					});
			},
			afterSelect: function(){
				this.qs1.cache();
				this.qs2.cache();
			},
			afterDeselect: function(){
				this.qs1.cache();
				this.qs2.cache();
			}
		};

		if ( gravityflow_form_settings_js_strings.ajaxSearchEnabled === "1" ) {
			$('#assignees, #workflow_notification_users').multiSelect(multiSelectWithAjaxSearch);
		} else {
			$('#assignees, #workflow_notification_users').multiSelect(multiSelectWithSearch);
		}

		$('#editable_fields').multiSelect( multiSelectWithSearch );
		$( '#display_fields_selected' ).multiSelect( multiSelectWithSearch );

		var gravityFlowIsDirty = false, gravityFlowSubmitted = false;

		$('form#gform-settings').submit(function () {
			gravityFlowSubmitted = true;
			$('form#gform-settings').find(':input').removeAttr('disabled');
		});

		$(':input').change(function () {
			// ignore form switcher and step switcher when checking for dirty
			if ($(this).hasClass('gform-dropdown__search-input')) {
				return;
			}
			gravityFlowIsDirty = true;
		});

		window.onbeforeunload = function () {
			if (gravityFlowIsDirty && !gravityFlowSubmitted) {
				return "You have unsaved changes.";
			}
		};

		var $stepType = $('input[name=_gform_setting_step_type]:checked');
		var selectedStepType = $stepType.val();

		var $statusExpiration = $('#status_expiration');
		var expiredSelected = $statusExpiration.val() == 'expired';
		$('#expiration_sub_setting_destination_expired').toggle(expiredSelected);
		$statusExpiration.change(function () {
			var show = $(this).val() == 'expired';
			show ? $('#expiration_sub_setting_destination_expired').show() : $('#expiration_sub_setting_destination_expired').hide();
		});

		setSubSettings();

		GravityFlowFeedSettings.getUsersMarkup = function (propertyName) {
			var i, n, account,
				accounts = gf_routing_setting_strings['accounts'],
				str = '<select class="gform-routing-users ' + propertyName + '_{i}">';

			for (i = 0; i < accounts.length; i++) {
				account = accounts[i];
				if (typeof account.choices != 'undefined') {
					var optgroup = '', choice;
					for (n = 0; n < account.choices.length; n++) {
						choice = account.choices[n];
						optgroup += '<option value="{0}">{1}</option>'.format(choice.value, choice.label);
					}
					str += '<optgroup label="{0}">{1}</option>'.format(account.label, optgroup);

				} else {
					str += '<option value="{0}">{1}</option>'.format(account.value, account.label);
				}
			}

			str += "</select>";
			return str;
		};

		// Workflow Notification

		$('#gform_setting_workflow_notification_type input[type=radio]').click(function () {
			toggleWorkflowNotificationType(this.value);
		});

		var workflowNotificationEnabled = $('#workflow_notification_enabled').prop('checked');
		toggleWorkflowNotificationSettings(workflowNotificationEnabled);
		$('#workflow_notification_enabled').click(function () {
			toggleWorkflowNotificationSettings(this.checked);
		});

		var $workflowNotificationRoutingSetting = $('#gform_user_routing_setting_workflow_notification_routing');

		var workflowNotificationRoutingJSON = $('#workflow_notification_routing').val();

		var workflow_notification_routing_items = workflowNotificationRoutingJSON ? $.parseJSON(workflowNotificationRoutingJSON) : null;

		if (!workflow_notification_routing_items) {
			var accounts = gf_routing_setting_strings && gf_routing_setting_strings['accounts'];
			var choices = accounts && accounts[0] && accounts[0]['choices'];
			var assignee = choices && choices[0] && choices[0]['value'] ? choices[0]['value'] : null;
			workflow_notification_routing_items = [{
				assignee: assignee,
				fieldId: '0',
				operator: 'is',
				value: '',
				type: '',
			}];
			$('#workflow_notification_routing').val($.toJSON(workflow_notification_routing_items));
		}

		var workflowNotificationOptions = {
			fieldName: $workflowNotificationRoutingSetting.data('field_name'),
			fieldId: $workflowNotificationRoutingSetting.data('field_id'),
			settings: gf_routing_setting_strings['fields'],
			accounts: gf_routing_setting_strings['accounts'],
			imagesURL: gf_vars.baseUrl + "/images",
			items: workflow_notification_routing_items,
			callbacks: {
				addNewTarget: function (obj, target) {
					var str = GravityFlowFeedSettings.getUsersMarkup('assignee');
					return str;
				}
			}
		};

		$workflowNotificationRoutingSetting.gfRoutingSetting(workflowNotificationOptions);

		// Notification Tabs

		GravityFlowFeedSettings.initNotificationTab = function (type) {
			if ( gravityflow_form_settings_js_strings.ajaxSearchEnabled === "1" ) {
				$( '#' + type + '_notification_users' ).multiSelect( multiSelectWithAjaxSearch );
			} else {
				$( '#' + type + '_notification_users' ).multiSelect( multiSelectWithSearch );
			}

			var $enabledSetting = $('#' + type + '_notification_enabled');

			toggleNotificationTabSettings($enabledSetting.prop('checked'), type);

			$enabledSetting.click(function () {
				toggleNotificationTabSettings(this.checked, type);
			});

			$('#gform-setting-tab-field-' + type + '_notification_type input[type=radio]').click(function () {
				toggleNotificationTabSettings(true, type);
			});

			var $routingSetting = $('#gform_user_routing_setting_' + type + '_notification_routing');

			var props = {
				routingType: type // or any other value you want to assign
			};

			$routingSetting.attr( 'data-js-props', JSON.stringify( props ) );

			if ($routingSetting.length) {
				var $routingJSONInput = $('#' + type + '_notification_routing'),
					routingJSON = $routingJSONInput.val(),
					routingItems = routingJSON ? $.parseJSON(routingJSON) : null;

				if (!routingItems) {
					var accounts = gf_routing_setting_strings && gf_routing_setting_strings['accounts'];
					var choices = accounts && accounts[0] && accounts[0]['choices'];
					var assignee = choices && choices[0] && choices[0]['value'] ? choices[0]['value'] : null;
					routingItems = [{
						assignee: assignee,
						fieldId: '0',
						operator: 'is',
						value: '',
						type: ''
					}];
					$routingJSONInput.val($.toJSON(routingItems));
				}

				var routingOptions = {
					fieldName: $routingSetting.data('field_name'),
					fieldId: $routingSetting.data('field_id'),
					settings: gf_routing_setting_strings['fields'],
					accounts: gf_routing_setting_strings['accounts'],
					imagesURL: gf_vars.baseUrl + "/images",
					items: routingItems,
					callbacks: {
						addNewTarget: function (obj, target) {
							return GravityFlowFeedSettings.getUsersMarkup('assignee');
						}
					}
				};

				$routingSetting.gfRoutingSetting(routingOptions);
			}

		};

		var notificationTabs = ['assignee', 'rejection', 'approval', 'in_progress', 'complete', 'revert'];

		for (var i = 0; i < notificationTabs.length; i++) {
			GravityFlowFeedSettings.initNotificationTab(notificationTabs[i]);
		}

		// User Input - Save Progress Option/In Progress Email Tab

		var $saveProgressSetting = $('#default_status');
		if ($saveProgressSetting.val() === 'hidden') {
			$('#tabs-notification_tabs').tabs('disable', 1);
		}

		$saveProgressSetting.change(function () {
			var disabled = $(this).val() === 'hidden',
				$notificationTabs = $('#tabs-notification_tabs');
			if (disabled) {
				var $enabledSetting = $('#in_progress_notification_enabled');

				// Disable the In Progress notification if enabled.
				if ($enabledSetting.prop('checked')) {
					$enabledSetting.click();
				}

				// If the In Progress Email tab is active switch to the Assignee Email tab.
				if ($notificationTabs.tabs('option', 'active') === 1) {
					$notificationTabs.tabs('option', 'active', 0);
				}

				$notificationTabs.tabs('disable', 1);
			} else {
				$notificationTabs.tabs('enable', 1);
			}
		});

		// Approval - Revert Email Tab + Custom Revert Confirmation Setting

		var $revertSetting = $('#revertenable');
		if ( !$revertSetting.prop("checked")) {
			$('#tabs-notification_tabs').tabs('disable', 3);
			$("#gform_setting_reverted_message").hide();
		}

		$revertSetting.change(function () {
			var disabled = $(this).prop("checked");
			var $notificationTabs = $('#tabs-notification_tabs');
			if (!disabled) {
				var $enabledSetting = $('#revert_notification_enabled');

				// Disable the Revert notification if enabled.
				if ($enabledSetting.prop('checked')) {
					$enabledSetting.click();
				}

				// If the Revert Email tab is active switch to the Assignee Email tab.
				if ($notificationTabs.tabs('option', 'active') === 1) {
					$notificationTabs.tabs('option', 'active', 0);
				}

				$notificationTabs.tabs('disable', 3);
				$("#gform_setting_reverted_message").hide();
			} else {
				$notificationTabs.tabs('enable', 3);
				$("#gform_setting_reverted_message").show();
			}
		});

		//-----

		if (window.gform) {
			gform.addFilter('gform_merge_tags', GravityFlowFeedSettings.gravityflow_add_merge_tags);
		}

		if (window['gformInitDatepicker']) {
			gformInitDatepicker();
		}

		loadMessages();

	});

	function toggleNotificationTabSettings(enabled, notificationType) {
		var $NotificationTypeSetting = $('#gform-setting-tab-field-' + notificationType + '_notification_type');
		$NotificationTypeSetting.toggle(enabled);
		if (enabled) {
			var selected = $NotificationTypeSetting.find('input[type=radio]:checked').val();
			toggleNotificationTabFields(selected, notificationType);
			$('#gform-setting-tab-tab_' + notificationType + '_notification i.gravityflow-tab-checked').show();
			$('#gform-setting-tab-tab_' + notificationType + '_notification i.gravityflow-tab-unchecked').hide();
		} else {
			toggleNotificationTabFields('off', notificationType);
			$('#gform-setting-tab-tab_' + notificationType + '_notification i.gravityflow-tab-checked').hide();
			$('#gform-setting-tab-tab_' + notificationType + '_notification i.gravityflow-tab-unchecked').show();
		}
	}

	function toggleNotificationTabFields(showType, notificationType) {
		var fields = ['users', 'routing', 'from_name', 'from_email', 'reply_to', 'cc', 'bcc', 'subject', 'message', 'autoformat', 'resend', 'gpdf'],
			prefix = '#gform-setting-tab-field-' + notificationType + '_notification_';

		$.each(fields, function (i, field) {
			$(prefix + field).hide();
		});

		if (showType == 'off') {
			return;
		}

		$.each(fields, function (i, field) {
			if (field == 'users' && showType == 'routing' || field == 'routing' && showType == 'select') {
				return true;
			}

			$(prefix + field).fadeToggle('normal');
		});
	}

	function toggleWorkflowNotificationType(showType) {
		var fields = {
			select: ['workflow_notification_users_', 'workflow_notification_from_name', 'workflow_notification_from_email', 'workflow_notification_reply_to', 'workflow_notification_cc', 'workflow_notification_bcc', 'workflow_notification_subject', 'workflow_notification_message', 'workflow_notification_autoformat', 'workflow_email_custom_file_name', 'workflow_notification_gpdf'],
			routing: ['workflow_notification_routing', 'workflow_notification_from_name', 'workflow_notification_from_email', 'workflow_notification_reply_to', 'workflow_notification_cc', 'workflow_notification_bcc', 'workflow_notification_subject', 'workflow_notification_message', 'workflow_notification_autoformat', 'workflow_email_custom_file_name', 'workflow_notification_gpdf']
		};
		toggleFields(fields, showType, false);
	}

	function toggleType( showType, isTab ) {
		var fields = {
			select: ['assignees_', 'editable_fields_', 'conditional_logic_editable_fields_enabled'],
			routing: ['routing', 'conditional_logic_editable_fields_enabled']
		};

		toggleFields( fields, showType, isTab );
	}

	function toggleFields(fields, showType, isTab) {
		var prefix = isTab ? '#gform-setting-tab-field-' : '#gform_setting_';
		$.each(fields, function (type, activeFields) {
			$.each(activeFields, function (i, activeField) {
				$(prefix + activeField).hide();
			});
		});

		$.each(fields, function (type, activeFields) {
			if (showType == type) {
				$.each(activeFields, function (i, activeField) {
					$(prefix + activeField).fadeToggle('normal');
				});
			}
		});
	}

	function toggleWorkflowNotificationSettings(enabled) {
		var $workflowNotificationType = $('#gform_setting_workflow_notification_type');
		$workflowNotificationType.toggle(enabled);
		if (enabled) {
			var selected = $workflowNotificationType.find('input[type=radio]:checked').val();
			toggleWorkflowNotificationType(selected);
		} else {
			toggleWorkflowNotificationType('off');
		}
	}

	function setSubSettings() {
		var subSettings = [
			'routing',
			'assignees_',
			'assignee_notification_from_name',
			'assignee_notification_from_email',
			'assignee_notification_reply_to',
			'assignee_notification_cc',
			'assignee_notification_bcc',
			'assignee_notification_subject',
			'assignee_notification_message',
			'assignee_notification_autoformat',
			'resend_assignee_email',
			'assignee_notification_gpdf',
			'rejection_notification_type',
			'rejection_notification_users_',
			'rejection_notification_user_field',
			'rejection_notification_routing',
			'rejection_notification_message',
			'rejection_notification_autoformat',
			'approval_notification_type',
			'approval_notification_users_',
			'approval_notification_user_field',
			'approval_notification_routing',
			'approval_notification_message',
			'approval_notification_autoformat',

			'workflow_notification_type',
			'workflow_notification_users_',
			'workflow_notification_user_field',
			'workflow_notification_routing',
			'workflow_notification_from_name',
			'workflow_notification_from_email',
			'workflow_notification_reply_to',
			'workflow_notification_cc',
			'workflow_notification_bcc',
			'workflow_notification_subject',
			'workflow_notification_message',
			'workflow_notification_autoformat',

			'assignees',
			'editable_fields_',
			'routing',
			'assignee_notification_message',
			'workflow_notification_gpdf'

		];
		for (var i = 0; i < subSettings.length; i++) {
			$('#gform_setting_' + subSettings[i]).addClass('gravityflow_sub_setting');
		}
	}

	GravityFlowFeedSettings.gravityflow_add_merge_tags = function (mergeTags, elementId, hideAllFields, excludeFieldTypes, isPrepop, option) {
		if (isPrepop) {
			return mergeTags;
		}

		addCommonMergeTags(mergeTags, elementId, hideAllFields, excludeFieldTypes, isPrepop, option);
		addApprovalMergeTags(mergeTags, elementId, hideAllFields, excludeFieldTypes, isPrepop, option);
		addNotificationMergeTags(mergeTags, elementId, hideAllFields, excludeFieldTypes, isPrepop, option);

		return mergeTags;
	};

	function addCommonMergeTags(mergeTags, elementId, hideAllFields, excludeFieldTypes, isPrepop, option) {

		var supportedElementIds = [
			'_gform_setting_assignee_notification_message',
			'_gform_setting_approval_notification_message',
			'_gform_setting_approved_messageValue',
			'_gform_setting_confirmation_messageValue',
			'_gform_setting_complete_notification_message',
			'_gform_setting_instructionsValue',
			'_gform_setting_in_progress_notification_message',
			'_gform_setting_processed_step_messageValue',
			'_gform_setting_rejected_messageValue',
			'_gform_setting_rejection_notification_message',
			'_gform_setting_reverted_messageValue',
			'_gform_setting_revert_notification_message',
			'_gform_setting_workflow_notification_message',
		];

		if (supportedElementIds.indexOf(elementId) < 0) {
			return mergeTags;
		}

		var labels = gravityflow_form_settings_js_strings.mergeTagLabels,
			tags = [];

		tags.push({tag: '{workflow_entry_link}', label: labels.workflow_entry_link});
		tags.push({tag: '{workflow_entry_url}', label: labels.workflow_entry_url});
		tags.push({tag: '{workflow_inbox_link}', label: labels.workflow_inbox_link});
		tags.push({tag: '{workflow_inbox_url}', label: labels.workflow_inbox_url});
		tags.push({tag: '{workflow_cancel_link}', label: labels.workflow_cancel_link});
		tags.push({tag: '{workflow_cancel_url}', label: labels.workflow_cancel_url});
		tags.push({tag: '{workflow_note}', label: labels.workflow_note});
		tags.push({tag: '{workflow_timeline}', label: labels.workflow_timeline});
		tags.push({tag: '{assignees}', label: labels.assignees});

		mergeTags['gravityflow'] = {
			label: labels.group,
			tags: tags
		};

		return mergeTags;

	}

	function addApprovalMergeTags(mergeTags, elementId, hideAllFields, excludeFieldTypes, isPrepop, option) {
		var supportedElementIds = [
			'_gform_setting_assignee_notification_message',
		];

		if (supportedElementIds.indexOf(elementId) < 0) {
			return mergeTags;
		}

		var labels = gravityflow_form_settings_js_strings.mergeTagLabels,
			tags = [];

		tags.push({tag: '{workflow_approve_link}', label: labels.workflow_approve_link});
		tags.push({tag: '{workflow_approve_url}', label: labels.workflow_approve_url});
		tags.push({tag: '{workflow_approve_token}', label: labels.workflow_approve_token});
		tags.push({tag: '{workflow_reject_link}', label: labels.workflow_reject_link});
		tags.push({tag: '{workflow_reject_url}', label: labels.workflow_reject_url});
		tags.push({tag: '{workflow_reject_token}', label: labels.workflow_reject_token});

		if ( $('#revertenable').prop("checked")) {
			tags.push({tag: '{workflow_revert_link}', label: labels.workflow_revert_link});
			tags.push({tag: '{workflow_revert_url}', label: labels.workflow_revert_url});
			tags.push({tag: '{workflow_revert_token}', label: labels.workflow_revert_token});
		}

		if (typeof mergeTags['gravityflow'] != 'undefined') {
			mergeTags['gravityflow']['tags'] = $.merge(mergeTags['gravityflow']['tags'], tags);
		} else {
			mergeTags['gravityflow'] = {
				label: labels.group,
				tags: tags
			};
		}

		return mergeTags;
	}

	function loadMessages() {
		var feedId = gravityflow_form_settings_js_strings['feedId'];
		if (feedId > 0) {
			var url = ajaxurl + '?action=gravityflow_feed_message&fid=' + feedId + '&id=' + gravityflow_form_settings_js_strings['formId'];
			$.get(url, function (response) {
				var $heading = $('#gform-settings-save');
				$heading.before(response);
			});
		}

	}

	function addNotificationMergeTags(mergeTags, elementId, hideAllFields, excludeFieldTypes, isPrepop, option) {

		var supportedElementIds = [
			'approval_notification_subject',
			'assignee_notification_subject',
			'workflow_notification_subject',
			'complete_notification_subject',
		];

		if (supportedElementIds.indexOf(elementId) < 0) {
			return mergeTags;
		}

		var labels = gravityflow_form_settings_js_strings.mergeTagLabels,
			tags = [];

		tags.push({tag: '{assignees}', label: labels.assignees});
		tags.push({tag: '{current_step}', label: labels.current_step});
		tags.push({tag: '{workflow_note}', label: labels.workflow_note});
		tags.push({tag: '{workflow_entry_url}', label: labels.workflow_entry_url});
		tags.push({tag: '{workflow_inbox_url}', label: labels.workflow_inbox_url});
		tags.push({tag: '{workflow_cancel_url}', label: labels.workflow_cancel_url});

		if (elementId === 'assignee_notification_subject') {
			tags.push({tag: '{workflow_approve_url}', label: labels.workflow_approve_url});
			tags.push({tag: '{workflow_reject_url}', label: labels.workflow_reject_url});
		}

		mergeTags['gravityflow'] = {
			label: labels.group,
			tags: tags
		};

		return mergeTags;

	}

}(window.GravityFlowFeedSettings = window.GravityFlowFeedSettings || {}, jQuery));


