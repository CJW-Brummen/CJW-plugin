/** @global flatpickr */

document.addEventListener("DOMContentLoaded", () => {
	// Datepicker
	if (typeof flatpickr !== "undefined") {
		flatpickr(".cjw-datepicker", {
			dateFormat: "d-m-Y",
			allowInput: true,
		});
	}

	// Repeater (date + label)
	document.querySelectorAll(".cjw-repeater").forEach((container) => {
		container.addEventListener("click", (e) => {
			const target = e.target;

			if (!(target instanceof HTMLElement)) {
				return;
			}

			if (target.classList.contains("cjw-add")) {
				const index = container.querySelectorAll(".cjw-row").length;
				const name = container.dataset.name;

				const row = document.createElement("div");
				row.classList.add("cjw-row");

				row.innerHTML = `
                    <input type="text" name="${name}[${index}][date]" class="regular-text cjw-datepicker" placeholder="Datum">
                    <input type="text" name="${name}[${index}][label]" class="regular-text" placeholder="Label">
                    <button type="button" class="button cjw-remove">Verwijder</button>
                `;

				container.insertBefore(row, target);

				if (typeof flatpickr !== "undefined") {
					flatpickr(row.querySelector(".cjw-datepicker"), {
						dateFormat: "d-m-Y",
						allowInput: true,
					});
				}
			}

			if (target.classList.contains("cjw-remove")) {
				target.closest(".cjw-row").remove();
			}
		});
	});

	// Media picker
	document.querySelectorAll("[data-cjw-media-field]").forEach((field) => {
		const input = field.querySelector("[data-cjw-media-input]");
		const preview = field.querySelector("[data-cjw-media-preview]");
		const selectButton = field.querySelector("[data-cjw-media-select]");
		const clearButton = field.querySelector("[data-cjw-media-clear]");
		const warning = field.querySelector("[data-cjw-media-warning]");

		if (
			!(input instanceof HTMLInputElement) ||
			!(preview instanceof HTMLElement) ||
			!(selectButton instanceof HTMLButtonElement) ||
			!(clearButton instanceof HTMLButtonElement) ||
			typeof wp === "undefined" ||
			typeof wp.media === "undefined"
		) {
			return;
		}

		let frame = null;

		const maxBytes = Number(field.dataset.maxBytes || 0);
		const recommendedWidth = Number(field.dataset.recommendedWidth || 0);
		const recommendedHeight = Number(field.dataset.recommendedHeight || 0);

		const formatBytes = (bytes) =>
			bytes >= 1048576
				? `${(bytes / 1048576).toFixed(1).replace(".", ",")} MB`
				: `${Math.round(bytes / 1024)} kB`;

		/*
		 * Said here rather than after saving, because that is where it can still
		 * be acted on: the media library is open, and choosing a different file
		 * is one click away.
		 */
		const describeProblem = (attachment) => {
			const bytes = Number(attachment.filesizeInBytes || 0);
			const width = Number(attachment.width || 0);

			const tooHeavy = maxBytes > 0 && bytes > maxBytes;
			const tooWide = recommendedWidth > 0 && width > recommendedWidth * 1.5;

			if (!tooHeavy && !tooWide) {
				return "";
			}

			const problem = tooHeavy
				? `Deze afbeelding is ${formatBytes(bytes)} groot.`
				: `Deze afbeelding is ${width} pixels breed.`;

			const advice =
				recommendedWidth > 0 && recommendedHeight > 0
					? `Maak de afbeelding kleiner: ongeveer ${recommendedWidth} bij ${recommendedHeight} pixels en hooguit ${formatBytes(maxBytes)}.`
					: `Maak de afbeelding kleiner: houd ongeveer ${formatBytes(maxBytes)} aan.`;

			return `${problem} Daardoor laadt de pagina traag, vooral op een telefoon met mobiel internet. ${advice}`;
		};

		const showWarning = (message) => {
			if (!(warning instanceof HTMLElement)) {
				return;
			}

			warning.textContent = message;
			warning.classList.toggle("is-hidden", message === "");
		};

		selectButton.addEventListener("click", (event) => {
			event.preventDefault();

			if (frame) {
				frame.open();
				return;
			}

			frame = wp.media({
				title: field.dataset.title || "Kies afbeelding",
				button: {
					text: field.dataset.button || "Gebruik deze afbeelding",
				},
				library: {
					type: "image",
				},
				multiple: false,
			});

			frame.on("select", () => {
				const attachment = frame.state().get("selection").first().toJSON();

				input.value = String(attachment.id || "");
				preview.innerHTML = "";

				const image = document.createElement("img");
				image.className = "cjw-media-field__image";
				image.src =
					attachment.sizes?.medium?.url ||
					attachment.sizes?.large?.url ||
					attachment.url;
				image.alt = attachment.alt || attachment.title || "";

				preview.appendChild(image);

				showWarning(describeProblem(attachment));
			});

			frame.open();
		});

		clearButton.addEventListener("click", (event) => {
			event.preventDefault();
			input.value = "";
			preview.innerHTML = "<span>Geen afbeelding gekozen</span>";
			showWarning("");
		});
	});

	// Copy generated links.
	document.querySelectorAll("[data-cjw-copy-value]").forEach((button) => {
		button.addEventListener("click", () => {
			const wrapper = button.closest(".cjw-generated-link-field");
			const source = wrapper?.querySelector("[data-cjw-copy-source]");

			if (!(source instanceof HTMLInputElement)) {
				return;
			}

			const originalText = button.textContent || "";

			navigator.clipboard.writeText(source.value).then(() => {
				button.textContent = "Gekopieerd";

				window.setTimeout(() => {
					button.textContent = originalText;
				}, 2000);
			});
		});
	});
});
