// Drag-and-drop order of the overview cards: <div data-sortable> with children [draggable][data-id].
// The new order is saved through api.php?a=device_order as soon as a card is dropped.
(function () {
  document.querySelectorAll('[data-sortable]').forEach(function (grid) {
    var dragged = null, before = '';

    function order() {
      return Array.prototype.map.call(grid.querySelectorAll('[data-id]'), function (el) { return +el.dataset.id; });
    }
    function save() {
      var ids = order();
      if (ids.join() === before) return;
      TW.post('api.php?a=device_order', { ids: ids }).catch(function () {
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
      dragged = null;
      save();
    });
  });
})();
