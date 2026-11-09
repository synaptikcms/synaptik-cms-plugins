// Inserts the clicked token at the cursor position in the WYSIWYG editor's
// underlying #content textarea (editor.js keeps it in sync with the
// visible rich-text area) — same approach as the Booking plugin's ICS
// placeholder bar (admin/templates/settings.php).
(function () {
	var tokenBar = document.getElementById('nl-digest-token-bar');
	var field = document.getElementById('content');
	if (!tokenBar || !field) return;

	tokenBar.addEventListener('click', function (e) {
		var btn = e.target.closest('.nl-token-btn');
		if (!btn) return;

		var token = btn.getAttribute('data-token');
		var start = field.selectionStart != null ? field.selectionStart : field.value.length;
		var end   = field.selectionEnd != null ? field.selectionEnd : field.value.length;

		field.value = field.value.slice(0, start) + token + field.value.slice(end);
		field.focus();
		var newPos = start + token.length;
		field.setSelectionRange(newPos, newPos);

		// Nudge the WYSIWYG editor to notice the underlying textarea
		// changed, in case it caches its own copy of the content.
		field.dispatchEvent(new Event('input', { bubbles: true }));
	});
})();
