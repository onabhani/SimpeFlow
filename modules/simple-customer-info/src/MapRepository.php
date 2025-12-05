<?php namespace SFA\SCI;
class MapRepository {
	private string $meta_key;
	private array $preset = [
		'file_number'      => ['label'=>'File Number','field_id'=>0],
		'customer_name_ar' => ['label'=>'Customer Name (AR)','field_id'=>0],
		'customer_name_en' => ['label'=>'Customer Name (EN)','field_id'=>0],
		'phone_primary'    => ['label'=>'Phone','field_id'=>0],
		'phone_secondary'  => ['label'=>'Additional Phone','field_id'=>0],
		'email'            => ['label'=>'Email','field_id'=>0],
		'address'          => ['label'=>'Address','field_id'=>0],
		'branch'           => ['label'=>'Branch','field_id'=>0],
		'customer_type'    => ['label'=>'Customer Type','field_id'=>0],
	];
	public function __construct( string $meta_key ) { $this->meta_key = $meta_key; }
	public function get(int $form_id): array {
		$form = \GFAPI::get_form($form_id);
		$map  = ( is_array($form) && isset($form[$this->meta_key]) ) ? $form[$this->meta_key] : [];
		$map  = wp_parse_args($map, ['preset'=>[], 'extra'=>[], 'options'=>['collapse_mobile'=>1,'hide_native'=>1]]);
		foreach ( $this->preset as $k=>$def ) { $map['preset'][$k] = isset($map['preset'][$k]) ? wp_parse_args($map['preset'][$k], $def) : $def; }
		return $map;
	}
	public function save(int $form_id, array $data): void {
		$form = \GFAPI::get_form($form_id);
		if (!is_array($form)) return;
		$form[$this->meta_key] = $data;
		\GFAPI::update_form($form, $form_id);
	}
	public function fields_indexed(int $form_id): array {
		$out=[]; $form=\GFAPI::get_form($form_id); if(!$form) return $out;
		foreach ($form['fields'] as $f) { if (!empty($f->id)) $out[$f->id] = (string)($f->label ?? ('Field '.$f->id)); }
		return $out;
	}
}
