function insertTags( tagOpen, tagClose, sampleText ) {
	$currentFocused = $( '#comment' );
	if ( $currentFocused && $currentFocused.length ) {
		$currentFocused.textSelection(
			'encapsulateSelection', {
				pre: tagOpen,
				peri: sampleText,
				post: tagClose
			}
		);
	}
}