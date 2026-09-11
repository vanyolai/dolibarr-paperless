(function () {
	'use strict';

	function initPaperlessInvoiceTagCheckboxes() {
		var select = document.querySelector('select[name="tag_ids[]"][multiple]');
		if (!select || select.dataset.paperlessCheckboxesReady === '1') {
			return;
		}

		select.dataset.paperlessCheckboxesReady = '1';

		var wrapper = document.createElement('div');
		wrapper.className = 'paperless-tag-checkboxes';
		wrapper.style.maxHeight = '12em';
		wrapper.style.overflowY = 'auto';
		wrapper.style.display = 'inline-block';
		wrapper.style.minWidth = '20em';
		wrapper.style.maxWidth = '100%';
		wrapper.style.verticalAlign = 'top';
		wrapper.style.paddingRight = '1em';

		Array.prototype.forEach.call(select.options, function (option) {
			var label = document.createElement('label');
			label.style.display = 'block';
			label.style.margin = '0.15em 0';
			label.style.whiteSpace = 'nowrap';

			var checkbox = document.createElement('input');
			checkbox.type = 'checkbox';
			checkbox.checked = option.selected;
			checkbox.value = option.value;
			checkbox.style.marginRight = '0.45em';
			checkbox.addEventListener('change', function () {
				option.selected = checkbox.checked;
			});

			label.appendChild(checkbox);
			label.appendChild(document.createTextNode(option.text));
			wrapper.appendChild(label);
		});

		select.parentNode.insertBefore(wrapper, select);
		select.style.display = 'none';

		if (select.form) {
			select.form.addEventListener('submit', function () {
				var checkboxes = wrapper.querySelectorAll('input[type="checkbox"]');
				Array.prototype.forEach.call(checkboxes, function (checkbox, index) {
					if (select.options[index]) {
						select.options[index].selected = checkbox.checked;
					}
				});
			});
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initPaperlessInvoiceTagCheckboxes);
	} else {
		initPaperlessInvoiceTagCheckboxes();
	}
})();
