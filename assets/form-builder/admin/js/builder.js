document.addEventListener("DOMContentLoaded", () => {
	const Builder = window.CJWBuilder || {};
	const workspace = document.getElementById("cjw-builder-workspace");
	const addStepBtn = document.getElementById("cjw-add-step");
	const dataOutput = document.getElementById("cjw_wizard_config_data");
	const copyShortcodeBtn = document.getElementById("cjw-copy-shortcode");

	if (!workspace || !addStepBtn || !dataOutput) {
		return;
	}

	if (copyShortcodeBtn) {
		copyShortcodeBtn.addEventListener("click", () => {
			const shortcodeText = document.getElementById("cjw-shortcode-text");
			if (!shortcodeText) {
				return;
			}

			navigator.clipboard.writeText(shortcodeText.innerText).then(() => {
				const originalText = copyShortcodeBtn.innerText;
				copyShortcodeBtn.innerText = "Gekopieerd!";
				setTimeout(() => {
					copyShortcodeBtn.innerText = originalText;
				}, 2000);
			});
		});
	}

	const config = Builder.loadConfig(dataOutput.value);
	const context = {
		config,
		syncData() {
			Builder.syncData(config, dataOutput);
		},
		render() {
			Builder.ensureUids(config);
			workspace.innerHTML = "";

			config.steps.forEach((step, stepIndex) => {
				workspace.appendChild(Builder.renderStepCard(step, stepIndex, context));
			});

			context.syncData();
		},
	};

	addStepBtn.addEventListener("click", () => {
		config.steps.push({
			key: `step_${Builder.generateId()}`,
			label: "",
			type: "default",
			fields: [],
			condition: {},
			_collapsed: true,
		});
		context.render();
	});

	Builder.ensureUids(config);
	context.render();
});
