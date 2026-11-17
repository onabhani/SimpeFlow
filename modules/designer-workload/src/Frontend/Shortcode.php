<?php
namespace SFA\DesignerWorkload\Frontend;

use SFA\DesignerWorkload\Data\WorkloadRepository;
use SFA\DesignerWorkload\Support\Access;
use SFA\DesignerWorkload\Support\Format;
use SFA\DesignerWorkload\Support\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * [designer_workload]: read-only board of each designer's pending tasks.
 */
class Shortcode {

	const TAG = 'designer_workload';

	public function __construct() {
		add_shortcode( self::TAG, array( $this, 'render' ) );
	}

	/**
	 * @param array<string, mixed>|string $atts
	 */
	public function render( $atts = array() ): string {
		$user_id = get_current_user_id();
		$user    = $user_id ? wp_get_current_user() : null;
		$switched = $this->switch_locale( $user_id );

		try {
			$settings = Settings::get();
			$allowed  = $user && Access::is_allowed( $user_id, (array) $user->roles, $settings['roles'], $settings['users'] );

			if ( ! $allowed ) {
				return '<p class="sfa-dw-denied">' . esc_html__( 'You do not have access to this page.', 'simpleflow' ) . '</p>';
			}

			wp_enqueue_style( 'sfa-dw', SFA_DW_URL . 'assets/designer-workload.css', array(), SFA_DW_VER );
			wp_enqueue_script( 'sfa-dw', SFA_DW_URL . 'assets/designer-workload.js', array(), SFA_DW_VER, true );

			return $this->render_board( WorkloadRepository::get_board() );
		} finally {
			if ( $switched ) {
				restore_previous_locale();
			}
		}
	}

	/**
	 * Render in the viewer's profile language and load the module's catalogue.
	 */
	private function switch_locale( int $user_id ): bool {
		$switched = false;
		if ( $user_id ) {
			$switched = function_exists( 'switch_to_user_locale' )
				? switch_to_user_locale( $user_id )
				: switch_to_locale( get_user_locale( $user_id ) );
		}

		$mofile = SFA_DW_DIR . 'languages/simpleflow-' . get_locale() . '.mo';
		if ( is_readable( $mofile ) ) {
			load_textdomain( 'simpleflow', $mofile );
		}
		return (bool) $switched;
	}

