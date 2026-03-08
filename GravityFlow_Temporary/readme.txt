=== Gravity Flow ===
Contributors: stevehenty
Tags: workflow, approvals, gravity forms
Requires at least: 5.2
Tested up to: 5.8.3
License: GPL-2.0+
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Add workflow processes to your Gravity Forms.

== Description ==

Gravity Flow is a business process workflow platform for WordPress.


= Who is it for? =

Gravity Flow is for organisations and departments of any size that need to get a form-based workflow process up and running online quickly with no programming. These processes usually already exist either offline or online but are often inefficiently implemented.

= How does it work? =

An end-user submits a web form which generates an entry. The entry is then passed around between users and systems on an established path until the process is complete. Each user or system in the workflow will add something to the process before allowing the entry to proceed to the next step.

For example, an employee may add additional information and a manager another might add their approval. Connected systems might send an email, add the user to a mailing list, create a user account or send data to an ERP system.

Gravity Flow requires [Gravity Forms](https://gravityflow.io/out/gravityforms)

Facebook: [Gravity Flow](https://facebook.com/gravityflow.io)

Twitter: [Gravity Flow](https://twitter.com/GravityFlow_io)

= Requirements =

1. [Purchase and install Gravity Forms](https://gravityflow.io/out/gravityforms)
2. Wordpress 4.2+
3. Gravity Forms 2.4+


= Support =

If you find any that needs fixing, or if you have any ideas for improvements, please get in touch:
https://gravityflow.io/contact/


== Installation ==

1.  Download the zipped file.
2.  Extract and upload the contents of the folder to /wp-contents/plugins/ folder
3.  Go to the Plugin management page of WordPress admin section and enable the 'Gravity Flow' plugin

== Frequently Asked Questions ==

= Which license of Gravity Forms do I need? =
Gravity Flow will work with any license of [Gravity Forms](https://gravityflow.io/out/gravityforms).

== ChangeLog ==

= 3.0.0 = 2025-11-05
- Added bulk actions to Workflow steps.
- Added step navigation to the step editing screen.
- Added AJAX search to the assignee settings for user interaction steps.
- Added support for AJAX search in the conditional routing repeater for user interaction steps.
- Added the ability for fields to instantiate assignee repeaters by assigning a data-notification_type attribute to the settings field.
- Updated minimum Gravity Forms version to 2.9.14.
- Updated the choice labels for the Feeds setting on steps for feed-based add-ons to include a link to the add-ons edit feed page.
- Updated the conditional routing setting to use the new Gravity Forms repeater field.
- Updated to use the Gravity Forms 2.9.18+ File Upload field enhancements when populating existing files for editable fields and when saving temporary file uploads.
- Updated the Submit page to show the new Gravity Flow logo.
- Fixed an issue where the Alternative Text input of an editable Post Image Field is not populated with the existing value.
- Fixed a bug that causes the "unsaved changes" warning to appear when you search in the form switcher dropdown, even if you have not made any other changes.
- Fixed an error that is displayed when installing Gravity Forms via the installation wizard.
- Removed the broken view and edit Zap links from the Zapier step.
- API: Added new gravityflow/v2/steps endpoint to get the steps by entry or form.
- API: Added new entry assignees management endpoints to the V2 API.
- API: Added new revert step endpoint for the Approval step to the V2 API.
- API: Added new endpoints to the V2 API to restart workflows, cancel workflows, restart steps, and send to step.
- API: Added the new gravityflow_assignee_ajax_search_account_threshold filter to allow modification of the number of users that are present on the site before AJAX search becomes enabled.
- API: Added the new gravityflow_assignee_ajax_search filter to allow AJAX search to be enabled or disabled globally.
- API: Added the new gravityflow_discussion_item filter to allow discussion item content/markup to be customized.

= 2.9.15 = 2025-07-14
- Updated the Inbox settings flyout to be compatible with the latest flyout component updates.
- Fixed an issue on the entry detail page for Approval steps where clicking "Cancel" on the confirmation prompt for the reject action still rejects the entry.
- Fixed an issue where notifications using conditional logic based on workflow step statuses sometimes are not sent.
- Fixed an issue where display fields with conditional logic don't show their content when dynamically displayed in user input steps.

= 2.9.14 = 2025-04-22
- Updated the outgoing webhook request settings to allow multi-line text to be entered when mapping to custom values.
- Fixed an issue where product state is not updated when product fields are changed in the inbox page.
- Fixed an issue where the HTML field value still displays when the workflow Display Steps setting is set to "Hide all fields except selected".
- Fixed an issue where editable fields don't show in user input steps when non-editable calculated fields are present in the form.
- Fixed an issue where logged-in users might not see workflow action buttons after an email assignee completed a prior step using a token link in the same browser session.

= 2.9.13 = 2-25-03-06
- Added a new workflow step for the Brevo add-on.
- Updated the feed add-on steps for compatibility with Gravity Forms 2.9.4, so the add-on framework can save the feed processing result (status) to the entry meta.
- Fixed an issue where a PHP Warning is thrown when using the setcookie function in PHP 8.1+.
- Fixed an issue where the domain of setcookie included http or https.
- Fixed an issue where update_option() is referencing a deprecated value.

= 2.9.12 = 2025-02-10
- Updated the process_workflow function to stop processing a workflow if it is cancelled via hooks.
- Fixed an issue where the File Upload field bypasses a validation check when using editable fields.
- Fixed an issue where the admin actions are not working properly in the entry's admin view.
- Fixed an issue with shortcode generated pages when viewing as an anonymous user.
- Fixed an issue where the editable fields list under conditional routing can't be edited on feeds created with Gravity Flow versions prior to 2.8.7.
- Fixed an issue where the entry locking modal sometimes displays outside of Gravity Flow contexts/pages.


= 2.9.11 = 2025-01-09
- Updated the get_users_as_choices function to allow modification of the role argument.
- Fixed an issue where clicking the approve or reject buttons on the step approval page doesn't update the step status.
- Fixed an issue where a deprecation warning is added to the debug log when logging in to the WordPress admin.
- Fixed the `Creation of dynamic property Gravity_Flow::$_assignee_settings_md5 is deprecated` notice with PHP 8.2.

= 2.9.10 = 2024-11-14
- Added the response mapping feature to the Salesforce step.
- Updated the ConvertKit Step label to Kit.
- Updated the step condition setting for compatibility with Gravity Forms 2.9 and greater.
- Updated conditional logic behavior in the inbox to not delete data in fields hidden by conditional logic until the entry is saved.
- Updated the gravityflow_inbox_search_criteria filter to include the inbox args as an additional parameter.
- Fixed the styles of the discussion field on the form editor in Gravity Forms 2.9.
- Fixed an issue which causes a PHP notice on the Widgets page in WordPress 6.6+.
- Fixed an issue where the approval step doesn't respect field conditional logic and can cause incorrect calculations.
- Fixed the default behavior of the Workflow Status block to show all entries to admins, not just the current user's entries.
- Fixed gravityflow_assignee_status_workflow_detail filter definition to avoid duplicate assignee parameter.
- Fixed inconsistent capitalization of SPACE and ID, and extra spaces before periods.

= 2.9.9 = 2024-08-12
 - Added a new workflow step for the PayPal Checkout add-on. Requires version 3.5.0 of the PayPal Checkout Add-On or later.

= 2.9.8 = 2024-07-11
- Added the [gravityflow_approval_assignee_status_types] filter to allow customization of the available approval step statuses.
- Added the [gravityflow_approval_assignee_status_feedback] filter to allow for a custom feedback message for custom approval step statuses.
- Fixed an issue where editable Hidden type fields aren't displayed on the Approval or User Input steps.
- Fixed an issue with the date picker not showing up on the step schedule and expiration fields if "date" is used instead of "delay".
- Fixed an issue where a fatal error can occur when using Gravity Kit extensions without GravityView enabled.

= 2.9.7 = 2024-06-06
- Added a new workflow step for the Salesforce add-on.
- Updated the Outgoing Webhook step to support mapping response headers.
- Updated the [gravityflow_entry_webhook_response_mapping](https://docs.gravityflow.io/article/236-gravityflowentrywebhookresponsemapping) filter to include `$mapping_type` as the fifth parameter.
- Fixed PHP warnings that occur when using the GravityKit GravityView integration.
- Fixed errors that occur when attempting to install translations and the WordPress filesystem requires credentials.
- Fixed an internal PHP Fatal error that occurs when using the Gravity Flow Form Connector add-on.
- Fixed an issue where an error occurs when trying to request control from another user viewing the same entry on the workflow detail page.

= 2.9.6 = 2024-02-23
- Fixed PHP warnings in PHP 8.2.
- Fixed support for gravityflow_approval_confirm_prompt_messages to allow customized prompt messages to display.
- Fixed an issue where reminders can be sent even when not enabled if a step has an expiration date.
- Fixed an issue where merge tags are not replaced in confirmation messages after one-click approval or rejection links are clicked.

= 2.9.5 = 2023-11-30
- Fixed an issue with the Discussion field in the Gravity Forms 2.8 form editor compact view.
- Fixed an issue where the Gravity Flow icon does not display in the form settings when no conflict mode is enabled.
- Fixed PHP warnings that show when custom labels are added for deactivated extensions.

= 2.9.4 = 2023-07-19
- Updated the ConvertKit step to support the Gravity Forms ConvertKit Add-On; steps will be automatically updated to use the migrated ConvertKit feeds.

= 2.9.3 = 2023-06-14
- Fixed an error that occurs when rendering the entry filter field.
- Fixed an error that occurs when selecting the PayPal Workflow step.
- Fixed an issue where the Discussion field value is duplicated when updating the entry on the User Input step.

= 2.9.2 = 2023-04-05
- Fixed an error where conditional routing settings cannot be saved on the notification step.

= 2.9.1 = 2023-03-30
- API: Fixed support for gravityflow_editable_fields_user_input filter to perform after gravityflow_editable_fields filter.
- API: Added support for gravityflow_editable_fields_[step_type] filter which any step type that supports editable fields will perform.
- API: Added filter gravityflow_step_notification_assignees to allow customization of notification assignees with syntax matching the gravityflow_step_assignees filter.


= 2.9 = 2023-03-23
- All step types can now define 'use Editable_Fields;' as part of their step class to have the editable fields step setting / functionality added to the step.
- Updated Approval step type to include editable fields setting.
- Updated User Input step type to include editable fields setting.
- Updated step settings screens to reduce page reloads required to create/update a step.
- Fixed an issue that causes Nested Form fields on a User Input Workflow Step to produce a fatal error on submission.
- Fixed an issue with outgoing webhook steps displaying an error if a GET request includes no request field values.
- Fixed an issue with inbox settings flyout where the component's icon is missing.
- Fixed an issue with editable fields which causes the setting to use the wrong UI.

= 2.8.8 = 2023-01-04
- Fixed an issue with user input steps involving conditional logic and calculations not saving file upload field values.

= 2.8.7 = 2022-11-30
- Update branding throughout the product.

= 2.8.6 = 2022-11-30
- Added the filter gravityflow_step_settings_fields to allow step setting fields to be modified.
- Fixed deprecation warning when loading the inbox page.
- Fixed an issue with user input steps handling of conditional logic and saving field values.
- Fixed an issue with Workflow Inbox blocks not displaying multiple forms entries when the filter forms block display setting included multiple forms.
- Fixed an issue with scheduled steps that also include an expiration setting sending reminders before the scheduled step start date/time.
- Fixed an issue with the user input step where the email assignee is not able to save multi-file uploads.

= 2.8.5 = 2022-07-30
- Updated default page creation on new activations to use block or shortcode based on active theme settings.
- Fixed support for Live Merge Tags on entry details screen.
- Fixed an issue with Workflow Step Settings assignee updates not being applied to assignees of active steps.

= 2.8.4 = 2022-07-02
- Added security enhancements.
- Added accessibility enhancements.
- Added a new action hook gravityflow_entry_detail_content_after to allow custom markup after the form on the entry details page.
- Added merge tag drop down to multiple textarea step settings on start, user input, approval and complete step types.
- Removed specific tabindex on User Input step update button so it can be accessed following standard browser navigation/accessibility patterns.
- Fixed an issue with filter forms not working with Workflow Inbox and Workflow Status blocks for some users.
- Fixed an issue with User Input steps and field values involving conditional logic display.
- Fixed an issue where inbox preferences are not saved for some assignees.


= 2.8.3 = 2022-06-11
- Added security enhancements.
- Added performance enhancements for the inbox and workflow detail page.
- Added better handling for the inbox to the workflow details screen link. Cmd + click now loads in new tab without focusing it, left shift + click opens it in a new tab and focuses it.
- Added support for Workflow merge tags in the merge tag drop-down of Notification subject lines.
- Added gravityflow_inbox_items_per_page_default filter to allow changing of the default items per page count for all inboxes.
- Added support for Live Merge Tags within HTML fields on entry details screen.
- Updated inbox grid strings to be translatable.
- Updated the inbox to automatically adjust the height of field based or custom columns based on their content.
- Updated the Workflow Detail Page button styles so the color property is more flexible for theme overrides.
- Fixed an issue with shortcode detection displaying warning when wp_query->posts is not set such as on wp-login.php.
- Fixed a warning for sites with license key stored on wp-config.
- Fixed display issues with the inbox on rtl layouts.
- Fixed an issue with the inbox settings flyout where the component's icon is missing.
- Fixed an issue which can cause fatal errors for some edge case server configurations.
- Fixed an issue which causes inbox notification preferences to not save.
- Fixed an issue that causes date fields in legacy mode enabled inboxes to print a duplicate empty column.
- Fixed an issue that causes date fields in status tables to print a duplicate empty column.
- Fixed an issue for inbox, status, and submit block where the block form filtering setting allows you to select the placeholder as an option.
- Fixed an issue with Conditional logic for HTML fields on User Input Step overriding the Display Field Settings.
- Fixed an issue which prevents users from being able to delete a file from within a User Input step.

= 2.8.2 = 2022-04-03
- Added the gravityflow_inbox_field_value filter to allow field values to be modified for the workflow inbox.
- Added the gravityflow_network_non_site_user_notification filter to allow network users of a multisite to receive notifications.
- Fixed an issue with RTL languages not displaying correctly on the Reports front-end page.
- Fixed an issue that causes Nested Form fields on a User Input Workflow Step to produce a fatal error on submission.
- Fixed an issue with the outgoing webhook steps displaying an error if a GET request includes no request field values.
- Fixed an issue with date fields on the Gravity Forms entry list page.
- Fixed a warning on the login page.

= 2.8.1 = 2022-03-27
- Added performance enhancements.
- Added the gravityflow_enqueue_admin_scripts action to allow additional scripts to be enqueued when the gravityflow shortcode or block is present on the page.
- Fixed a conflict with some optimizer plugins.
- Fixed an issue that prevents the form filter select from opening in the inbox block settings.
- Fixed an issue which causes Inbox queries to fire on all pages.


= 2.8 = 2022-03-13
- Added a new inbox! Now supports live data refresh (no need to refresh the page), paging, sorting, filtering by value and date range (including date fields and due date), column reordering, full-screen mode, and browser notifications. Note: clear your browser and server cache to avoid seeing errors or an empty inbox!
- Added workflow blocks for inbox, status, submit, and reports pages.
- Added entry locking support to the admin UI.
- Fixed mobile view for the Status page for some themes.
- Updated icons.
- API: Added the ability for extensions to use Gravity Flow icons or specify their own.

= 2.7.9 =
- Added security enhancements.
- Added 3 new merge tags to support reverting Approval steps to user input steps via email including one-click tokens - {workflow_revert_link} {workflow_revert_url} {workflow_revert_token}
- Added settings for custom revert messages after an Approval step is reverted.
- Added new step type; Update Post. Update posts which were created via the Create Post step or feed. Requires the Gravity Forms Advanced Post Creation Add-On v1.0.
- Added the gravityflow_post_update_post filter for custom logic to perform after a post has been updated.
- Added deprecated version warning for Gravity Forms less than version 2.5. Gravity Flow will soon require Gravity Forms 2.5+.
- Updated the Revert to User Input step setting for new Approval steps to be off by default.
- Updated the Post Creation step type to Create Post. It also now has a step specific icon.
- Fixed an issue with Gravity PDF conditional logic not honored on the PDF sent with Workflow notifications.
- Fixed an issue with entry details screen displaying instructions section markup when the step settings for instructions are empty.


= 2.7.8 =
- Added enhanced support for GravityView. Editing an entry in GravityView can complete a User Input Step when the user is an assignee. Enable via setting in Workflow > Settings > Advanced.
- Added enhanced support for GravityView. List views can now add one-click approval links using a new View Field "Workflow Approval Links".
- Fixed an issue with Approval Steps Invalid Approval Link Message not displaying when workflow has proceeded on to a non-approval step type.
- Fixed an issue with Approval Steps Invalid Approval Link Message and Custom Cancellation Message not displaying when a workflow has been cancelled.
- Fixed an issue with how HTML fields with conditional logic were displaying on the User Input Workflow Step.
- API: Added a filter gravityflow_user_input_by_view_edit to provide additional control of if/when GravityView edits should trigger completion of a matching assignees' User Input step.
- API: Updated the {current_step} merge tag to remove the time if the gravityflow_date_format_current_step_merge_tag filter returns an empty string.
- API: Updated the gravityflow_date_format_current_step_merge_tag filter to include arguments for step and modifier.


= 2.7.7 =
- Added settings for custom confirmation messages after approval and rejection.
- Added a setting for custom cancellation message.
- Added a translator comment to the tooltip text for the partial entries workflow enabling feed setting.
- Fixed an issue with RTL languages not displaying correctly on the Reports page.


= 2.7.6 =
- Added a setting to enable updates to pre-releases of Gravity Flow.
- Fixed an issue with conditional routing not loading when the Workflow Notification setting was active on Gravity Forms 2.5.13 and greater.
- Fixed an issue with File Upload field not displaying download/remove file icon links on User Input steps when accessed via the dashboard inbox.
- Fixed an issue with Workflow Status Box not displaying long step names correctly on the Workflow Details page.
- Fixed an issue with start step type display field settings not evaluating correctly for entry creator status page view.


= 2.7.5 =
- Added TranslationsPress to manage translations for Gravity Flow and the extensions. Translations for the installed WordPress languages will be installed on Gravity Flow/extension installation and updates. The WordPress updates page will also offer to install them when updates to the translations are available. The WP-CLI `wp language plugin` commands for managing translations are also supported.
- Added CSS classes to the Workflow Status Box.
- Added two filters to the step get_form function - gravityflow_{$this->get_type()}_form and gravityflow_step_form - to allow the form to be revised before/during step processing.
- Updated the request body sent by the Gravity Forms Zapier Add-On to include formatted versions of workflow field values.
- Removed the step type restrictions note from the Workflow > Settings > Connected Apps page.
- Fixed new theme css and js bundles loading on all pages.
- Fixed a compatibility issue with the Gravity Perks Populate Anything Perk.
- Fixed an issue where the Likert field is editable on the User Input step when it should be display only.
- Fixed an issue where Workflow Detail Page for an entry is loading without matching the form id.
- Fixed an issue where PDF Custom File Name Setting did not appear as a sub-setting under Send by Email on PDF Workflow Step Settings.


= 2.7.4 =
- Fixed an issue with the entry filter setting where the settings are not saved for the Update Fields step.
- Fixed an issue where the values of administrative fields are lost when partial entries are updated by the Partial Entries Add-On. Requires Partial Entries Add-On v1.6.1 or greater.
- Fixed an issue with Gravity Forms 2.5 not displaying the green triangle next to the editable fields on the User Input Workflow Step.


= 2.7.3 =
- Added the Form setting to the Zapier step enabling multiple forms to use the same Zap.
- Added the gravityflow_note_valid filter to customize note validation for Approval and User Input steps.
- Fixed Font Awesome not loading for shortcodes which causes the quick actions for inbox to not be visible.
- Fixed an issue where a blank note could be submitted with whitespaces, even if it is required.
- Fixed an issue where uninstall for extensions on Gravity Forms Settings is not loading the correct page.


= 2.7.2 =
- Fixed deprecation warnings when saving steps on Gravity Forms 2.5.
- Fixed an issue that causes Gravity Forms OAuth1 connected apps to fail verification.
- Fixed an issue where the File Upload field generates a validation error when progress is saved on the User Input Step.
- Fixed an issue when the filter gravityflow_form_ids_status in use with the filter gravityflow_status_filter fetches invalid counters for the Status Page.


= 2.7.1 =
- Added action gravityflow_step_start for custom logic to perform when any step starts.
- Fixed an issue with step ordering that causes a conflict with other add-ons that support feed ordering.
- Fixed an issue that prevents the use of gravityflow_status_filter with an 'any' mode selection from being applied correctly.
- Fixed an issue that causes Icons in the Timeline View to display at the wrong size in Gravity Forms 2.5.
- Fixed an issue that causes Order Summary to display on Complete Step even when the setting was disabled.
- Fixed the entry filter setting to support the filters being passed in the field config which improves Form Connector extension remote site scenarios.
- Fixed an issue with HTML fields conditional logic that affected their display on Workflow Detail page for completed workflows.
- Fixed the background on Workflow Labels Settings page with Gravity Forms 2.5.
- Fixed an issue with the display of the Update button on the Workflow Labels Settings page with Gravity Forms 2.5.
- Updated the Status Page to use a CSS Loading Spinner to replace the deprecated Spinner Gif from Gravity Forms Core.
- Updated the Assignee Email Message Textarea to improve support for the TinyMCE Editor from Gravity Forms 2.5


= 2.7 =
- Added support for Gravity Forms 2.5-beta-3.
- Added filter gravityflow_entry_url_status_table to allow the url in status page to be customized.
- Added filter gravityflow_sort_columns_status_table to allow custom columns defined in gravityflow_field_value_status_table to be sorted
- Added filter gravityflow_sort_criteria_status to allow initial sort order on status page load to be defined.
- Added a setting to enable the Workflow Inbox count in the WordPress admin menu.
- Updated the Workflow Inbox Count to be disabled by default; it can be enabled with the advanced setting on the plugin settings page.
- Fixed an issue where the workflow was showing as stuck on same step for email assignees in the front-end.
- Fixed an issue with Step Assignees on User Input Step causing a Fatal Error.
- Fixed an issue with the print button on the workflow details page and front-end pages.
- Fixed an issue where workflow steps are processed for entry revisions created by GravityView Entry Revisions.
- Fixed an issue with the Step Condition setting on the Form Submission Step where the entry meta of child form is fetched to process the condition, instead of the parent form.
- Fixed an issue with step reassignment when the assignee policy has changed.

= 2.6 =
- Added support for Gravity Forms 2.5.
- Added the 'gravityflow_inbox_count_display' filter to allow the inbox count to be hidden in the WordPress admin menu.
- Added the 'gravityflow_major_version_auto_updates_allowed' filter to allow only minor versions to be auto-updated.
- Fixed an issue with Step Assignees on User Input Step causing a Fatal Error.


= 2.5.12 =
- Added the red bubble inbox count display on the WP Dashboard Workflow menu item.
- Added support for the WordPress 5.5 enable/disable auto-updates feature on the installed plugins page.
- Updated the CSS classname 'wrap' by making it specific as 'gravityflow_wrap'. This avoids conflicts with the CSS rules of some themes.
- Fixed an issue where GP Limit Dates does not function on the User Input step when the min and max range is based on non-editable Date fields.
- Fixed an issue where merge tags for the Confirmation Message in the User Input Step are evaluated before the workflow step completes.
- Fixed an issue on the User Registration Step, where manual User Activation feed setting was sending email with activation link to User.
- Fixed an issue on the User and Multi-User field 'Users Role Filter' setting display (but not filter save).
- Fixed an issue on front-end pages with WordPress 5.5 removing the page parameter.
- Fixed an issue where combination of inbox shortcode arguments would prevent role based assignees from accessing submit button.


= 2.5.11 =
- Fixed issue with merge tag evaluation that caused a fatal error involving certain conditional logic setup. Update Form Connector add-on will also be required if installed.
- Fixed an issue with second layer confirmation for Approval Step. When the Revert to User Input step option is enabled, the Revert button was not displaying the confirm box.
- Fixed support for latest versions of Gravity View Advanced Filters. Credit: The team at GravityView.
- Fixed an issue with the reports shortcode/page where the "Step Assignees" dropdown was missing when "Category" is set to "Step" and a step is chosen.

= 2.5.10 =
- Added security enhancements.
- Added support for reports in the shortcode [gravityflow page="reports"].
- Added setting to allow the reports shortcode to display reports to display to anonymous users.
- Added support for the Pods Gravity Forms Add-On.
- Added support for the GP eCommerce Fields on the User Input Step.
- Added support for the Gravity Forms EmailOctopus Add-On.
- Added support for Merge Tags on Workflow Step Conditions.
- Added support for Gravity Forms conditional shortcode within Outgoing webhook raw body requests.
- Added filter gravityflow_send_to_step_condition_met_required to allow customization of the API send_to_step when the proposed steps conditions are not met. Default is false that API will always send to the proposed step.
- Added filter gravityflow_send_to_step_condition_not_met to allow customization of the API when the proposed next step has not met its step conditions. Defaults to next step after the proposed step.
- Added workflow note when the Update User step is completed.
- Added support for filter gform_pre_validation on the User Input Step.
- Added a second layer confirmation for Approval Step with a confirm box. This can be enabled in the step settings.
- Added filter gravityflow_approval_confirm_prompt_messages to allow customization of the confirmation prompt messages on the Approval Step.
- Updated the Zapier step to support Gravity Forms Zapier Add-On v4.0-beta-1.1 and greater.
- Updated Outgoing Webhook settings to allow GET requests to include the request body settings as URL parameters.
- Updated gravityflow_print_styles filter to include 2nd parameter of entry IDs.
- Fixed an issue with the User Input Step where editable Total, Coupon, Subtotal, Tax, or Discount fields required all other pricing fields to be editable to function correctly.
- Fixed an issue with the User Input Step where the existing Coupon field value is not restored. Requires Coupons Add-On v2.9.2 or greater.
- Fixed an issue with the User Input Step where the Coupon usage count is not updated.
- Fixed a PHP notice which occurs when the shortcode is processed and WordPress isn't able to provide the current post object.
- Fixed a PHP warning on the inbox page when a step is configured with a delay based due date and the offset setting contains non-numeric characters.
- Fixed notices with PHP 7.4.
- Fixed an issue where the Dropbox step does not complete with Dropbox Add-On 2.4.1+.
- Fixed an issue where the report data for assignees by month is incorrect.
- Fixed an issue where the Discussion Field view more/less effect would not operate on the Form > Entries screen (both view and edit).
- Fixed an issue where the scripts and styles are not enqueued when using the shortcode or block in a reusable block.
- Fixed an issue in the step settings page where duplicate Workflow step icons appear for the Gravity Forms HubSpot Add-on and third-party HubSpot Add-on. IMPORTANT: check that your HubSpot workflow steps are correct after updating to this version.
- Fixed a bug with 'Schedule expiration' on Workflow step. 'Next Step if Expired' option should only appear when 'Status after expiration' is set as 'Expired'.
- Fixed the field filter not appearing on the status page when the form_id constraint is set to an array using the gravityflow_status_filter hook.
- Fixed an issue for the completed URL of Partial Entry Submission step, which was directing to a new form entry. Updated to return a message stating that the entry was already completed.
- Fixed an issue with the Connected Apps comparing authorization value from translated text.


= 2.5.9 =
- Fixed incorrect feedback when user input step confirmation message content is left empty and the step is completed.
- Fixed an issue when attempting to modify step settings for a step type that is not active (plugin deactivated, incorrect permissions).
- Fixed an issue where PHP fatal error is thrown in the filter_gravityview_common_get_entry_check_entry_display() method.
- Fixed an issue where PHP fatal error is thrown when processing expired entries with steps that no longer exist.
- Fixed an issue where a multi-select on User Input step settings pages would shrink to a very small width on smaller window resolutions.
- Fixed a PHP notice which can occur when conditional logic is evaluated and the rule is not based on a form field.
- Fixed an issue where workflow merge tags would not be available in the merge tag dropdown for notification content fields in certain steps.
- Fixed an issue where revert notifications would not include the latest workflow note.
- Fixed an issue where PHP fatal error is thrown from workflow detail link integration with Gravity View 2.5. Credit: the GravityView Team.
- Updated the Members integration to include the missing Status page Admin Actions capability.
- Updated the hook used in the WP E-Signature Step to allow all signatures to be collected before continuing workflow.
- Updated the translations for Catalan and Arabic.

= 2.5.8 =
- Added support for the delayed payment enhancements in Gravity Forms 2.4.13. Workflow processing can now be delayed when using PayPal Standard, Stripe (Checkout), and other payment add-ons which support the Post Payment Action setting.
- Added the filter gravityflow_timeline_note_add to support customizing the potential note to add to timeline.
- Added the filter gravityflow_timeline_notes to support customizing the display of timeline notes.
- Added support for CC: field to all Gravity Flow notification types.
- Fixed the "view more" link for the Discussion field being output when merge tags are processed for posts created by the Advanced Post Creation Add-On.
- Fixed the notification tab for Revert Email on Approval step to have the workflow merge tags options.


= 2.5.7 =
- Fixed issue where status page filters show a search box.


= 2.5.6 =
- Added filter gravityflow_entry_url_inbox_table to allow customization of the link from inbox.
- Added filter gravityflow_approval_revert_step_id to allow customization of the revert step.
- Added Merge Tag selector to Outgoing Webhook step settings for raw request body.
- Added the filter gravityflow_step_is_condition_met to enable complex conditional logic for start (or not) a given step.
- Fixed the Post Creation step creating duplicate posts when the workflow or step is restarted.
- Fixed back link display on entry detail page to use css with class gravityflow-back-link-container instead of line breaks for spacing.
- Fixed PHP 7.3 compatibility warning related to entry detail screen display.
- Fixed number field display on user input steps when the field contained 0 value.
- Fixed delayed Zapier steps send duplicate content from the first entry in queue.
- Fixed an issue when gravityflow_form_ids_status runs to ensure entry counts + pagination counts match filtering form IDs.


= 2.5.5 =
- Added security enhancements.
- Added translations for French, Portuguese, Italian, Swedish, Dutch, Turkish, German and Spanish.
- Fixed an issue with the User Input step where the product order summary table is not displayed unless a product field is included in the display fields.
- Fixed an issue with the workflow entry detail page where all fields are displayed to admins instead of only the display fields.

= 2.5.4 =
- Added the gravityflow_can_render_form filter.
- Updated translations.
- Fixed a rare fatal error on the status page when steps are missing.

= 2.5.3 =
- Added security enhancements.
- Added support for Gravity Flow editor blocks - coming soon!
- Added filter gravityflow_assignee_email_reminder_repeat_days and added deprecation notice for gravityflow_assignee_eamil_reminder_repeat_days.
- Added support for the forms attribute in the submit shortcode so the workflow forms can be filtered. e.g. [gravityflow page="submit" forms="1,2,3"]
- Added support for the back_link back_link_text and back_link_url attributes in the status shortcode.
- Added support for multiple forms in the status shortcode e.g. [gravityflow page="status" form="1,2,3"]
- Added support for displaying the first 2,000 users in assignee settings.
- Updated translations.
- Updated the submit shortcode to display only the published workflows or the forms specified in the forms attribute.
- Fixed an issue with notification step not identifying all users in a multi-user field for notification.
- Fixed an issue when Sliced Invoices status was manually updated to paid, entries weren't released from Sliced Invoices steps.
- Fixed an issue with the Status shortcode where users with the gravityflow_status_view_all capability don't see all entries when the shortcode security settings are set to disallow the display_all attribute.
- Fixed validation issue in Assignee, User and Multi-User fields.
- Fixed an issue with the confirmation page for users with the gravityflow_status_view_all capability when transitioning steps.
- Fixed a PHP notice when loading feed message with AJAX.


= 2.5.2 =
- Added security enhancements.
- Fixed an issue with due date column of inbox shortcode displaying a default value when no step settings had been defined.
- Fixed an issue with the inbox incorrectly highlighting some entries as overdue.
- Fixed the workflow stalling on the Dropbox step when there are no files to process.
- Fixed an issue with the display of schedule date field settings.

= 2.5.1 =
- Fixed an issue where the start step settings are not displayed unless the Partial Entries Add-On is active.

= 2.5 =
- Added security enhancements.
- Added support for the Gravity Forms Partial Entries Add-On.
- Added DB performance improvements.
- Added the Update User step to update a user profile - requires the edit_users capability.
- Added link on entry detail page, shortcode only, to 'Return to list' which links user back to inbox / status page.
- Added shortcode attribute back_link (default: false) to identify if back link should be displayed on entry detail for entries loaded via shortcode.
- Added shortcode attribute back_link_text (default: "Return to list" translatable) to allow customization of text for back link on entry detail page.
- Added shortcode attribute back_link_url (default: null) to allow customization of back link on entry detail page.
- Added filter gravityflow_back_link_url_entry_detail to allow customization of back link on entry detail page.
- Added filter gravityflow_search_criteria_status to allow status page to filter on multiple field criteria.
- Added step setting type for due date with sub-options for delay/date/field selection and row highlighting for inbox.
- Added the due date settings to the approval and user input steps.
- Added inbox and status attribute due_date (false by default) to show due date column.
- Added shortcode security settings to the Workflow->Setting page.
- Added the filter gravityflow_next_step to allow customization of the next step for workflow processing (example - restart a completed user input step for new assignees to action)
- Fixed the workflows for GP Nested Forms child entries starting before some Gravity Perks extensions had updated the entries.
- Fixed an issue that prevented conditional routing from correctly matching date field conditions.
- Fixed an issue that assignee fields with placeholder text would have invalid blank selection options on user input steps.
- Fixed the outgoing webhook for steps with raw request body defined to have field merge tag values properly escaped.
- Fixed an issue with the outgoing webhook step where GET requests using connected apps do not include the authorization headers.
