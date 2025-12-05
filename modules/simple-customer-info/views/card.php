<section class="sfa-sci-card" <?php echo $collapsed ? 'data-collapsed="1"' : ''; ?>
         data-mapped="<?php echo esc_attr( $encoded_ids ); ?>">

  <header class="sfa-sci-header" role="button" tabindex="0" aria-expanded="true" aria-controls="sfa-sci-body">
    <div class="sfa-sci-header-left">
      <div class="sfa-sci-subtitle"><?php echo esc_html( $subtitle ); ?></div>
      <div class="sfa-sci-title"><?php echo esc_html( $title ); ?></div>
    </div>
    <div class="sfa-sci-header-right">
      <?php if ( ! empty( $entry_id ) ) : ?>
        <span class="sfa-sci-badge" <?php echo isset($badge_style) ? $badge_style : ""; ?>><?php echo esc_html__( 'Entry', 'simple-flow-attachment' ); ?> #<?php echo (int) $entry_id; ?></span>
      <?php endif; ?>
    </div>
  </header>

  <div class="sfa-sci-body" id="sfa-sci-body" hidden>
    <div class="sfa-sci-grid">
      <?php foreach ( $rows as $r ) : ?>
        <div class="sfa-sci-item" data-field-id="<?php echo (int) $r['field_id']; ?>">
          <div class="sfa-sci-key"><?php echo esc_html( $r['label'] ); ?></div>
          <div class="sfa-sci-val"><?php echo esc_html( $r['value'] ); ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
