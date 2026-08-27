(() => {
	window.CJWBuilder = window.CJWBuilder || {};
	const Builder = window.CJWBuilder;

	Builder.CONDITION_OPERATORS = [
		{ value: "equals", label: "is gelijk aan" },
		{ value: "not_equals", label: "is niet gelijk aan" },
		{ value: "contains", label: "bevat" },
		{ value: "not_contains", label: "bevat niet" },
		{ value: "empty", label: "is leeg" },
		{ value: "not_empty", label: "is niet leeg" },
	];

	/*
	 * What a field means, as opposed to what it looks like. Optional: a form
	 * that marks nothing still works, it just cannot fill the list columns or
	 * count places that depend on knowing which answer is which.
	 */
	Builder.FIELD_ROLES = [
		{ value: "", label: "— geen —" },
		{ value: "child_name", label: "Voornaam van het kind" },
		{ value: "child_infix", label: "Tussenvoegsel van het kind" },
		{ value: "child_last_name", label: "Achternaam van het kind" },
		{ value: "birth_date", label: "Geboortedatum" },
		{ value: "camp_type", label: "Type kamp" },
		{ value: "photo_consent", label: "Toestemming foto's" },
	];

	Builder.FIELD_FEATURES = {
		text: { label: "Tekstveld", required: true, validation: true },
		email: { label: "E-mail", required: true, validation: true },
		date: { label: "Datum", required: true, validation: true },
		checkbox: { label: "Checkbox", required: true, validation: false },
		checkbox_group: {
			label: "Checkboxgroep",
			required: true,
			validation: false,
			checkboxGroup: true,
		},
		radio: {
			label: "Radio buttons",
			required: true,
			validation: false,
			options: true,
		},
		textarea: { label: "Tekstvak", required: true, validation: true },
		heading: { label: "Koptekst", required: false, validation: false, role: false },
		html: { label: "HTML-uitleg", required: false, validation: false, role: false },
		repeater: {
			label: "Herhaler",
			required: false,
			validation: false,
			repeater: true,
			role: false,
		},
	};

	Builder.generateId = function generateId() {
		return Math.random().toString(36).substring(2, 9);
	};

	Builder.escapeHtml = function escapeHtml(value) {
		return String(value ?? "")
			.replaceAll("&", "&amp;")
			.replaceAll("<", "&lt;")
			.replaceAll(">", "&gt;")
			.replaceAll('"', "&quot;");
	};

	Builder.slugify = function slugify(text) {
		return text
			.toString()
			.toLowerCase()
			.trim()
			.replace(/\s+/g, "_")
			.replace(/[^\w-]+/g, "")
			.replace(/--+/g, "_");
	};

	Builder.getRadioOptionLabel = function getRadioOptionLabel(option) {
		if (option && typeof option === "object") {
			return String(option.label || option.value || "");
		}

		return String(option || "");
	};

	Builder.getRadioOptionValue = function getRadioOptionValue(option) {
		if (option && typeof option === "object") {
			return String(option.value || option.label || "");
		}

		return String(option || "");
	};

	Builder.createDefaultRadioOption = function createDefaultRadioOption(
		index = 1,
	) {
		const label = `Optie ${index}`;

		return {
			label,
			value: label,
			default: false,
			fields: [],
		};
	};

	Builder.createDefaultCheckboxGroupOption =
		function createDefaultCheckboxGroupOption(index = 1) {
			const label = `Optie ${index}`;

			return {
				label,
				value: label,
			};
		};
})();
