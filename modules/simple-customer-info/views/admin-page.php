<div class="wrap gforms_form_settings">
	<h2 class="gform-settings__title"><?php esc_html_e('Simple Customer Information – Mapping', 'simple-flow-attachment'); ?></h2>
	<form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>">
		<?php wp_nonce_field('sfa_sci_save_'.$form_id, \SFA\SCI\AdminController::NONCE); ?>
		<input type="hidden" name="action" value="sfa_sci_save">
		<input type="hidden" name="form_id" value="<?php echo (int)$form_id; ?>">
		
		<h3><?php esc_html_e('Status Badge','simple-flow-attachment'); ?></h3>
		<?php
			$__fields_idx = array();
			$form = \GFAPI::get_form($form_id);
			if ( is_array($form) && !empty($form['fields']) ) {
				foreach ( $form['fields'] as $f ) {
					if ( !empty($f->id) ) { $__fields_idx[ $f->id ] = (string) ( $f->label ?? ('Field '.$f->id) ); }
				}
			}
			$__badge_field = isset($map['options']['badge_field_id']) ? (int)$map['options']['badge_field_id'] : 0;
			$__badge_colors = isset($map['options']['badge_colors']) ? (string)$map['options']['badge_colors'] : '';
		?>
		<table class="widefat striped">
			<tbody>
				<tr>
					<th style="width:220px;"><?php esc_html_e('Dropdown Field (status)', 'simple-flow-attachment'); ?></th>
					<td>
						<select name="options[badge_field_id]">
							<option value="0"><?php esc_html_e('— Select a field —','simple-flow-attachment'); ?></option>
							<?php foreach ( $__fields_idx as $fid=>$flabel ): ?>
								<option value="<?php echo (int)$fid; ?>" <?php selected($__badge_field, (int)$fid); ?>><?php echo esc_html($fid.' — '.$flabel); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e('Pick the dropdown field that controls the badge color.', 'simple-flow-attachment'); ?></p>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e('Value → Color map', 'simple-flow-attachment'); ?></th>
					<td>
						<textarea name="options[badge_colors]" rows="5" style="width:100%;" placeholder="New Entry | #16a34a&#10;Pending Review | #f59e0b&#10;Completed | #ef4444"><?php echo esc_textarea($__badge_colors); ?></textarea>
						<p class="description"><?php esc_html_e('One per line. Use Label | #hex (or Label: #hex). Case-insensitive label match.', 'simple-flow-attachment'); ?></p>
					</td>
				</tr>
                <tr>
                    <th><?php esc_html_e('Additional phone field IDs', 'simple-flow-attachment'); ?></th>
                    <td>
                        <?php $__opt_phone = isset($map['options']['optional_phone_ids']) ? (string)$map['options']['optional_phone_ids'] : ''; ?>
                        <input type="text" name="options[optional_phone_ids]" value="<?php echo esc_attr($__opt_phone); ?>" placeholder="46" style="width: 280px;">
                        <p class="description"><?php esc_html_e('Comma-separated GF field IDs to treat as "Additional Phone". If value has fewer than 4 digits (e.g., just a country code), the card hides the row.', 'simple-flow-attachment'); ?></p>
                    </td>
                </tr>
    
			</tbody>
		</table>



		<h3><?php esc_html_e('Preset Slots','simple-flow-attachment'); ?></h3>
		<table class="widefat striped">
			<thead><tr><th><?php esc_html_e('Label','simple-flow-attachment'); ?></th><th><?php esc_html_e('Field','simple-flow-attachment'); ?></th></tr></thead>
			<tbody>
				<?php foreach ( $map['preset'] as $key=>$slot): ?>
				<tr>
					<td style="min-width:220px;"><input type="text" class="regular-text" name="preset[<?php echo esc_attr($key); ?>][label]" value="<?php echo esc_attr($slot['label']); ?>"></td>
					<td>
						<select name="preset[<?php echo esc_attr($key); ?>][field_id]">
							<option value=""><?php esc_html_e('— Not mapped —','simple-flow-attachment'); ?></option>
							<?php foreach ($fields as $fid=>$flabel): ?><option value="<?php echo (int)$fid; ?>" <?php selected((int)$slot['field_id'],(int)$fid); ?>><?php echo esc_html("#$fid — $flabel"); ?></option><?php endforeach; ?>
						</select>
					</td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<h3 style="margin-top:20px;"><?php esc_html_e('Extra Slots','simple-flow-attachment'); ?></h3>
		<table class="widefat striped" id="sfa-sci-extra-table">
			<thead><tr><th><?php esc_html_e('Label','simple-flow-attachment'); ?></th><th><?php esc_html_e('Field','simple-flow-attachment'); ?></th><th></th></tr></thead>
			<tbody>
				<?php if (!empty($map['extra'])): foreach ($map['extra'] as $i=>$slot): ?>
				<tr>
					<td><input type="text" class="regular-text" name="extra[<?php echo (int)$i; ?>][label]" value="<?php echo esc_attr($slot['label']); ?>"></td>
					<td>
						<select name="extra[<?php echo (int)$i; ?>][field_id]">
							<option value=""><?php esc_html_e('— Not mapped —','simple-flow-attachment'); ?></option>
							<?php foreach ($fields as $fid=>$flabel): ?><option value="<?php echo (int)$fid; ?>" <?php selected((int)$slot['field_id'],(int)$fid); ?>><?php echo esc_html("#$fid — $flabel"); ?></option><?php endforeach; ?>
						</select>
					</td>
					<td><a href="#" class="button link-delete-row"><?php esc_html_e('Remove','simple-flow-attachment'); ?></a></td>
				</tr>
				<?php endforeach; endif; ?>
			</tbody>
		</table>
		<p><a href="#" class="button" id="sfa-sci-add-row"><?php esc_html_e('Add row','simple-flow-attachment'); ?></a></p>

		<template id="sfa-sci-row-template">
			<tr>
				<td><input type="text" class="regular-text" name="extra[__IDX__][label]"></td>
				<td>
					<select name="extra[__IDX__][field_id]">
						<option value=""><?php esc_html_e('— Not mapped —','simple-flow-attachment'); ?></option>
						<?php foreach ($fields as $fid=>$flabel): ?><option value="<?php echo (int)$fid; ?>"><?php echo esc_html("#$fid — $flabel"); ?></option><?php endforeach; ?>
					</select>
				</td>
				<td><a href="#" class="button link-delete-row"><?php esc_html_e('Remove','simple-flow-attachment'); ?></a></td>
			</tr>
		</template>

		<h3 style="margin-top:20px;"><?php esc_html_e('Options','simple-flow-attachment'); ?></h3>
		<label><input type="checkbox" name="options[collapse_mobile]" value="1" <?php checked(!empty($map['options']['collapse_mobile'])); ?>> <?php esc_html_e('Collapse by default','simple-flow-attachment'); ?></label><br>
		<label><input type="checkbox" name="options[hide_native]" value="1" <?php checked(!empty($map['options']['hide_native'])); ?>> <?php esc_html_e('Hide mapped fields from native detail','simple-flow-attachment'); ?></label>

		<p style="margin-top:16px;"><button type="submit" class="button button-primary"><?php esc_html_e('Save Mapping','simple-flow-attachment'); ?></button></p>
	</form>

	<hr style="margin:24px 0;">
	<h3><?php esc_html_e('Debug Status','simple-flow-attachment'); ?></h3>
	<table class="widefat striped"><tbody>
		<tr><td style="width:240px">GET id</td><td><?php echo isset($_GET['id']) ? esc_html($_GET['id']) : ''; ?></td></tr>
		<tr><td>Form ID</td><td><?php echo (int)$form_id; ?></td></tr>
		<tr><td>GF active</td><td><?php echo class_exists('GFForms')?'yes':'no'; ?></td></tr>
		<tr><td>GFAPI available</td><td><?php echo class_exists('GFAPI')?'yes':'no'; ?></td></tr>
		<tr><td>Gravity Flow active</td><td><?php echo function_exists('gravity_flow')?'yes':'no'; ?></td></tr>
	</tbody></table>
</div>

<script>
(function(){
	const addBtn=document.getElementById('sfa-sci-add-row');
	const tbody=document.querySelector('#sfa-sci-extra-table tbody');
	const tpl=document.getElementById('sfa-sci-row-template');
	let idx=tbody?tbody.children.length:0;
	if(addBtn && tbody && tpl){
		addBtn.addEventListener('click',function(e){
			e.preventDefault();
			const node=tpl.content.cloneNode(true);
			node.querySelectorAll('[name]').forEach(function(el){ el.name=el.name.replace('__IDX__', idx); });
			tbody.appendChild(node); idx++;
		});
	}
	document.addEventListener('click',function(e){
		if(e.target && e.target.classList.contains('link-delete-row')){ e.preventDefault(); var tr=e.target.closest('tr'); if(tr) tr.remove(); }
	});
})();
</script>
