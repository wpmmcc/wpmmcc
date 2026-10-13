(function () {
	function fallbackCopy(text) {
		var area = document.createElement('textarea');
		area.value = text;
		area.setAttribute('readonly', '');
		area.style.position = 'fixed';
		area.style.top = '0';
		area.style.left = '-9999px';
		document.body.appendChild(area);
		area.focus();
		area.select();
		var copied = false;
		try {
			copied = document.execCommand('copy');
		} catch (error) {
			copied = false;
		}
		document.body.removeChild(area);
		return copied;
	}

	function copyText(text) {
		if (window.isSecureContext && navigator.clipboard && navigator.clipboard.writeText) {
			return navigator.clipboard.writeText(text).catch(function () {
				if (!fallbackCopy(text)) {
					return Promise.reject(new Error('copy failed'));
				}
			});
		}
		if (!fallbackCopy(text)) {
			return Promise.reject(new Error('copy failed'));
		}
		return Promise.resolve();
	}

	document.addEventListener('click', function (event) {
		var target = event.target;
		if (!target || !target.closest) {
			return;
		}
		var strings = window.wpmmccAdminCopy || {};
		var uuidButton = target.closest('[data-wpmmcc-copy]');
		if (uuidButton) {
			var value = uuidButton.getAttribute('data-wpmmcc-copy') || '';
			copyText(value).then(function () {
				uuidButton.textContent = strings.copied || uuidButton.textContent;
				window.setTimeout(function () {
					uuidButton.textContent = strings.copy || uuidButton.textContent;
				}, 1200);
			});
			return;
		}
		var packButton = target.closest('[data-wpmmcc-copy-pack]');
		if (!packButton) {
			return;
		}
		var pack = document.getElementById('wpmmcc-conn-pack-textarea');
		copyText(pack ? pack.value : '').then(function () {
			if (strings.packCopied) {
				window.alert(strings.packCopied);
			}
		});
	});
})();
