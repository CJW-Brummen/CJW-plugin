(() => {
	window.CJWBuilder = window.CJWBuilder || {};
	const Builder = window.CJWBuilder;

	Builder.renderStepCard = function renderStepCard(step, stepIndex, context) {
		const stepTypeLabel =
			(step.type || "default") === "summary" ? "Samenvatting-stap" : "Stap";
		const element = document.createElement("div");
		element.className = `cjw-step-card ${step._collapsed ? "cjw-collapsed" : ""}`;
		element.dataset.uid = step._uid;

		element.innerHTML = `
			<div class="cjw-step-header">
				<div class="cjw-step-heading">
					<span class="dashicons dashicons-arrow-${step._collapsed ? "right" : "down"} cjw-toggle-collapse"></span>
					<span class="cjw-item-badge cjw-item-badge-step">${stepTypeLabel}</span>
					<label><strong>Stap titel:</strong></label>
					<input type="text" class="cjw-step-label" value="${Builder.escapeHtml(step.label || "")}">
					<label><strong>Type:</strong></label>
					<select class="cjw-step-type">
						<option value="default" ${(step.type || "default") === "default" ? "selected" : ""}>Standaard</option>
						<option value="summary" ${step.type === "summary" ? "selected" : ""}>Samenvatting</option>
					</select>
				</div>
				<div class="cjw-step-actions">
					<button type="button" class="button cjw-add-field">Voeg component toe</button>
					<span class="dashicons dashicons-no cjw-remove-step"></span>
				</div>
			</div>
			<div class="cjw-step-body" style="display:${step._collapsed ? "none" : "block"};">
				${Builder.renderConditionEditor(step.condition, {
					title: "Voorwaarde",
					referenceKey: step.key || "",
					referenceLabel: "Stapcode",
					explanation:
						"Toon deze stap alleen als een eerder veld aan deze regel voldoet.",
				})}
				<div class="cjw-step-field-list" data-parent-uid="${step._uid}"></div>
			</div>
		`;

		const body = element.querySelector(".cjw-step-body");
		const fieldList = element.querySelector(".cjw-step-field-list");

		element
			.querySelector(".cjw-step-header")
			.addEventListener("click", (event) => {
				if (event.target.closest("button, input, select, .cjw-remove-step")) {
					return;
				}
				step._collapsed = !step._collapsed;
				context.render();
			});

		element
			.querySelector(".cjw-step-label")
			.addEventListener("input", (event) => {
				step.label = event.target.value;
				step.key = Builder.slugify(step.label) || step.key;
				context.syncData();
			});

		element
			.querySelector(".cjw-step-type")
			.addEventListener("change", (event) => {
				step.type = event.target.value;
				context.syncData();
			});

		element.querySelector(".cjw-add-field").addEventListener("click", () => {
			step.fields = Array.isArray(step.fields) ? step.fields : [];
			step.fields.push(Builder.createDefaultField());
			context.render();
			context.syncData();
		});

		element.querySelector(".cjw-remove-step").addEventListener("click", () => {
			if (confirm("Weet je zeker dat je deze stap wilt verwijderen?")) {
				context.config.steps.splice(stepIndex, 1);
				context.render();
				context.syncData();
			}
		});

		Builder.wireConditionEditor(body, step, context.syncData);
		Builder.renderFields(step.fields || [], fieldList, context);
		Builder.wireDropZone(fieldList, () => step.fields || [], context);

		return element;
	};

	Builder.renderFields = function renderFields(fields, container, context) {
		if (!Array.isArray(fields)) {
			return;
		}

		fields.forEach((field, fieldIndex) => {
			container.appendChild(
				Builder.renderFieldCard(field, fields, fieldIndex, context),
			);
		});
	};

	Builder.renderFieldCard = function renderFieldCard(
		field,
		fields,
		fieldIndex,
		context,
	) {
		const showCaret = ["radio", "checkbox_group", "repeater"].includes(
			field.type,
		);
		const features =
			Builder.FIELD_FEATURES[field.type] || Builder.FIELD_FEATURES.text;
		const fieldTypeLabel = features.label || "Veld";
		const hasPersistentBodyContent = Boolean(
			features.options ||
				features.checkboxGroup ||
				features.validation ||
				features.repeater,
		);
		const conditionEnabled = Boolean(
			field.condition &&
				typeof field.condition === "object" &&
				Object.keys(field.condition).length > 0,
		);
		const bodyDisplay = showCaret
			? field._collapsed
				? "none"
				: "block"
			: hasPersistentBodyContent || conditionEnabled
				? "block"
				: "none";
		const element = document.createElement("div");
		element.className = `cjw-field-card cjw-type-${field.type} ${field._collapsed ? "cjw-collapsed" : ""}`;
		element.setAttribute("draggable", "true");
		element.dataset.uid = field._uid;

		element.innerHTML = `
			<div class="cjw-field-main">
				<div class="cjw-field-info">
					<span class="dashicons dashicons-move cjw-drag-handle"></span>
					${showCaret ? `<span class="dashicons dashicons-arrow-${field._collapsed ? "right" : "down"} cjw-toggle-collapse"></span>` : "<span></span>"}
					<span class="cjw-item-badge cjw-item-badge-field cjw-item-badge-${field.type}">${fieldTypeLabel}</span>
					<input type="text" class="cjw-field-label" value="${Builder.escapeHtml(field.label || "")}" placeholder="Label/Tekst">
					<select class="cjw-field-type">
						${Object.entries(Builder.FIELD_FEATURES)
							.map(
								([key, feat]) =>
									`<option value="${key}" ${field.type === key ? "selected" : ""}>${feat.label}</option>`,
							)
							.join("")}
					</select>
				</div>
				<div class="cjw-field-actions">
					${
						features.required
							? `<label class="cjw-inline-checkbox"><input type="checkbox" class="cjw-field-required" ${field.required ? "checked" : ""}> Verplicht</label>
							   <span class="cjw-field-divider"></span>`
							: ""
					}
					<label class="cjw-inline-checkbox cjw-inline-checkbox-compact"><input type="checkbox" class="cjw-field-condition-enabled" ${conditionEnabled ? "checked" : ""}> Voorwaarde</label>
					<span class="cjw-field-divider"></span>
					<span class="dashicons dashicons-no cjw-remove-field"></span>
				</div>
			</div>
			<div class="cjw-field-body" style="display:${bodyDisplay};">
				${field.type === "radio" ? Builder.renderRadioOptions(field) : ""}
				${field.type === "checkbox_group" ? Builder.renderCheckboxGroupOptions(field) : ""}
				${features.validation ? Builder.renderValidationEditor(field) : ""}
				${Builder.renderConditionEditor(field.condition, {
					title: "Voorwaarde",
					referenceKey: field.key || "",
					showToggle: false,
				})}
				${features.repeater ? Builder.renderRepeaterEditor(field) : ""}
			</div>
`;

		Builder.wireDragHandlers(element, field, context);

		if (showCaret) {
			element
				.querySelector(".cjw-field-main")
				.addEventListener("click", (event) => {
					if (
						event.target.closest(
							"button, input, select, .cjw-remove-field, .cjw-drag-handle",
						)
					) {
						return;
					}
					field._collapsed = !field._collapsed;
					context.render();
				});
		}

		element
			.querySelector(".cjw-field-label")
			.addEventListener("input", (event) => {
				field.label = event.target.value;
				field.key = Builder.slugify(field.label) || field.key;
				context.syncData();
			});

		element
			.querySelector(".cjw-field-type")
			.addEventListener("change", (event) => {
				field.type = event.target.value;
				field.condition = field.condition || {};

				if (field.type === "radio" && !Array.isArray(field.options)) {
					field.options = [
						Builder.createDefaultRadioOption(1),
						Builder.createDefaultRadioOption(2),
					];
				}

				if (field.type === "checkbox_group" && !Array.isArray(field.options)) {
					field.options = [
						Builder.createDefaultCheckboxGroupOption(1),
						Builder.createDefaultCheckboxGroupOption(2),
					];
				}

				if (field.type === "checkbox_group" && Array.isArray(field.options)) {
					field.options = field.options.map((option) => ({
						label: Builder.getRadioOptionLabel(option),
						value: Builder.getRadioOptionValue(option),
					}));
				}

				if (field.type === "repeater" && !Array.isArray(field.fields)) {
					field.fields = [];
				}

				context.render();
				context.syncData();
			});

		const requiredInput = element.querySelector(".cjw-field-required");
		if (requiredInput) {
			requiredInput.addEventListener("change", (event) => {
				field.required = event.target.checked;
				context.syncData();
			});
		}

		element.querySelector(".cjw-remove-field").addEventListener("click", () => {
			fields.splice(fieldIndex, 1);
			context.render();
			context.syncData();
		});

		Builder.wireConditionEditor(element, field, context.syncData);
		Builder.wireValidationEditor(element, field, context.syncData);

		if (field.type === "radio") {
			field.options = Array.isArray(field.options) ? field.options : [];

			element
				.querySelector(".cjw-add-radio-option")
				.addEventListener("click", () => {
					field.options.push(
						Builder.createDefaultRadioOption(field.options.length + 1),
					);
					context.render();
					context.syncData();
				});

			element
				.querySelectorAll(".cjw-radio-option-editor")
				.forEach((optionElement) => {
					const optionIndex = parseInt(optionElement.dataset.optionIndex, 10);
					const option = field.options[optionIndex];

					if (!option || typeof option !== "object") {
						field.options[optionIndex] = {
							label: Builder.getRadioOptionLabel(option),
							value: Builder.getRadioOptionValue(option),
							default: false,
							fields: [],
						};
					}

					const normalizedOption = field.options[optionIndex];

					optionElement
						.querySelector(".cjw-radio-option-label")
						.addEventListener("input", (event) => {
							normalizedOption.label = event.target.value;
							if (!normalizedOption.value) {
								normalizedOption.value = event.target.value;
							}
							context.syncData();
						});

					optionElement
						.querySelector(".cjw-radio-option-value")
						.addEventListener("input", (event) => {
							normalizedOption.value = event.target.value;
							context.syncData();
						});

					optionElement
						.querySelector(".cjw-radio-option-default")
						.addEventListener("change", (event) => {
							field.options.forEach((entry) => {
								if (entry && typeof entry === "object") {
									entry.default = false;
								}
							});
							normalizedOption.default = event.target.checked;
							context.render();
							context.syncData();
						});

					optionElement
						.querySelector(".cjw-remove-radio-option")
						.addEventListener("click", () => {
							field.options.splice(optionIndex, 1);
							context.render();
							context.syncData();
						});

					optionElement
						.querySelector(".cjw-add-radio-option-field")
						.addEventListener("click", () => {
							normalizedOption.fields = Array.isArray(normalizedOption.fields)
								? normalizedOption.fields
								: [];
							normalizedOption.fields.push({
								...Builder.createDefaultField("option"),
								type: "checkbox_group",
								required: false,
								options: [
									Builder.createDefaultCheckboxGroupOption(1),
									Builder.createDefaultCheckboxGroupOption(2),
								],
							});
							context.render();
							context.syncData();
						});

					const subfieldList = optionElement.querySelector(
						".cjw-radio-option-subfield-list",
					);
					if (subfieldList) {
						subfieldList.innerHTML = "";
						Builder.renderFields(
							normalizedOption.fields || [],
							subfieldList,
							context,
						);
						Builder.wireDropZone(
							subfieldList,
							() => normalizedOption.fields || [],
							context,
						);
					}
				});
		}

		if (field.type === "checkbox_group") {
			field.options = Array.isArray(field.options) ? field.options : [];

			element
				.querySelector(".cjw-add-checkbox-group-option")
				.addEventListener("click", () => {
					field.options.push(
						Builder.createDefaultCheckboxGroupOption(field.options.length + 1),
					);
					context.render();
					context.syncData();
				});

			element
				.querySelectorAll(".cjw-checkbox-group-option-editor")
				.forEach((optionElement) => {
					const optionIndex = parseInt(optionElement.dataset.optionIndex, 10);
					const option = field.options[optionIndex];

					if (!option || typeof option !== "object") {
						field.options[optionIndex] = {
							label: Builder.getRadioOptionLabel(option),
							value: Builder.getRadioOptionValue(option),
						};
					}

					const normalizedOption = field.options[optionIndex];

					optionElement
						.querySelector(".cjw-checkbox-group-option-label")
						.addEventListener("input", (event) => {
							normalizedOption.label = event.target.value;
							if (!normalizedOption.value) {
								normalizedOption.value = event.target.value;
							}
							context.syncData();
						});

					optionElement
						.querySelector(".cjw-checkbox-group-option-value")
						.addEventListener("input", (event) => {
							normalizedOption.value = event.target.value;
							context.syncData();
						});

					optionElement
						.querySelector(".cjw-remove-checkbox-group-option")
						.addEventListener("click", () => {
							field.options.splice(optionIndex, 1);
							context.render();
							context.syncData();
						});
				});
		}

		if (field.type === "repeater") {
			const subfieldList = element.querySelector(".cjw-subfield-list");
			Builder.renderFields(field.fields || [], subfieldList, context);
			Builder.wireDropZone(subfieldList, () => field.fields || [], context);

			element
				.querySelector(".cjw-add-subfield")
				.addEventListener("click", () => {
					field.fields = Array.isArray(field.fields) ? field.fields : [];
					field.fields.push(Builder.createDefaultField("sub"));
					context.render();
					context.syncData();
				});

			element
				.querySelector(".cjw-repeater-max")
				.addEventListener("input", (event) => {
					field.max_entries = parseInt(event.target.value, 10) || 0;
					context.syncData();
				});
		}

		return element;
	};
})();
