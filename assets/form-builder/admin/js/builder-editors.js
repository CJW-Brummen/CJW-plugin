(() => {
	window.CJWBuilder = window.CJWBuilder || {};
	const Builder = window.CJWBuilder;

	Builder.renderConditionEditor = function renderConditionEditor(
		condition = {},
		{
			title = "Voorwaarde",
			referenceKey = "",
			referenceLabel = "Veldsleutel",
			explanation = "",
			showToggle = true,
		} = {},
	) {
		const field = Builder.escapeHtml(condition.field || "");
		const value = Builder.escapeHtml(condition.value || "");
		const operator = condition.operator || "equals";
		const enabled = Boolean(
			condition &&
				typeof condition === "object" &&
				Object.keys(condition).length > 0,
		);
		const referenceKeyMarkup = referenceKey
			? `
				<div class="cjw-condition-reference">
					<span class="cjw-condition-reference-label">${referenceLabel}</span>
					<code>${Builder.escapeHtml(referenceKey)}</code>
				</div>
			`
			: "";
		const explanationMarkup = explanation
			? `<p class="cjw-help-text">${Builder.escapeHtml(explanation)}</p>`
			: "";
		const toggleMarkup = showToggle
			? `
				<label class="cjw-inline-checkbox">
					<input type="checkbox" class="cjw-condition-enabled" ${enabled ? "checked" : ""}>
					Activeer voorwaardelijke weergave
				</label>
			`
			: "";
		const options = Builder.CONDITION_OPERATORS.map(
			(entry) =>
				`<option value="${entry.value}" ${entry.value === operator ? "selected" : ""}>${entry.label}</option>`,
		).join("");

		const gridClass = enabled ? "" : "cjw-hidden";

		return `
			<div class="cjw-panel cjw-panel-condition">
				<div class="cjw-panel-heading">
					<h4>${title}</h4>
					${referenceKeyMarkup}
				</div>
				<div class="cjw-condition-toggle-wrapper">
					${explanationMarkup}
					${toggleMarkup}
				</div>
				<div class="cjw-condition-fields cjw-condition-grid ${gridClass}">
					<label>
						<span>Stuur op veld</span>
						<input type="text" class="cjw-condition-field" value="${field}" placeholder="bijv. group, email of kids.0.name">
					</label>
					<label>
						<span>Operator</span>
						<select class="cjw-condition-operator">${options}</select>
					</label>
					<label>
						<span>Vergelijkingswaarde</span>
						<input type="text" class="cjw-condition-value" value="${value}" placeholder="Leeg laten voor leeg/niet leeg">
					</label>
				</div>
			</div>
		`;
	};

	Builder.renderValidationEditor = function renderValidationEditor(field) {
		const type = field.type || "text";

		if (type === "date") {
			return `
				<div class="cjw-panel">
					<h4>Datumvalidatie</h4>
					<div class="cjw-condition-grid">
						<label>
							<span>Minimale datum</span>
							<input type="date" class="cjw-min-date" value="${Builder.escapeHtml(field.min_date || "")}">
						</label>
						<label>
							<span>Maximale datum</span>
							<input type="date" class="cjw-max-date" value="${Builder.escapeHtml(field.max_date || "")}">
						</label>
						<label>
							<span>Niet voor veld</span>
							<input type="text" class="cjw-date-after-field" value="${Builder.escapeHtml(field.date_after_field || "")}" placeholder="start_date">
						</label>
						<label>
							<span>Niet na veld</span>
							<input type="text" class="cjw-date-before-field" value="${Builder.escapeHtml(field.date_before_field || "")}" placeholder="end_date">
						</label>
					</div>
				</div>
			`;
		}

		if (["text", "textarea", "email"].includes(type)) {
			return `
				<div class="cjw-panel">
					<h4>Lengtevalidatie</h4>
					<div class="cjw-condition-grid">
						<label>
							<span>Min. tekens</span>
							<input type="number" class="cjw-min-length" value="${Builder.escapeHtml(field.min_length || "")}" min="0">
						</label>
						<label>
							<span>Max. tekens</span>
							<input type="number" class="cjw-max-length" value="${Builder.escapeHtml(field.max_length || "")}" min="0">
						</label>
					</div>
				</div>
			`;
		}

		return "";
	};

	Builder.renderRadioOptions = function renderRadioOptions(field) {
		const options = Array.isArray(field.options) ? field.options : [];
		const optionRows = options
			.map((option, index) => {
				const label = Builder.getRadioOptionLabel(option);
				const value = Builder.getRadioOptionValue(option);
				const isDefault = Boolean(option && typeof option === "object" && option.default);
				const fields = option && typeof option === "object" ? option.fields || [] : [];
				const optionUid =
					option && typeof option === "object" && option._uid
						? option._uid
						: `radio_option_${index}`;

				return `
					<div class="cjw-radio-option-editor" data-option-index="${index}">
						<div class="cjw-radio-option-main">
							<label>
								<span>Label</span>
								<input type="text" class="cjw-radio-option-label" value="${Builder.escapeHtml(label)}">
							</label>
							<label>
								<span>Waarde</span>
								<input type="text" class="cjw-radio-option-value" value="${Builder.escapeHtml(value)}">
							</label>
							<label class="cjw-inline-checkbox">
								<input type="checkbox" class="cjw-radio-option-default" ${isDefault ? "checked" : ""}>
								Standaard
							</label>
							<button type="button" class="button cjw-add-radio-option-field">Voeg subveld toe</button>
							<span class="dashicons dashicons-no cjw-remove-radio-option"></span>
						</div>
						<div class="cjw-radio-option-subfields">
							<div class="cjw-radio-option-subfield-list" data-parent-uid="${optionUid}">
								${fields.length === 0 ? '<p class="cjw-help-text">Geen subvelden.</p>' : ""}
							</div>
						</div>
					</div>
				`;
			})
			.join("");

		return `
			<div class="cjw-panel">
				<h4>Opties</h4>
				<div class="cjw-radio-options-list">${optionRows}</div>
				<button type="button" class="button cjw-add-radio-option">Voeg optie toe</button>
			</div>
		`;
	};

	Builder.renderCheckboxGroupOptions = function renderCheckboxGroupOptions(field) {
		const options = Array.isArray(field.options) ? field.options : [];
		const optionRows = options
			.map((option, index) => {
				const label = Builder.getRadioOptionLabel(option);
				const value = Builder.getRadioOptionValue(option);

				return `
					<div class="cjw-checkbox-group-option-editor" data-option-index="${index}">
						<label>
							<span>Label</span>
							<input type="text" class="cjw-checkbox-group-option-label" value="${Builder.escapeHtml(label)}">
						</label>
						<label>
							<span>Waarde</span>
							<input type="text" class="cjw-checkbox-group-option-value" value="${Builder.escapeHtml(value)}">
						</label>
						<span class="dashicons dashicons-no cjw-remove-checkbox-group-option"></span>
					</div>
				`;
			})
			.join("");

		return `
			<div class="cjw-panel">
				<h4>Checkboxes</h4>
				<p class="cjw-help-text">Als Verplicht aan staat, moet minimaal één checkbox gekozen worden.</p>
				<div class="cjw-checkbox-group-options-list">${optionRows}</div>
				<button type="button" class="button cjw-add-checkbox-group-option">Voeg checkbox toe</button>
			</div>
		`;
	};

	Builder.renderRepeaterEditor = function renderRepeaterEditor(field) {
		return `
			<div class="cjw-repeater-box">
				<div class="cjw-panel">
					<h4>Herhalerinstellingen</h4>
					<label>
						<span>Max aantal herhalingen</span>
						<input type="number" class="cjw-repeater-max" value="${Builder.escapeHtml(field.max_entries || 0)}" min="0">
					</label>
					<small>0 = onbeperkt</small>
				</div>
				<div class="cjw-repeater-header">
					<strong>Subvelden</strong>
					<button type="button" class="button cjw-add-subfield">Voeg subveld toe</button>
				</div>
				<div class="cjw-subfield-list" data-parent-uid="${field._uid}"></div>
			</div>
		`;
	};

	Builder.wireConditionEditor = function wireConditionEditor(
		scope,
		target,
		syncData,
	) {
		if (!scope) {
			return;
		}

		const panel = scope.querySelector(".cjw-panel-condition");
		if (!panel) {
			return;
		}

		const toggle =
			scope.querySelector(".cjw-field-condition-enabled") ||
			panel.querySelector(".cjw-condition-enabled");
		const fieldInput = panel.querySelector(".cjw-condition-field");
		const operatorInput = panel.querySelector(".cjw-condition-operator");
		const valueInput = panel.querySelector(".cjw-condition-value");
		const grid = panel.querySelector(".cjw-condition-fields");

		if (!toggle || !fieldInput || !operatorInput || !valueInput || !grid) {
			return;
		}

		const fieldBody = panel.closest(".cjw-field-body");
		const fieldCard = panel.closest(".cjw-field-card");
		const hasPersistentFieldBodyContent = () =>
			Boolean(
				fieldBody?.querySelector(
					".cjw-panel:not(.cjw-panel-condition), .cjw-repeater-box",
				),
			);
		const setFieldBodyVisible = (visible) => {
			if (!fieldBody) {
				return;
			}

			fieldBody.style.display = visible ? "block" : "none";

			if (visible) {
				target._collapsed = false;
				fieldCard?.classList.remove("cjw-collapsed");
				const caret = fieldCard?.querySelector(
					":scope > .cjw-field-main .cjw-toggle-collapse",
				);

				if (caret) {
					caret.classList.remove("dashicons-arrow-right");
					caret.classList.add("dashicons-arrow-down");
				}
			}
		};

		const syncCondition = () => {
			if (!toggle.checked) {
				target.condition = {};
				grid.classList.add("cjw-hidden");
				if (!hasPersistentFieldBodyContent()) {
					setFieldBodyVisible(false);
				}
				syncData();
				return;
			}

			target.condition = {
				field: fieldInput.value.trim(),
				operator: operatorInput.value,
				value: valueInput.value,
			};

			setFieldBodyVisible(true);
			grid.classList.remove("cjw-hidden");
			syncData();
		};

		toggle.addEventListener("change", syncCondition);
		fieldInput.addEventListener("input", syncCondition);
		operatorInput.addEventListener("change", syncCondition);
		valueInput.addEventListener("input", syncCondition);
		syncCondition();
	};

	Builder.wireValidationEditor = function wireValidationEditor(
		scope,
		field,
		syncData,
	) {
		if (!scope) {
			return;
		}

		scope
			.querySelector(".cjw-min-length")
			?.addEventListener("input", (event) => {
				field.min_length = parseInt(event.target.value, 10) || 0;
				syncData();
			});

		scope
			.querySelector(".cjw-max-length")
			?.addEventListener("input", (event) => {
				field.max_length = parseInt(event.target.value, 10) || 0;
				syncData();
			});

		scope.querySelector(".cjw-min-date")?.addEventListener("input", (event) => {
			field.min_date = event.target.value;
			syncData();
		});

		scope.querySelector(".cjw-max-date")?.addEventListener("input", (event) => {
			field.max_date = event.target.value;
			syncData();
		});

		scope
			.querySelector(".cjw-date-after-field")
			?.addEventListener("input", (event) => {
				field.date_after_field = event.target.value.trim();
				syncData();
			});

		scope
			.querySelector(".cjw-date-before-field")
			?.addEventListener("input", (event) => {
				field.date_before_field = event.target.value.trim();
				syncData();
			});
	};
})();
