// "Add another document" button for the document rows.
(function () {
  var box = document.getElementById('doc-rows');
  var add = document.getElementById('doc-add');
  var tpl = document.getElementById('doc-row-tpl');
  if (!box || !add || !tpl) { return; }
  var max = parseInt(box.getAttribute('data-max'), 10) || 10;

  function refresh() {
    add.disabled = box.querySelectorAll('.doc-row').length >= max;
  }

  add.addEventListener('click', function () {
    if (box.querySelectorAll('.doc-row').length >= max) { return; }
    var row = tpl.content.firstElementChild.cloneNode(true);
    row.querySelector('.doc-remove').addEventListener('click', function () {
      row.remove();
      refresh();
    });
    box.appendChild(row);
    refresh();
  });
  refresh();
})();
