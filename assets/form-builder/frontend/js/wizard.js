document.addEventListener("DOMContentLoaded", () => {
	const wizardForms = document.querySelectorAll(".cjw-wizard-form");

	function buildInputId(name) {
		return name.replace(/]\[/g, "_").replace(/[[\]]/g, "_");
	}

	function getFieldValue(form, fieldPath, relativeTo = null) {
		// If inside a repeater row, try finding the field in the same row first
		if (relativeTo) {
			const row = relativeTo.closest(".cjw-repeater-row");
			if (row) {
				const inputs = row.querySelectorAll("input, select, textarea");
				for (const input of inputs) {
					const name = input.getAttribute("name") || "";
					if (name.endsWith(`[${fieldPath}]`)) {
						if (input.type === "checkbox") {
							return input.checked ? input.value || "1" : "";
						}
						if (input.type === "radio") {
							if (input.checked) return input.value;
							continue;
						}
						return input.value;
					}
				}
			}
		}

		const directName = `cjw_field_${fieldPath}`;
		let fields = form.querySelectorAll(`[name="${CSS.escape(directName)}"]`);

		if (fields.length === 0) {
			fields = form.querySelectorAll(`[name="${CSS.escape(`${directName}[]`)}"]`);
		}

		if (fields.length === 0) {
			return "";
		}

		const firstField = fields[0];
		if (firstField.type === "radio") {
			const checkedField = Array.from(fields).find((field) => field.checked);
			return checkedField ? checkedField.value : "";
		}

		if (firstField.type === "checkbox") {
			if (fields.length > 1) {
				return Array.from(fields)
					.filter((field) => field.checked)
					.map((field) => field.value || "1");
			}

			return firstField.checked ? firstField.value || "1" : "";
		}

		return firstField.value ?? "";
	}

	function matchesCondition(actualValue, operator, expectedValue) {
		if (Array.isArray(actualValue)) {
			const normalizedExpected = String(expectedValue ?? "").trim().toLowerCase();
			const normalizedValues = actualValue.map((value) =>
				String(value ?? "").trim().toLowerCase(),
			);

			switch (operator) {
				case "not_equals":
					return !normalizedValues.includes(normalizedExpected);
				case "contains":
					return normalizedValues.includes(normalizedExpected);
				case "not_contains":
					return !normalizedValues.includes(normalizedExpected);
				case "empty":
					return normalizedValues.length === 0;
				case "not_empty":
					return normalizedValues.length > 0;
				default:
					return normalizedValues.includes(normalizedExpected);
			}
		}

		const normalizedActual = String(actualValue ?? "").trim().toLowerCase();
		const normalizedExpected = String(expectedValue ?? "").trim().toLowerCase();

		switch (operator) {
			case "not_equals":
				return normalizedActual !== normalizedExpected;
			case "contains":
				return normalizedActual.includes(normalizedExpected);
			case "not_contains":
				return !normalizedActual.includes(normalizedExpected);
			case "empty":
				return normalizedActual === "";
			case "not_empty":
				return normalizedActual !== "";
			default:
				return normalizedActual === normalizedExpected;
		}
	}

	function updateConditionalFields(form) {
		form.querySelectorAll("[data-condition-field]").forEach((element) => {
			const field = element.getAttribute("data-condition-field") || "";
			const operator = element.getAttribute("data-condition-operator") || "equals";
			const expectedValue = element.getAttribute("data-condition-value") || "";
			const isVisible = matchesCondition(
				getFieldValue(form, field, element),
				operator,
				expectedValue,
			);

			element.style.display = isVisible ? "" : "none";

			element
				.querySelectorAll("input, select, textarea")
				.forEach((input) => {
					if (input.type === "hidden") {
						return;
					}

					if (isVisible) {
						if (input.dataset.wasRequired === "1") {
							input.required = true;
						}
						return;
					}

					if (input.required) {
						input.dataset.wasRequired = "1";
					}
					input.required = false;
				});
		});
	}

	function reindexRepeater(container) {
		const itemsContainer = container.querySelector(".cjw-repeater-items");
		const rows = itemsContainer.querySelectorAll(".cjw-repeater-row");

		rows.forEach((row, index) => {
			row.querySelectorAll("input, select, textarea").forEach((input) => {
				const name = input.getAttribute("name");
				if (!name) {
					return;
				}

				const newName = name.replace(/\[\d+]/, `[${index}]`);
				input.setAttribute("name", newName);
				input.setAttribute("id", buildInputId(newName));
			});

			row.querySelectorAll(".cjw-field").forEach((field) => {
				const label = field.querySelector("label[for]");
				const input = field.querySelector("input, select, textarea");
				if (label && input?.id) {
					label.setAttribute("for", input.id);
				}
			});
		});
	}

	function wireRepeaterButtons(form) {
		form.querySelectorAll(".cjw-add-repeater-row").forEach((button) => {
			button.addEventListener("click", (event) => {
				event.preventDefault();
				const repeaterId = button.getAttribute("data-repeater");
				const container = form.querySelector(`#repeater-${CSS.escape(repeaterId)}`);

				if (!container) {
					return;
				}

				const itemsContainer = container.querySelector(".cjw-repeater-items");
				const maxEntries = parseInt(container.getAttribute("data-max-entries"), 10) || 0;
				const rows = itemsContainer.querySelectorAll(".cjw-repeater-row");

				if (maxEntries > 0 && rows.length >= maxEntries) {
					alert(`Maximaal ${maxEntries} toegestaan.`);
					button.style.display = "none";
					return;
				}

				const lastRow = rows[rows.length - 1];
				if (!lastRow) {
					return;
				}

				const newRow = lastRow.cloneNode(true);
				newRow.querySelectorAll("input, select, textarea").forEach((input) => {
					if (input.type === "checkbox" || input.type === "radio") {
						input.checked = false;
					} else {
						input.value = "";
					}
				});

				itemsContainer.appendChild(newRow);
				reindexRepeater(container);
				updateConditionalFields(form);

				if (
					maxEntries > 0 &&
					itemsContainer.querySelectorAll(".cjw-repeater-row").length >= maxEntries
				) {
					button.style.display = "none";
				}
			});
		});

		form.addEventListener("click", (event) => {
			const removeButton = event.target.closest(".cjw-remove-repeater-row");
			if (!removeButton) {
				return;
			}

			const row = removeButton.closest(".cjw-repeater-row");
			const container = row?.closest(".cjw-repeater-container");
			if (!row || !container) {
				return;
			}

			const itemsContainer = container.querySelector(".cjw-repeater-items");
			const rows = itemsContainer.querySelectorAll(".cjw-repeater-row");

			if (rows.length <= 1) {
				alert("Je moet minimaal één item invullen.");
				return;
			}

			row.remove();
			reindexRepeater(container);

			const addButton = container.querySelector(".cjw-add-repeater-row");
			const maxEntries = parseInt(container.getAttribute("data-max-entries"), 10) || 0;
			if (
				addButton &&
				(maxEntries === 0 ||
					itemsContainer.querySelectorAll(".cjw-repeater-row").length < maxEntries)
			) {
				addButton.style.display = "inline-block";
			}

			updateConditionalFields(form);
		});
	}

	wizardForms.forEach((form) => {
		form.querySelectorAll("input[required], select[required], textarea[required]").forEach(
			(input) => {
				input.dataset.wasRequired = "1";
			},
		);

		wireRepeaterButtons(form);

		form.addEventListener("change", () => {
			updateConditionalFields(form);
		});

		form.addEventListener("input", () => {
			updateConditionalFields(form);
		});

		updateConditionalFields(form);
	});
});
