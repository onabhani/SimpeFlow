/**
 * Production Scheduling - Calendar View
 * Visual calendar interface for managers
 */
(function($) {
    'use strict';

    $(document).ready(function() {
        // Only initialize if calendar elements exist on the page
        if ($('.sfa-prod-calendar, .sfa-day-entries').length === 0) {
            return;
        }

        initializeCalendarTooltips();
    });

    /**
     * Initialize entry tooltips on calendar days
     */
    function initializeCalendarTooltips() {
        // Show tooltip on hover
        $('.sfa-day-entries').on('mouseenter', function() {
            $(this).find('.sfa-entries-tooltip').stop(true, true).fadeIn(200);
        });

        // Hide tooltip on mouse leave
        $('.sfa-day-entries').on('mouseleave', function() {
            $(this).find('.sfa-entries-tooltip').stop(true, true).fadeOut(200);
        });

        // Prevent tooltip from closing when hovering over it
        $('.sfa-entries-tooltip').on('mouseenter', function() {
            $(this).stop(true, true).show();
        });

        $('.sfa-entries-tooltip').on('mouseleave', function() {
            $(this).stop(true, true).fadeOut(200);
        });
    }

    // TODO: Implement capacity editing
    // TODO: Move booking functionality (v2)

})(jQuery);
