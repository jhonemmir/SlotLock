(function () {
    const slotSelector = '[data-slot]';
    const freeSlotSelector = '[data-slot][data-status="free"]';

    function setValue(id, value) {
        const element = document.getElementById(id);
        if (element) {
            element.value = value;
        }
    }

    function selectSlot(button) {
        if (!button) {
            return;
        }

        document.querySelectorAll(slotSelector).forEach((element) => {
            element.classList.remove('selected');
        });
        button.classList.add('selected');

        const resource = document.getElementById('resource-select')?.value;
        const start = button.dataset.start;
        const end = button.dataset.end;

        setValue('resource_id', resource);
        setValue('sim_resource_id', resource);
        setValue('start_at', start);
        setValue('sim_start_at', start);
        setValue('end_at', end);
        setValue('sim_end_at', end);

        const selected = document.getElementById('selected-slot');
        if (selected) {
            selected.textContent = `${button.dataset.label} · ${button.dataset.status}`;
        }

        const book = document.getElementById('book-button');
        if (book) {
            book.disabled = false;
        }

        const simulate = document.getElementById('sim-button');
        if (simulate) {
            simulate.disabled = false;
        }
    }

    function autoSelect() {
        const now = Date.now();
        const free = Array.from(document.querySelectorAll(freeSlotSelector))
            .find((element) => Date.parse(element.dataset.start) > now);

        if (free) {
            selectSlot(free);
            return;
        }

        const book = document.getElementById('book-button');
        if (book) {
            book.disabled = true;
        }

        const simulate = document.getElementById('sim-button');
        if (simulate) {
            simulate.disabled = true;
        }

        const selected = document.getElementById('selected-slot');
        if (selected) {
            selected.textContent = 'No hay slots libres para este día.';
        }
    }

    document.addEventListener('click', (event) => {
        const slot = event.target.closest(slotSelector);
        if (slot) {
            selectSlot(slot);
        }
    });

    document.body.addEventListener('htmx:afterSwap', () => {
        // La grilla puede haber cambiado (book/simulación/HTMX swap):
        // re-seleccionar el primer slot libre y actualizar los hidden inputs.
        if (document.querySelector(slotSelector)) {
            autoSelect();
        }
    });

    document.addEventListener('DOMContentLoaded', autoSelect);
})();
