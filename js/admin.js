( function () {
	'use strict';

	function toggleCancellationReason( select ) {
		var form = select.closest( 'form' );
		var row = form ? form.querySelector( '[data-pm-cancellation-row]' ) : null;

		if ( ! row ) {
			return;
		}

		row.classList.toggle( 'pm-is-hidden', select.value !== 'Cancel' );
	}

	function searchParticipants( input ) {
		var table = document.querySelector( '[data-pm-participants-table]' );
		var query = input.value.toLowerCase();

		if ( ! table ) {
			return;
		}

		table.querySelectorAll( 'tbody tr' ).forEach( function ( row ) {
			row.hidden = query && row.textContent.toLowerCase().indexOf( query ) === -1;
		} );
	}

	document.addEventListener( 'change', function ( event ) {
		if ( event.target.matches( '[data-pm-status-control]' ) ) {
			toggleCancellationReason( event.target );
		}
	} );

	document.addEventListener( 'input', function ( event ) {
		if ( event.target.matches( '[data-pm-search-input]' ) ) {
			searchParticipants( event.target );
		}
	} );

	document.addEventListener( 'submit', function ( event ) {
		if ( event.target.matches( '[data-pm-search-form]' ) ) {
			event.preventDefault();
			searchParticipants( event.target.querySelector( '[data-pm-search-input]' ) );
		}
	} );

	document.addEventListener( 'click', function ( event ) {
		var link = event.target.closest( '[data-pm-confirm]' );

		if ( link && ! window.confirm( link.getAttribute( 'data-pm-confirm' ) ) ) {
			event.preventDefault();
		}
	} );

	document.querySelectorAll( '[data-pm-status-control]' ).forEach( toggleCancellationReason );
}() );
