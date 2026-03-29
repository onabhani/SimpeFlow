/**
 * Code Validation - Real-time AJAX Validator
 * Checks if entered value exists in source form entries on field change.
 */
( function( $ ) {

	window.SFACodeValidator = function( args ) {

		var self = this;

		for ( var prop in args ) {
			if ( args.hasOwnProperty( prop ) ) {
				self[ prop ] = args[ prop ];
			}
		}

		self._debounceTimer = null;

		self.init = function() {
			self.$elems().on( 'change', function() {
				const $el = $( this );
				clearTimeout( self._debounceTimer );
				self._debounceTimer = setTimeout( function() {
					self.doesValueExist( $el );
				}, 300 );
			} );
		};

		self.$elems = function() {
			return $( self.selectors.join( ', ' ) );
		};

		self.getAllValues = function() {
			var values = {};
			// Invert fieldMap (source => target) to (target => source)
			// so we send source field IDs as keys, matching what the server expects.
			const targetToSource = {};
			for ( const sourceId in self.fieldMap ) {
				if ( self.fieldMap.hasOwnProperty( sourceId ) ) {
					targetToSource[ String( self.fieldMap[ sourceId ] ) ] = sourceId;
				}
			}
			self.$elems().each( function() {
				const inputId   = gf_get_input_id_by_html_id( $( this ).attr( 'id' ) );
				const sourceKey = targetToSource[ String( inputId ) ] || inputId;
				values[ sourceKey ] = $( this ).val();
			} );
			return values;
		};

		self.doAllFieldsHaveValue = function() {
			var values = Object.values( self.getAllValues() );
			return values.length === values.filter( Boolean ).length;
		};

		self.doesValueExist = function( $elem ) {
			if ( ! self.doAllFieldsHaveValue() ) {
				self.removeIndicators();
				return;
			}

			self.removeIndicators();

			var spinner  = new self.spinner( $elem, false, 'position:relative;top:2px;left:-25px;' ),
				$buttons = $( '#gform_' + self.targetFormId + ' .gform_button' );

			$buttons.prop( 'disabled', true );
			self.$elems().prop( 'disabled', true );

			$.post( self.ajaxUrl, {
				nonce:   self.nonce,
				action:  'sfa_cv_check',
				values:  self.getAllValues(),
				form_id: self.sourceFormId
			}, function( response ) {

				self.$elems().prop( 'disabled', false );
				$buttons.prop( 'disabled', false );
				spinner.destroy();

				if ( ! response ) {
					return;
				}

				if ( response.doesValueExist ) {
					self.addIndicators( 'sfa-cv-success', '&#10004;' );
				} else {
					self.addIndicators( 'sfa-cv-error', '&#10008;' );
				}

				gform.doAction( 'sfa_cv_post_validation', self, response );

			} );
		};

		self.getIndicatorId = function( inputId ) {
			return 'sfa_cv_{0}_{1}'.gformFormat( self.targetFormId, inputId );
		};

		self.getIndicatorTemplate = function() {
			return '<span id="{0}" class="sfa-cv-indicator {1}">{2}</span>';
		};

		self.removeIndicators = function() {
			self.$elems().each( function() {
				var inputId = gf_get_input_id_by_html_id( $( this ).attr( 'id' ) );
				$( '#' + self.getIndicatorId( inputId ) ).remove();
			} );
		};

		self.addIndicators = function( cssClass, icon ) {
			self.$elems().each( function() {
				var inputId = gf_get_input_id_by_html_id( $( this ).attr( 'id' ) );
				$( this ).after(
					self.getIndicatorTemplate().gformFormat( self.getIndicatorId( inputId ), cssClass, icon )
				);
			} );
		};

		self.spinner = function( elem, imageSrc, inlineStyles ) {
			imageSrc     = imageSrc || window.gf_global.spinnerUrl;
			inlineStyles = inlineStyles || '';

			this.elem  = elem;
			this.image = '<img class="gfspinner" src="' + imageSrc + '" style="' + inlineStyles + '" />';

			this.init = function() {
				this.spinner = $( this.image );
				$( this.elem ).after( this.spinner );
				return this;
			};

			this.destroy = function() {
				$( this.spinner ).remove();
			};

			return this.init();
		};

		self.init();
	};

} )( jQuery );
