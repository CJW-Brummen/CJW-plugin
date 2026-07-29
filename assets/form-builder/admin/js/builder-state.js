(() => {
	window.CJWBuilder = window.CJWBuilder || {};
	const Builder = window.CJWBuilder;

	Builder.createDefaultField = function createDefaultField(prefix = "field") {
		return {
			key: `${prefix}_${Builder.generateId()}`,
			label: "",
			type: "text",
			required: true,
			condition: {},
			_collapsed: true,
		};
	};

	Builder.loadConfig = function loadConfig(raw) {
		const normalizeField = (field) => {
			if (typeof field === "string") {
				return {
					key: Builder.slugify(field) || `field_${Builder.generateId()}`,
					label: field,
					type: "text",
					required: true,
					condition: {},
				};
			}

			if (!field || typeof field !== "object") {
				return Builder.createDefaultField();
			}

			const normalized = {
				...field,
				type: field.type || "text",
				fields: Array.isArray(field.fields)
					? field.fields.map(normalizeField)
					: field.fields,
				condition: field.condition || {},
				_collapsed: true,
			};

			if (normalized.type === "radio" && Array.isArray(field.options)) {
				normalized.options = field.options.map((option) => {
					if (!option || typeof option !== "object") {
						return option;
					}

					return {
						...option,
						label: Builder.getRadioOptionLabel(option),
						value: Builder.getRadioOptionValue(option),
						default: Boolean(option.default),
						fields: Array.isArray(option.fields)
							? option.fields.map(normalizeField)
							: [],
						_uid: option._uid || `uid_${Builder.generateId()}`,
					};
				});
			}

			if (
				normalized.type === "checkbox_group" &&
				Array.isArray(field.options)
			) {
				normalized.options = field.options.map((option) => {
					if (!option || typeof option !== "object") {
						return option;
					}

					return {
						...option,
						label: Builder.getRadioOptionLabel(option),
						value: Builder.getRadioOptionValue(option),
					};
				});
			}

			return normalized;
		};

		try {
			const parsed = JSON.parse(raw);
			if (parsed && typeof parsed === "object") {
				if (Array.isArray(parsed.steps)) {
					return {
						...parsed,
						steps: parsed.steps.map((step) => ({
							...step,
							type: step.type || "default",
							fields: Array.isArray(step.fields)
								? step.fields.map(normalizeField)
								: [],
							condition: step.condition || {},
							_collapsed: true,
						})),
					};
				}

				if (parsed.steps && typeof parsed.steps === "object") {
					return {
						...parsed,
						steps: Object.entries(parsed.steps).map(([key, step]) => ({
							key,
							label: step.label || key,
							type: step.type || "default",
							fields: Array.isArray(step.fields)
								? step.fields.map(normalizeField)
								: [],
							condition: step.condition || {},
							_collapsed: true,
						})),
					};
				}
			}
		} catch {
			return { steps: [] };
		}

		return { steps: [] };
	};

	Builder.ensureUids = function ensureUids(target) {
		if (Array.isArray(target)) {
			target.forEach((item) => {
				Builder.ensureUids(item);
			});
			return;
		}

		if (target && typeof target === "object") {
			if (!target._uid && (target.key || target.type)) {
				target._uid = `uid_${Builder.generateId()}`;
			}

			if (target.fields) {
				Builder.ensureUids(target.fields);
			}

			if (Array.isArray(target.options)) {
				target.options.forEach((option) => {
					if (!option || typeof option !== "object") {
						return;
					}

					if (!option._uid) {
						option._uid = `uid_${Builder.generateId()}`;
					}

					if (option.fields) {
						Builder.ensureUids(option.fields);
					}
				});
			}

			if (target.steps) {
				Builder.ensureUids(target.steps);
			}
		}
	};

	Builder.syncData = function syncData(config, dataOutput) {
		const cleanConfig = JSON.parse(JSON.stringify(config));

		const stripTransientState = (item) => {
			if (Array.isArray(item)) {
				item.forEach(stripTransientState);
				return;
			}

			if (item && typeof item === "object") {
				delete item._uid;
				delete item._collapsed;

				if (item.condition && !item.condition.field) {
					delete item.condition;
				}

				if (item.fields) {
					stripTransientState(item.fields);
				}

				if (Array.isArray(item.options)) {
					item.options.forEach((option) => {
						if (!option || typeof option !== "object") {
							return;
						}

						delete option._uid;

						if (option.fields) {
							stripTransientState(option.fields);
						}
					});
				}

				if (item.steps) {
					stripTransientState(item.steps);
				}
			}
		};

		stripTransientState(cleanConfig);
		dataOutput.value = JSON.stringify(cleanConfig);
	};

	Builder.findAndRemove = function findAndRemove(target, uid) {
		if (!target || !uid) {
			return null;
		}

		if (Array.isArray(target)) {
			for (let index = 0; index < target.length; index += 1) {
				if (target[index]._uid === uid) {
					return target.splice(index, 1)[0];
				}

				const found = Builder.findAndRemove(target[index], uid);
				if (found) {
					return found;
				}
			}
		} else if (target.fields) {
			return Builder.findAndRemove(target.fields, uid);
		} else if (Array.isArray(target.options)) {
			for (const option of target.options) {
				const found = Builder.findAndRemove(option.fields, uid);
				if (found) {
					return found;
				}
			}
		}

		return null;
	};

	Builder.insertAt = function insertAt(
		list,
		item,
		targetUid,
		position = "after",
	) {
		if (!Array.isArray(list)) {
			return false;
		}

		const index = list.findIndex((entry) => entry._uid === targetUid);
		if (index > -1) {
			list.splice(position === "after" ? index + 1 : index, 0, item);
			return true;
		}

		for (const entry of list) {
			if (
				entry.fields &&
				Builder.insertAt(entry.fields, item, targetUid, position)
			) {
				return true;
			}

			if (Array.isArray(entry.options)) {
				for (const option of entry.options) {
					if (
						option &&
						Array.isArray(option.fields) &&
						Builder.insertAt(option.fields, item, targetUid, position)
					) {
						return true;
					}
				}
			}
		}

		return false;
	};
})();
