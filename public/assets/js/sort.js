// Drag-and-drop order of cards: <div data-sortable="<api action>"> with children [data-id].
// A child is dragged by itself ([draggable]) or by its [data-handle] (cards holding inputs).
// The new order is saved through api.php?a=<action> as soon as a card is dropped.
(function () {
  document.querySelectorAll('[data-sortable]').forEach(function (grid) {
    var dragged = null, before = '', action = grid.dataset.sortable || 'device_order';
    // handles: make the card draggable only while the handle is held, so its inputs stay usable
    grid.addEventListener('pointerdown', function (e) {
      var h = e.target.closest && e.target.closest('[data-handle]');
      if (h) h.closest('[data-id]').draggable = true;
    });
    grid.addEventListener('pointerup', function (e) {
      var h = e.target.closest && e.target.closest('[data-handle]');
      if (h) h.closest('[data-id]').draggable = false;
    });

    function order() {
      return Array.prototype.map.call(grid.querySelectorAll('[data-id]'), function (el) { return +el.dataset.id; });
    }
    function save() {
      var ids = order();
      if (ids.join() === before) return;
      TW.post('api.php?a=' + action, { ids: ids }).catch(function () {
        alert('The new order could not be saved.');
      });
    }

    grid.addEventListener('dragstart', function (e) {
      var card = e.target.closest && e.target.closest('[data-id]');
      if (!card || card.parentNode !== grid) return;
      dragged = card;
      before = order().join();
      card.classList.add('dragging');
      e.dataTransfer.effectAllowed = 'move';
      e.dataTransfer.setData('text/plain', card.dataset.id);
    });
    grid.addEventListener('dragover', function (e) {
      if (!dragged) return;
      e.preventDefault();
      var over = e.target.closest && e.target.closest('[data-id]');
      if (!over || over === dragged || over.parentNode !== grid) return;
      var r = over.getBoundingClientRect();
      // past the middle of the card: the right half in a multi-column grid, the lower half in one column
      var oneColumn = r.width > grid.clientWidth * 0.75;
      var after = oneColumn ? e.clientY > r.top + r.height / 2 : e.clientX > r.left + r.width / 2;
      grid.insertBefore(dragged, after ? over.nextSibling : over);
    });
    grid.addEventListener('drop', function (e) { if (dragged) e.preventDefault(); });
    grid.addEventListener('dragend', function () {
      if (!dragged) return;
      dragged.classList.remove('dragging');
      if (dragged.querySelector('[data-handle]')) dragged.draggable = false;
      dragged = null;
      save();
    });
  });
})();
