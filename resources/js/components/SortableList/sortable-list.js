function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? "";
}

function sortableRows(list) {
    return Array.from(list.querySelectorAll("[data-sortable-row]"));
}

function sortableIds(list) {
    return sortableRows(list)
        .map((row) => row.dataset.sortableId)
        .filter((id) => id !== undefined && id !== "");
}

function restoreOrder(list, ids) {
    ids.forEach((id) => {
        const row = list.querySelector(`[data-sortable-row][data-sortable-id="${id}"]`);

        if (row) {
            list.append(row);
        }
    });
}

async function persistOrder(list, ids) {
    const url = list.dataset.sortableUrl;

    if (!url) return null;

    const payloadKey = list.dataset.sortablePayloadKey || "ids";
    const response = await fetch(url, {
        method: list.dataset.sortableMethod || "PUT",
        headers: {
            Accept: "application/json",
            "Content-Type": "application/json",
            "X-CSRF-TOKEN": csrfToken(),
            "X-Requested-With": "XMLHttpRequest",
        },
        body: JSON.stringify({ [payloadKey]: ids }),
    });
    const payload = await response.json().catch(() => ({}));

    if (!response.ok) {
        throw new Error(payload.message || "Could not save list order.");
    }

    return payload;
}

function moveRow(list, draggingRow, targetRow, clientY) {
    if (!draggingRow || !targetRow || draggingRow === targetRow) return;

    const box = targetRow.getBoundingClientRect();
    const isAfter = clientY > box.top + box.height / 2;

    if (isAfter) {
        targetRow.after(draggingRow);
        return;
    }

    targetRow.before(draggingRow);
}

function mountSortableList(list) {
    if (!list || list.dataset.sortableMounted === "true") return;

    list.dataset.sortableMounted = "true";
    let draggingRow = null;
    let initialOrder = [];

    list.addEventListener("dragstart", (event) => {
        const handle = event.target.closest("[data-sortable-handle]");

        if (!handle || !list.contains(handle)) return;

        const row = handle.closest("[data-sortable-row]");

        if (!row || !list.contains(row)) return;

        draggingRow = row;
        initialOrder = sortableIds(list);
        row.classList.add("opacity-60");
        event.dataTransfer?.setData("text/plain", row.dataset.sortableId || "");
        if (event.dataTransfer) {
            event.dataTransfer.effectAllowed = "move";
        }
    });

    list.addEventListener("dragover", (event) => {
        if (!draggingRow) return;

        const targetRow = event.target.closest("[data-sortable-row]");

        if (!targetRow || !list.contains(targetRow)) return;

        event.preventDefault();
        if (event.dataTransfer) {
            event.dataTransfer.dropEffect = "move";
        }

        moveRow(list, draggingRow, targetRow, event.clientY);
    });

    list.addEventListener("drop", async (event) => {
        if (!draggingRow) return;

        event.preventDefault();
        const finalOrder = sortableIds(list);
        const previousOrder = initialOrder;
        const changed = finalOrder.join("|") !== initialOrder.join("|");
        const detail = { ids: finalOrder, previousIds: previousOrder, list };

        draggingRow.classList.remove("opacity-60");
        draggingRow = null;
        initialOrder = [];

        if (!changed) return;

        list.dispatchEvent(new CustomEvent("sortable-list:changed", { bubbles: true, detail }));

        try {
            const payload = await persistOrder(list, finalOrder);
            list.dispatchEvent(new CustomEvent("sortable-list:saved", {
                bubbles: true,
                detail: { ...detail, payload },
            }));
        } catch (error) {
            restoreOrder(list, previousOrder);
            list.dispatchEvent(new CustomEvent("sortable-list:failed", {
                bubbles: true,
                detail: { ...detail, error },
            }));
        }
    });

    list.addEventListener("dragend", () => {
        draggingRow?.classList.remove("opacity-60");
        draggingRow = null;
        initialOrder = [];
    });
}

function mountSortableLists(scope = document) {
    scope
        .querySelectorAll("[data-sortable-list]")
        .forEach((list) => mountSortableList(list));
}

export { mountSortableList, mountSortableLists, sortableIds };
