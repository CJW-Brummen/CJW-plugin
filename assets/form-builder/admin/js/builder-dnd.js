(() => {
	window.CJWBuilder = window.CJWBuilder || {};
	const Builder = window.CJWBuilder;

	Builder.wireDragHandlers = function wireDragHandlers(element, field, context) {
		element.addEventListener("dragstart", (event) => {
			event.dataTransfer.setData("text/plain", field._uid);
			element.classList.add("cjw-dragging");
			event.stopPropagation();
		});

		element.addEventListener("dragend", () => {
			element.classList.remove("cjw-dragging");
		});

		element.addEventListener("dragover", (event) => {
			event.preventDefault();
			event.stopPropagation();
			element.classList.add("cjw-drag-target");
		});

		element.addEventListener("dragleave", () => {
			element.classList.remove("cjw-drag-target");
		});

		element.addEventListener("drop", (event) => {
			event.preventDefault();
			event.stopPropagation();
			element.classList.remove("cjw-drag-target");

			const draggingUid = event.dataTransfer.getData("text/plain");
			if (draggingUid === field._uid) {
				return;
			}

			const movedItem = Builder.findAndRemove(context.config.steps, draggingUid);
			if (!movedItem) {
				return;
			}

			const rect = element.getBoundingClientRect();
			const position = event.clientY - rect.top > rect.height / 2 ? "after" : "before";
			Builder.insertAt(context.config.steps, movedItem, field._uid, position);
			context.render();
			context.syncData();
		});
	};

	Builder.wireDropZone = function wireDropZone(zone, getTargetList, context) {
		zone.addEventListener("dragover", (event) => {
			event.preventDefault();
			event.stopPropagation();
			zone.classList.add("cjw-drag-over");
		});

		zone.addEventListener("dragleave", () => {
			zone.classList.remove("cjw-drag-over");
		});

		zone.addEventListener("drop", (event) => {
			event.preventDefault();
			event.stopPropagation();
			zone.classList.remove("cjw-drag-over");

			if (event.target !== zone) {
				return;
			}

			const draggingUid = event.dataTransfer.getData("text/plain");
			const movedItem = Builder.findAndRemove(context.config.steps, draggingUid);
			if (!movedItem) {
				return;
			}

			getTargetList().push(movedItem);
			context.render();
			context.syncData();
		});
	};
})();
