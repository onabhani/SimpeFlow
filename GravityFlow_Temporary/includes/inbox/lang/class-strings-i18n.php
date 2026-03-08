<?php

namespace Gravity_Flow\Gravity_Flow\Inbox\Lang;

/**
 * Simple class to store i18n strings and their translations.
 *
 * @since 2.8
 */
class Strings_I18n {

	/**
	 * The strings to be translated.
	 *
	 * @since 2.8
	 *
	 * @return array
	 */
	public function strings() {
		return array(
			'actions_menu_heading'        => esc_html__( 'Note:', 'gravityflow' ),
			'ag_grid'                     => array(
				// Set Filter
				'selectAll'                   => esc_html__( '(Select All)', 'gravityflow' ),
				'selectAllSearchResults'      => esc_html__( '(Select All Search Results)', 'gravityflow' ),
				'searchOoo'                   => esc_html__( 'Search...', 'gravityflow' ),
				'blanks'                      => esc_html__( '(Blanks)', 'gravityflow' ),
				'noMatches'                   => esc_html__( 'No matches', 'gravityflow' ),

				// Number Filter & Text Filter
				'filterOoo'                    => esc_html__( 'Filter...', 'gravityflow' ),
				'equals'                      => esc_html__( 'Equals', 'gravityflow' ),
				'notEqual'                    => esc_html__( 'Not equal', 'gravityflow' ),
				'blank'                       => esc_html__( 'Blank', 'gravityflow' ),
				'notBlank'                    => esc_html__( 'Not blank', 'gravityflow' ),
				'empty'                       => esc_html__( 'Choose One', 'gravityflow' ),

				// Number Filter
				'lessThan'                    => esc_html__( 'Less than', 'gravityflow' ),
				'greaterThan'                 => esc_html__( 'Greater than', 'gravityflow' ),
				'lessThanOrEqual'             => esc_html__( 'Less than or equal', 'gravityflow' ),
				'greaterThanOrEqual'          => esc_html__( 'Greater than or equal', 'gravityflow' ),
				'inRange'                     => esc_html__( 'In range', 'gravityflow' ),
				'inRangeStart'                => esc_html__( 'from', 'gravityflow' ),
				'inRangeEnd'                  => esc_html__( 'to', 'gravityflow' ),

				// Text Filter
				'contains'                    => esc_html__( 'Contains', 'gravityflow' ),
				'notContains'                 => esc_html__( 'Not contains', 'gravityflow' ),
				'startsWith'                  => esc_html__( 'Starts with', 'gravityflow' ),
				'endsWith'                    => esc_html__( 'Ends with', 'gravityflow' ),

				// Date Filter
				'dateFormatOoo'               => esc_html__( 'yyyy-mm-dd', 'gravityflow' ),

				// Filter Conditions
				'andCondition'                => esc_html__( 'AND', 'gravityflow' ),
				'orCondition'                 => esc_html__( 'OR', 'gravityflow' ),

				// Filter Buttons
				'applyFilter'                 => esc_html__( 'Apply', 'gravityflow' ),
				'resetFilter'                 => esc_html__( 'Reset', 'gravityflow' ),
				'clearFilter'                 => esc_html__( 'Clear', 'gravityflow' ),
				'cancelFilter'                => esc_html__( 'Cancel', 'gravityflow' ),

				// Filter Titles
				'textFilter'                  => esc_html__( 'Text Filter', 'gravityflow' ),
				'numberFilter'                => esc_html__( 'Number Filter', 'gravityflow' ),
				'dateFilter'                  => esc_html__( 'Date Filter', 'gravityflow' ),
				'setFilter'                   => esc_html__( 'Set Filter', 'gravityflow' ),

				// Side Bar
				'columns'                     => esc_html__( 'Columns', 'gravityflow' ),
				'filters'                      => esc_html__( 'Filters', 'gravityflow' ),

				// columns tool panel
				'pivotMode'                   => esc_html__( 'Pivot Mode', 'gravityflow' ),
				'groups'                      => esc_html__( 'Row Groups', 'gravityflow' ),
				'rowGroupColumnsEmptyMessage' => esc_html__( 'Drag here to set row groups', 'gravityflow' ),
				'values'                      => esc_html__( 'Values', 'gravityflow' ),
				'valueColumnsEmptyMessage'    => esc_html__( 'Drag here to aggregate', 'gravityflow' ),
				'pivots'                      => esc_html__( 'Column Labels', 'gravityflow' ),
				'pivotColumnsEmptyMessage'    => esc_html__( 'Drag here to set column labels', 'gravityflow' ),

				// Header of the Default Group Column
				'group'                       => esc_html__( 'Group', 'gravityflow' ),

				// Row Drag
				'rowDragRows'                 => esc_html__( 'rows', 'gravityflow' ),

				// Other
				'loadingOoo'                  => esc_html__( 'Loading...', 'gravityflow' ),
				'noRowsToShow'                => esc_html__( 'No Rows To Show', 'gravityflow' ),
				'enabled'                     => esc_html__( 'Enabled', 'gravityflow' ),

				// Menu
				'pinColumn'                   => esc_html__( 'Pin Column', 'gravityflow' ),
				'pinLeft'                     => esc_html__( 'Pin Left', 'gravityflow' ),
				'pinRight'                    => esc_html__( 'Pin Right', 'gravityflow' ),
				'noPin'                       => esc_html__( 'No Pin', 'gravityflow' ),
				'valueAggregation'            => esc_html__( 'Value Aggregation', 'gravityflow' ),
				'autosizeThiscolumn'          => esc_html__( 'Autosize This Column', 'gravityflow' ),
				'autosizeAllColumns'          => esc_html__( 'Autosize All Columns', 'gravityflow' ),
				'groupBy'                     => esc_html__( 'Group by', 'gravityflow' ),
				'ungroupBy'                   => esc_html__( 'Un-Group by', 'gravityflow' ),
				'addToValues'                 => esc_html__( 'Add ${variable} to values', 'gravityflow' ),
				'removeFromValues'            => esc_html__( 'Remove ${variable} from values', 'gravityflow' ),
				'addToLabels'                 => esc_html__( 'Add ${variable} to labels', 'gravityflow' ),
				'removeFromLabels'            => esc_html__( 'Remove ${variable} from labels', 'gravityflow' ),
				'resetColumns'                => esc_html__( 'Reset Columns', 'gravityflow' ),
				'expandAll'                   => esc_html__( 'Expand All', 'gravityflow' ),
				'collapseAll'                 => esc_html__( 'Close All', 'gravityflow' ),
				'copy'                        => esc_html__( 'Copy', 'gravityflow' ),
				'ctrlC'                       => esc_html__( 'Ctrl+C', 'gravityflow' ),
				'copyWithHeaders'             => esc_html__( 'Copy With Headers', 'gravityflow' ),
				'copyWithHeaderGroups'        => esc_html__( 'Copy With Header Groups', 'gravityflow' ),
				'paste'                       => esc_html__( 'Paste', 'gravityflow' ),
				'ctrlV'                       => esc_html__( 'Ctrl+V', 'gravityflow' ),
				'export'                      => esc_html__( 'Export', 'gravityflow' ),
				'csvExport'                   => esc_html__( 'CSV Export', 'gravityflow' ),
				'excelExport'                 => esc_html__( 'Excel Export', 'gravityflow' ),

				// Enterprise Menu Aggregation and Status Bar
				'sum'                         => esc_html__( 'Sum', 'gravityflow' ),
				'min'                         => esc_html__( 'Min', 'gravityflow' ),
				'max'                         => esc_html__( 'Max', 'gravityflow' ),
				'none'                        => esc_html__( 'None', 'gravityflow' ),
				'count'                       => esc_html__( 'Count', 'gravityflow' ),
				'avg'                         => esc_html__( 'Average', 'gravityflow' ),
				'filteredRows'                 => esc_html__( 'Filtered', 'gravityflow' ),
				'selectedRows'                => esc_html__( 'Selected', 'gravityflow' ),
				'totalRows'                   => esc_html__( 'Total Rows', 'gravityflow' ),
				'totalAndFilteredRows'        => esc_html__( 'Rows', 'gravityflow' ),
				'more'                        => esc_html__( 'More', 'gravityflow' ),
				'to'                          => esc_html__( 'to', 'gravityflow' ),
				'of'                          => esc_html__( 'of', 'gravityflow' ),
				'page'                        => esc_html__( 'Page', 'gravityflow' ),
				'nextPage'                    => esc_html__( 'Next Page', 'gravityflow' ),
				'lastPage'                    => esc_html__( 'Last Page', 'gravityflow' ),
				'firstPage'                    => esc_html__( 'First Page', 'gravityflow' ),
				'previousPage'                => esc_html__( 'Previous Page', 'gravityflow' ),

				// Pivoting
				'pivotColumnGroupTotals'      => esc_html__( 'Total', 'gravityflow' ),

				// Enterprise Menu (Charts)
				'pivotChartAndPivotMode'      => esc_html__( 'Pivot Chart & Pivot Mode', 'gravityflow' ),
				'pivotChart'                  => esc_html__( 'Pivot Chart', 'gravityflow' ),
				'chartRange'                  => esc_html__( 'Chart Range', 'gravityflow' ),

				'columnChart'      => esc_html__( 'Column', 'gravityflow' ),
				'groupedColumn'    => esc_html__( 'Grouped', 'gravityflow' ),
				'stackedColumn'    => esc_html__( 'Stacked', 'gravityflow' ),
				'normalizedColumn' => esc_html__( '100% Stacked', 'gravityflow' ),

				'barChart'      => esc_html__( 'Bar', 'gravityflow' ),
				'groupedBar'    => esc_html__( 'Grouped', 'gravityflow' ),
				'stackedBar'    => esc_html__( 'Stacked', 'gravityflow' ),
				'normalizedBar' => esc_html__( '100% Stacked', 'gravityflow' ),

				'pieChart' => esc_html__( 'Pie', 'gravityflow' ),
				'pie'      => esc_html__( 'Pie', 'gravityflow' ),
				'doughnut' => esc_html__( 'Doughnut', 'gravityflow' ),

				'line' => esc_html__( 'Line', 'gravityflow' ),

				'xyChart' => esc_html__( 'X Y (Scatter)', 'gravityflow' ),
				'scatter' => esc_html__( 'Scatter', 'gravityflow' ),
				'bubble'  => esc_html__( 'Bubble', 'gravityflow' ),

				'areaChart'      => esc_html__( 'Area', 'gravityflow' ),
				'area'           => esc_html__( 'Area', 'gravityflow' ),
				'stackedArea'    => esc_html__( 'Stacked', 'gravityflow' ),
				'normalizedArea' => esc_html__( '100% Stacked', 'gravityflow' ),

				'histogramChart' => esc_html__( 'Histogram', 'gravityflow' ),

				'combinationChart'            => esc_html__( 'Combination', 'gravityflow' ),
				'columnLineCombo'             => esc_html__( 'Column & Line', 'gravityflow' ),
				'AreaColumnCombo'             => esc_html__( 'Area & Column', 'gravityflow' ),

				// Charts
				'pivotChartTitle'             => esc_html__( 'Pivot Chart', 'gravityflow' ),
				'rangeChartTitle'             => esc_html__( 'Range Chart', 'gravityflow' ),
				'settings'                    => esc_html__( 'Settings', 'gravityflow' ),
				'data'                        => esc_html__( 'Data', 'gravityflow' ),
				'format'                      => esc_html__( 'Format', 'gravityflow' ),
				'categories'                  => esc_html__( 'Categories', 'gravityflow' ),
				'defaultCategory'             => esc_html__( '(None)', 'gravityflow' ),
				'series'                      => esc_html__( 'Series', 'gravityflow' ),
				'xyValues'                    => esc_html__( 'X Y Values', 'gravityflow' ),
				'paired'                      => esc_html__( 'Paired Mode', 'gravityflow' ),
				'axis'                        => esc_html__( 'Axis', 'gravityflow' ),
				'navigator'                   => esc_html__( 'Navigator', 'gravityflow' ),
				'color'                       => esc_html__( 'Color', 'gravityflow' ),
				'thickness'                   => esc_html__( 'Thickness', 'gravityflow' ),
				'xType'                       => esc_html__( 'X Type', 'gravityflow' ),
				'automatic'                   => esc_html__( 'Automatic', 'gravityflow' ),
				'category'                    => esc_html__( 'Category', 'gravityflow' ),
				'number'                      => esc_html__( 'Number', 'gravityflow' ),
				'time'                        => esc_html__( 'Time', 'gravityflow' ),
				'xRotation'                   => esc_html__( 'X Rotation', 'gravityflow' ),
				'yRotation'                   => esc_html__( 'Y Rotation', 'gravityflow' ),
				'ticks'                       => esc_html__( 'Ticks', 'gravityflow' ),
				'width'                       => esc_html__( 'Width', 'gravityflow' ),
				'height'                      => esc_html__( 'Height', 'gravityflow' ),
				'length'                      => esc_html__( 'Length', 'gravityflow' ),
				'padding'                     => esc_html__( 'Padding', 'gravityflow' ),
				'spacing'                     => esc_html__( 'Spacing', 'gravityflow' ),
				'chart'                       => esc_html__( 'Chart', 'gravityflow' ),
				'title'                       => esc_html__( 'Title', 'gravityflow' ),
				'titlePlaceholder'            => esc_html__( 'Chart title - double click to edit', 'gravityflow' ),
				'background'                  => esc_html__( 'Background', 'gravityflow' ),
				'font'                        => esc_html__( 'Font', 'gravityflow' ),
				'top'                         => esc_html__( 'Top', 'gravityflow' ),
				'right'                       => esc_html__( 'Right', 'gravityflow' ),
				'bottom'                      => esc_html__( 'Bottom', 'gravityflow' ),
				'left'                        => esc_html__( 'Left', 'gravityflow' ),
				'labels'                      => esc_html__( 'Labels', 'gravityflow' ),
				'size'                        => esc_html__( 'Size', 'gravityflow' ),
				'minSize'                     => esc_html__( 'Minimum Size', 'gravityflow' ),
				'maxSize'                     => esc_html__( 'Maximum Size', 'gravityflow' ),
				'legend'                      => esc_html__( 'Legend', 'gravityflow' ),
				'position'                    => esc_html__( 'Position', 'gravityflow' ),
				'markerSize'                  => esc_html__( 'Marker Size', 'gravityflow' ),
				'markerStroke'                => esc_html__( 'Marker Stroke', 'gravityflow' ),
				'markerPadding'               => esc_html__( 'Marker Padding', 'gravityflow' ),
				'itemSpacing'                 => esc_html__( 'Item Spacing', 'gravityflow' ),
				'itemPaddingX'                => esc_html__( 'Item Padding X', 'gravityflow' ),
				'itemPaddingY'                => esc_html__( 'Item Padding Y', 'gravityflow' ),
				'layoutHorizontalSpacing'     => esc_html__( 'Horizontal Spacing', 'gravityflow' ),
				'layoutVerticalSpacing'       => esc_html__( 'Vertical Spacing', 'gravityflow' ),
				'strokeWidth'                 => esc_html__( 'Stroke Width', 'gravityflow' ),
				'offset'                      => esc_html__( 'Offset', 'gravityflow' ),
				'offsets'                     => esc_html__( 'Offsets', 'gravityflow' ),
				'tooltips'                    => esc_html__( 'Tooltips', 'gravityflow' ),
				'callout'                     => esc_html__( 'Callout', 'gravityflow' ),
				'markers'                     => esc_html__( 'Markers', 'gravityflow' ),
				'shadow'                      => esc_html__( 'Shadow', 'gravityflow' ),
				'blur'                        => esc_html__( 'Blur', 'gravityflow' ),
				'xOffset'                     => esc_html__( 'X Offset', 'gravityflow' ),
				'yOffset'                     => esc_html__( 'Y Offset', 'gravityflow' ),
				'lineWidth'                   => esc_html__( 'Line Width', 'gravityflow' ),
				'normal'                      => esc_html__( 'Normal', 'gravityflow' ),
				'bold'                        => esc_html__( 'Bold', 'gravityflow' ),
				'italic'                      => esc_html__( 'Italic', 'gravityflow' ),
				'boldItalic'                  => esc_html__( 'Bold Italic', 'gravityflow' ),
				'predefined'                   => esc_html__( 'Predefined', 'gravityflow' ),
				'fillOpacity'                  => esc_html__( 'Fill Opacity', 'gravityflow' ),
				'strokeOpacity'               => esc_html__( 'Line Opacity', 'gravityflow' ),
				'histogramBinCount'           => esc_html__( 'Bin count', 'gravityflow' ),
				'columnGroup'                 => esc_html__( 'Column', 'gravityflow' ),
				'barGroup'                    => esc_html__( 'Bar', 'gravityflow' ),
				'pieGroup'                    => esc_html__( 'Pie', 'gravityflow' ),
				'lineGroup'                   => esc_html__( 'Line', 'gravityflow' ),
				'scatterGroup'                => esc_html__( 'X Y (Scatter)', 'gravityflow' ),
				'areaGroup'                   => esc_html__( 'Area', 'gravityflow' ),
				'histogramGroup'              => esc_html__( 'Histogram', 'gravityflow' ),
				'combinationGroup'            => esc_html__( 'Combination', 'gravityflow' ),
				'groupedColumnTooltip'        => esc_html__( 'Grouped', 'gravityflow' ),
				'stackedColumnTooltip'        => esc_html__( 'Stacked', 'gravityflow' ),
				'normalizedColumnTooltip'     => esc_html__( '100% Stacked', 'gravityflow' ),
				'groupedBarTooltip'           => esc_html__( 'Grouped', 'gravityflow' ),
				'stackedBarTooltip'           => esc_html__( 'Stacked', 'gravityflow' ),
				'normalizedBarTooltip'        => esc_html__( '100% Stacked', 'gravityflow' ),
				'pieTooltip'                  => esc_html__( 'Pie', 'gravityflow' ),
				'doughnutTooltip'             => esc_html__( 'Doughnut', 'gravityflow' ),
				'lineTooltip'                 => esc_html__( 'Line', 'gravityflow' ),
				'groupedAreaTooltip'          => esc_html__( 'Area', 'gravityflow' ),
				'stackedAreaTooltip'          => esc_html__( 'Stacked', 'gravityflow' ),
				'normalizedAreaTooltip'       => esc_html__( '100% Stacked', 'gravityflow' ),
				'scatterTooltip'              => esc_html__( 'Scatter', 'gravityflow' ),
				'bubbleTooltip'               => esc_html__( 'Bubble', 'gravityflow' ),
				'histogramTooltip'            => esc_html__( 'Histogram', 'gravityflow' ),
				'columnLineComboTooltip'      => esc_html__( 'Column & Line', 'gravityflow' ),
				'areaColumnComboTooltip'      => esc_html__( 'Area & Column', 'gravityflow' ),
				'customComboTooltip'          => esc_html__( 'Custom Combination', 'gravityflow' ),
				'noDataToChart'               => esc_html__( 'No data available to be charted.', 'gravityflow' ),
				'pivotChartRequiresPivotMode' => esc_html__( 'Pivot Chart requires Pivot Mode enabled.', 'gravityflow' ),
				'chartSettingsToolbarTooltip' => esc_html__( 'Menu', 'gravityflow' ),
				'chartLinkToolbarTooltip'     => esc_html__( 'Linked to Grid', 'gravityflow' ),
				'chartUnlinkToolbarTooltip'   => esc_html__( 'Unlinked from Grid', 'gravityflow' ),
				'chartDownloadToolbarTooltip' => esc_html__( 'Download Chart', 'gravityflow' ),
				'seriesChartType'             => esc_html__( 'Series Chart Type', 'gravityflow' ),
				'seriesType'                  => esc_html__( 'Series Type', 'gravityflow' ),
				'secondaryAxis'               => esc_html__( 'Secondary Axis', 'gravityflow' ),

				// ARIA
				'ariaHidden'                  => esc_html__( 'hidden', 'gravityflow' ),
				'ariaVisible'                 => esc_html__( 'visible', 'gravityflow' ),
				'ariaChecked'                 => esc_html__( 'checked', 'gravityflow' ),
				'ariaUnchecked'               => esc_html__( 'unchecked', 'gravityflow' ),
				'ariaIndeterminate'           => esc_html__( 'indeterminate', 'gravityflow' ),
				'ariaDefaultListName'         => esc_html__( 'list', 'gravityflow' ),
				'ariaColumnSelectAll'         => esc_html__( 'Toggle Select All Columns', 'gravityflow' ),
				'ariaInputEditor'             => esc_html__( 'Input Editor', 'gravityflow' ),
				'ariaDateFilterInput'         => esc_html__( 'Date Filter Input', 'gravityflow' ),
				'ariaFilterList'              => esc_html__( 'Filter list', 'gravityflow' ),
				'ariaFilterInput'             => esc_html__( 'Filter Input', 'gravityflow' ),
				'ariaFilterColumnsInput'      => esc_html__( 'Filter Columns Input', 'gravityflow' ),
				'ariaFilterValue'             => esc_html__( 'Filter Value', 'gravityflow' ),
				'ariaFilterFromValue'         => esc_html__( 'Filter from value', 'gravityflow' ),
				'ariaFilterToValue'           => esc_html__( 'Filter to value', 'gravityflow' ),
				'ariaFilteringOperator'       => esc_html__( 'Filtering Operator', 'gravityflow' ),
				'ariaColumn'                  => esc_html__( 'Column', 'gravityflow' ),
				'ariaColumnList'              => esc_html__( 'Column list', 'gravityflow' ),
				'ariaColumnGroup'             => esc_html__( 'Column Group', 'gravityflow' ),
				'ariaRowSelect'               => esc_html__( 'Press SPACE to select this row', 'gravityflow' ),
				'ariaRowDeselect'             => esc_html__( 'Press SPACE to deselect this row', 'gravityflow' ),
				'ariaRowToggleSelection'      => esc_html__( 'Press SPACE to toggle row selection', 'gravityflow' ),
				'ariaRowSelectAll'            => esc_html__( 'Press SPACE to toggle all rows selection', 'gravityflow' ),
				'ariaToggleVisibility'        => esc_html__( 'Press SPACE to toggle visibility', 'gravityflow' ),
				'ariaSearch'                  => esc_html__( 'Search', 'gravityflow' ),
				'ariaSearchFilterValues'      => esc_html__( 'Search filter values', 'gravityflow' ),

				'ariaRowGroupDropZonePanelLabel'         => esc_html__( 'Row Groups', 'gravityflow' ),
				'ariaValuesDropZonePanelLabel'           => esc_html__( 'Values', 'gravityflow' ),
				'ariaPivotDropZonePanelLabel'            => esc_html__( 'Column Labels', 'gravityflow' ),
				'ariaDropZoneColumnComponentDescription' => esc_html__( 'Press DELETE to remove', 'gravityflow' ),
				'ariaDropZoneColumnValueItemDescription' => esc_html__( 'Press ENTER to change the aggregation type', 'gravityflow' ),

				// ARIA Labels for Dialogs
				'ariaLabelColumnMenu'                    => esc_html__( 'Column Menu', 'gravityflow' ),
				'ariaLabelCellEditor'                    => esc_html__( 'Cell Editor', 'gravityflow' ),
				'ariaLabelDialog'                        => esc_html__( 'Dialog', 'gravityflow' ),
				'ariaLabelSelectField'                   => esc_html__( 'Select Field', 'gravityflow' ),
				'ariaLabelTooltip'                       => esc_html__( 'Tooltip', 'gravityflow' ),
				'ariaLabelContextMenu'                   => esc_html__( 'Context Menu', 'gravityflow' ),
				'ariaLabelSubMenu'                       => esc_html__( 'SubMenu', 'gravityflow' ),
				'ariaLabelAggregationFunction'           => esc_html__( 'Aggregation function', 'gravityflow' ),

				// Number Format (Status Bar, Pagination Panel)
				'thousandSeparator'                      => esc_html__( ',', 'gravityflow' ),
				'decimalSeparator'                       => esc_html__( ' . ', 'gravityflow' ),
			),
			'browser_notifications_error'  => esc_html__( 'Your browser does not support notifications. ', 'gravityflow' ),
			'cancel'                      => esc_html__( 'Cancel', 'gravityflow' ),
			'disabled'                    => esc_html__( 'Disabled', 'gravityflow' ),
			'enabled'                     => esc_html__( 'Enabled', 'gravityflow' ),
			'inbox_notification_title'     => esc_html__( 'Workflow Inbox', 'gravityflow' ),
			'inbox_settings'              => esc_html__( 'Inbox Settings', 'gravityflow' ),
			'loading'                     => esc_html__( 'Loading...', 'gravityflow' ),
			'new_inbox_items'             => esc_html__( 'You have a new item in your workflow inbox. ', 'gravityflow' ),
			'no_entries'                  => esc_html__( 'No Pending Tasks', 'gravityflow' ),
			'options_for_grid_not_found'  => esc_html__( 'Can\'t find inbox options for grid ID:', 'gravityflow' ),
			'search_inbox'                => esc_html__( 'Search Inbox', 'gravityflow' ),
			'settings_push_title'         => esc_html__( 'Enable Push Notifications', 'gravityflow' ),
			'settings_push_desc'          => esc_html__( 'You will have to allow push notifications for this domain and browser to enable this feature. If you haven\'t already,
		your browser will ask you to enable them one time.', 'gravityflow' ),
			'submit'                      => esc_html__( 'Submit', 'gravityflow' ),
			'toggle_fullscreen_title'     => esc_html__( 'Toggle fullscreen for this table', 'gravityflow' ),
			'toggle_settings_title'       => esc_html__( 'Toggle settings for this table', 'gravityflow' ),
			'toggle_clear_filters_title'   => esc_html__( 'Clear active filters for this table', 'gravityflow' ),
			'view_entry'                  => esc_html__( 'View', 'gravityflow' ),
			'view_quick_actions'          => esc_html__( 'View quick actions', 'gravityflow' ),
		);
	}

}
