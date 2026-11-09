// Site-icon/logo picker modal: browse files/, upload, or remove. Reads its
// own adjacent <script type="application/json"> data island. Thin wiring
// over the shared window.SynaptikImagePicker (core admin/assets/js/common.js,
// loaded on every admin page including this plugin's own) — no local
// folder-browsing/modal logic needed here.
(function () {
	var dataEl = document.getElementById('mt-logo-picker-data');
	if (!dataEl) return;
	var data;
	try { data = JSON.parse(dataEl.textContent); } catch (e) { return; }
	var i18n = data.i18n || {};

	var wrapper = document.querySelector('.mt-sip-wrapper');
	if (!wrapper) return;

	var picker = window.SynaptikImagePicker.create({
		title:       data.title,
		getFilesUrl: data.getFilesUrl,
		baseUrl:     data.siteBaseUrl,
		i18n: { home: i18n.home, loading: i18n.loading, noImages: i18n.noImages, loadError: i18n.loadError }
	});

	window.SynaptikImagePicker.bindField(picker, {
		wrapper:     wrapper,
		fileInput:   wrapper.querySelector('.mt-sip-upload-input'),
		browseBtn:   wrapper.querySelector('.mt-sip-browse-btn'),
		removeBtn:   wrapper.querySelector('.mt-sip-remove-btn'),
		pathInput:   document.getElementById('mt-sip-path'),
		removeInput: document.getElementById('mt-sip-remove'),
		previewImg:  wrapper.querySelector('.mt-sip-preview-img'),
		previewEl:   wrapper.querySelector('.mt-sip-preview')
	});
})();