	/**
	 * @param array{generated_at: int, designers: array<int, array<string, mixed>>} $board
	 */
	private function render_board( array $board ): string {
		$designers = $board['designers'];
		$now       = time();
		$total     = 0;
		$forms     = array();

		foreach ( $designers as $designer ) {
			$total += Format::count( $designer['tasks'] );
			foreach ( $designer['tasks'] as $task ) {
				$forms[ $task['form_id'] ] = $task['form_title'];
			}
		}
		ksort( $forms );

		ob_start();
		?>
		<div class="sfa-dw" dir="<?php echo is_rtl() ? 'rtl' : 'ltr'; ?>" data-total="<?php echo esc_attr( (string) $total ); ?>">
			<div class="sfa-dw-head">
				<div>
					<h2 class="sfa-dw-title"><?php echo esc_html__( 'Designer Workload', 'simpleflow' ); ?></h2>
					<p class="sfa-dw-sub"><?php echo esc_html__( 'Open drawing tasks per designer, oldest first', 'simpleflow' ); ?></p>
				</div>
				<?php if ( count( $forms ) > 1 ) : ?>
					<div class="sfa-dw-filters" role="group" aria-label="<?php echo esc_attr__( 'Filter by form', 'simpleflow' ); ?>">
						<button type="button" class="sfa-dw-chip" data-form="all" aria-pressed="true"><?php echo esc_html__( 'All forms', 'simpleflow' ); ?></button>
						<?php foreach ( $forms as $form_id => $title ) : ?>
							<button type="button" class="sfa-dw-chip" data-form="<?php echo esc_attr( (string) $form_id ); ?>" aria-pressed="false"><?php echo esc_html( $title ); ?></button>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</div>

			<?php if ( ! $designers ) : ?>
				<p class="sfa-dw-empty"><?php echo esc_html__( 'No designers found.', 'simpleflow' ); ?></p>
			<?php else : ?>
				<div class="sfa-dw-summary">
					<?php foreach ( $designers as $designer ) : ?>
						<?php
						$count = Format::count( $designer['tasks'] );
						$split = array();
						foreach ( $forms as $form_id => $title ) {
							$split[ $form_id ] = Format::count(
								array_filter(
									$designer['tasks'],
									static function ( array $t ) use ( $form_id ): bool {
										return (int) $t['form_id'] === (int) $form_id;
									}
								)
							);
						}
						?>
						<button type="button" class="sfa-dw-card" data-designer="<?php echo esc_attr( (string) $designer['id'] ); ?>">
							<span class="sfa-dw-card-who">
								<span class="sfa-dw-name"><bdi><?php echo esc_html( $designer['name'] ); ?></bdi></span>
								<?php $branch = $this->branch_label( $designer['branch'] ); ?>
								<?php if ( '' !== $branch ) : ?>
									<span class="sfa-dw-branch"><?php echo esc_html( $branch ); ?></span>
								<?php endif; ?>
							</span>
							<span class="sfa-dw-count"><span class="sfa-dw-num" data-count><?php echo esc_html( (string) $count ); ?></span> <small><?php echo esc_html__( 'open', 'simpleflow' ); ?></small></span>
							<span class="sfa-dw-bar" aria-hidden="true"><span style="width:<?php echo esc_attr( (string) ( $total ? round( $count / $total * 100 ) : 0 ) ); ?>%"></span></span>
							<?php if ( count( $forms ) > 1 ) : ?>
								<span class="sfa-dw-split">
									<?php
									$parts = array();
									foreach ( $split as $form_id => $n ) {
										$parts[] = $forms[ $form_id ] . ' ' . $n;
									}
									echo esc_html( implode( ' · ', $parts ) );
									?>
								</span>
							<?php endif; ?>
						</button>
					<?php endforeach; ?>
				</div>

				<div class="sfa-dw-lists">
					<?php foreach ( $designers as $designer ) : ?>
						<details class="sfa-dw-designer" id="sfa-dw-designer-<?php echo esc_attr( (string) $designer['id'] ); ?>" data-designer="<?php echo esc_attr( (string) $designer['id'] ); ?>" open>
							<summary>
								<span class="sfa-dw-caret" aria-hidden="true">›</span>
								<span class="sfa-dw-name"><bdi><?php echo esc_html( $designer['name'] ); ?></bdi></span>
								<?php $branch = $this->branch_label( $designer['branch'] ); ?>
								<?php if ( '' !== $branch ) : ?>
									<span class="sfa-dw-branch"><?php echo esc_html( $branch ); ?></span>
								<?php endif; ?>
								<span class="sfa-dw-pill" data-count><?php echo esc_html( (string) Format::count( $designer['tasks'] ) ); ?></span>
							</summary>
							<div class="sfa-dw-tablewrap">
								<table class="sfa-dw-table">
									<thead>
										<tr>
											<th scope="col"><?php echo esc_html__( 'Order #', 'simpleflow' ); ?></th>
											<th scope="col"><?php echo esc_html__( 'Client', 'simpleflow' ); ?></th>
											<th scope="col"><?php echo esc_html__( 'Form', 'simpleflow' ); ?></th>
											<th scope="col"><?php echo esc_html__( 'Current step', 'simpleflow' ); ?></th>
											<th scope="col"><?php echo esc_html__( 'Waiting', 'simpleflow' ); ?></th>
										</tr>
									</thead>
									<tbody>
										<?php foreach ( $designer['tasks'] as $task ) : ?>
											<?php
											$days = Format::age_days( (int) $task['start_ts'], $now );
											$url  = home_url( '/workflow-inbox/' ) . '?page=gravityflow-inbox&view=entry&id=' . (int) $task['form_id'] . '&lid=' . (int) $task['entry_id'];
											?>
											<tr data-form="<?php echo esc_attr( (string) $task['form_id'] ); ?>">
												<td class="sfa-dw-c-id"><a href="<?php echo esc_url( $url ); ?>"><bdi>#<?php echo esc_html( (string) $task['entry_id'] ); ?></bdi></a></td>
												<td class="sfa-dw-c-client"><bdi><?php echo esc_html( '' !== $task['client'] ? $task['client'] : __( 'No name', 'simpleflow' ) ); ?></bdi></td>
												<td class="sfa-dw-c-form"><span class="sfa-dw-form"><?php echo esc_html( $task['form_title'] ); ?></span></td>
												<td class="sfa-dw-c-step"><bdi><?php echo esc_html( '' !== $task['step_name'] ? $task['step_name'] : __( 'Unknown step', 'simpleflow' ) ); ?></bdi></td>
												<td class="sfa-dw-c-age">
													<span class="sfa-dw-age sfa-dw-age-<?php echo esc_attr( Format::age_bucket( $days ) ); ?>">
														<?php
														/* translators: %d: number of days the task has been waiting. */
														echo esc_html( sprintf( _n( '%d day', '%d days', $days, 'simpleflow' ), $days ) );
														?>
													</span>
												</td>
											</tr>
										<?php endforeach; ?>
										<tr class="sfa-dw-none"<?php echo $designer['tasks'] ? ' hidden' : ''; ?>>
											<td colspan="5"><?php echo esc_html__( 'No open tasks', 'simpleflow' ); ?></td>
										</tr>
									</tbody>
								</table>
							</div>
						</details>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<p class="sfa-dw-stamp">
				<?php
				/* translators: %s: time the list was last refreshed. */
				echo esc_html( sprintf( __( 'Updated %s', 'simpleflow' ), wp_date( (string) get_option( 'time_format' ), (int) $board['generated_at'] ) ) );
				?>
			</p>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	private function branch_label( string $key ): string {
		switch ( $key ) {
			case 'alnuzhah':
				return __( 'Alnuzhah', 'simpleflow' );
			case 'alyasmin':
				return __( 'Alyasmin', 'simpleflow' );
			case 'touches':
				return __( 'Touches', 'simpleflow' );
			default:
				return '';
		}
	}
}
