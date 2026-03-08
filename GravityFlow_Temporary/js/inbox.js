(function (GravityFlowInbox, $) {

	$(document).ready(function () {

		$('.gravityflow-actions-unlock').click( function() {
			var $this = $(this),
				$lock = $this.siblings('.gravityflow-actions-lock'),
				$noteContainer = $this.siblings('.gravityflow-actions-note-field-container'),
				$actionButtons = $this.siblings('.gravityflow-actions');
			$this.hide();
			$lock.show();
			$noteContainer.hide();
			$actionButtons.hide();
			$this.parent('.gravityflow-actions').addClass( 'gravityflow-actions-locked' );
		});

		if ( window.gform && typeof gform.addFilter === 'function' ) {
			// Prevent the conditional logic from resetting the field values when the form is displayed in the inbox to save users from accidentally deleting data.
			gform.addFilter("gform_reset_pre_conditional_logic_field_action", function (reset, formId, targetId, defaultValues, isInit) {
				return false;
			});
		}
	});

}(window.GravityFlowInbox = window.GravityFlowInbox || {}, jQuery));

/**
 * @function handleApprovalStepButtonClick
 * @description Handles the approval step button click by setting the new approval status via a hidden input.
 * When the form is handled by the submission handler, it prevents the default submit event
 * and may use jQuery.trigger('submit'), which doesn't include the clicked button value.
 * This function ensures the button's value is preserved in the submission.
 *
 * @since 2.9.11
 *
 * @param {HTMLButtonElement} button         The clicked button element containing the action value
 * @param {boolean}           shouldConfirm  Whether to show a confirmation dialog before proceeding
 * @param {string}            confirmMessage The confirmation message to display if shouldConfirm is true
 *
 * @returns {boolean} Returns false if user cancels confirmation, otherwise true
 */
var handleApprovalStepButtonClick = function ( button,shouldConfirm, confirmMessage ) {
	if ( ! button || ! button.value ) {
		console.error( 'Gravity Flow: Invalid button element or missing value attribute' );
		return false;
	}

	// Check if confirmation is needed and handle user response
	if ( shouldConfirm && confirmMessage && ! confirm( confirmMessage ) ) {
		return false;
	}

	// Set the action value in hidden input
	var hiddenInput = document.getElementById( 'gravityflow_approval_new_status_step' );
	if ( ! hiddenInput ) {
		console.error( 'Gravity Flow: Required hidden input gravityflow_approval_new_status_step not found' );
		return false;
	}
	hiddenInput.value = button.value;
	// Trigger submission logic
	maybeTriggerHandleButtonClick(button);

	return true;
};

/**
 * @function maybeTriggerHandleButtonClick
 * @description calls handleButtonClick if gravityforms version supports it, otherwise, just submits the form.
 *
 * @since 2.9.11
 *
 * @param {HTMLElement} button The clicked button.
 */
var maybeTriggerHandleButtonClick = function ( button ) {
	if ( gform && gform.submission ) {
		gform.submission.handleButtonClick( button );
	} else {
		button.form.submit();
	}
}
