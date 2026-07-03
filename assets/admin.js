/* EthioTractors — admin panel behavior */
(function () {
  'use strict';

  /* ---------- Confirm dialogs for destructive forms ---------- */
  var dialog = document.getElementById('confirmDialog');
  var confirmText = document.getElementById('confirmText');
  var pendingForm = null;

  document.querySelectorAll('form[data-confirm]').forEach(function (form) {
    form.addEventListener('submit', function (ev) {
      if (form.dataset.confirmed === '1') return; // second pass, let it through
      ev.preventDefault();
      var msg = form.getAttribute('data-confirm') || 'Are you sure?';
      if (dialog && typeof dialog.showModal === 'function') {
        pendingForm = form;
        confirmText.textContent = msg;
        dialog.showModal();
      } else if (window.confirm(msg)) {
        form.dataset.confirmed = '1';
        form.submit();
      }
    });
  });

  if (dialog) {
    document.getElementById('confirmCancel').addEventListener('click', function () {
      pendingForm = null;
      dialog.close();
    });
    document.getElementById('confirmOk').addEventListener('click', function () {
      if (pendingForm) {
        pendingForm.dataset.confirmed = '1';
        pendingForm.submit();
      }
      dialog.close();
    });
    dialog.addEventListener('click', function (ev) {
      if (ev.target === dialog) { pendingForm = null; dialog.close(); }
    });
  }

  /* ---------- Mobile sidebar ---------- */
  var menuBtn = document.getElementById('adminMenuBtn');
  var sidebar = document.getElementById('sidebar');
  if (menuBtn && sidebar) {
    menuBtn.addEventListener('click', function () { sidebar.classList.toggle('open'); });
    document.addEventListener('click', function (ev) {
      if (sidebar.classList.contains('open') &&
          !sidebar.contains(ev.target) && !menuBtn.contains(ev.target)) {
        sidebar.classList.remove('open');
      }
    });
  }

  /* ---------- Product table quick filter ---------- */
  var prodSearch = document.getElementById('prodSearch');
  if (prodSearch) {
    var rows = document.querySelectorAll('#prodTable tbody tr[data-name]');
    prodSearch.addEventListener('input', function () {
      var q = prodSearch.value.trim().toLowerCase();
      rows.forEach(function (row) {
        row.style.display = !q || row.getAttribute('data-name').indexOf(q) !== -1 ? '' : 'none';
      });
    });
  }

  /* ---------- Product form: spec rows ---------- */
  var specRows = document.getElementById('specRows');
  var addSpec = document.getElementById('addSpec');

  function bindRemove(btn) {
    btn.addEventListener('click', function () { btn.closest('.spec-row').remove(); });
  }

  if (specRows && addSpec) {
    specRows.querySelectorAll('.spec-row .rm').forEach(bindRemove);
    addSpec.addEventListener('click', function () {
      var row = document.createElement('div');
      row.className = 'spec-row';
      row.innerHTML =
        '<input type="text" name="spec_k[]" maxlength="60" placeholder="Label e.g. Power req.">' +
        '<input type="text" name="spec_v[]" maxlength="100" placeholder="Value e.g. 45 – 130 hp">' +
        '<button type="button" class="rm" aria-label="Remove specification">×</button>';
      bindRemove(row.querySelector('.rm'));
      specRows.appendChild(row);
      row.querySelector('input').focus();
    });
  }

  /* ---------- Product form: live photo preview ---------- */
  var photoInput = document.getElementById('p-photo');
  var photoPreview = document.getElementById('photoPreview');
  var photoEmpty = document.getElementById('photoEmpty');
  var imageUrlInput = document.getElementById('p-image');
  var removePhoto = document.getElementById('removePhoto');

  function showPreview(src) {
    photoPreview.src = src;
    photoPreview.hidden = false;
    if (photoEmpty) photoEmpty.hidden = true;
  }

  function hidePreview() {
    photoPreview.removeAttribute('src');
    photoPreview.hidden = true;
    if (photoEmpty) photoEmpty.hidden = false;
  }

  if (photoInput && photoPreview) {
    photoInput.addEventListener('change', function () {
      var file = photoInput.files && photoInput.files[0];
      if (!file) return;
      var reader = new FileReader();
      reader.onload = function (ev) {
        showPreview(ev.target.result);
        if (removePhoto) removePhoto.checked = false;
      };
      reader.readAsDataURL(file);
    });

    // A broken link should fall back to the empty state, not a broken-image icon.
    photoPreview.addEventListener('error', function () {
      if (!photoPreview.hidden) hidePreview();
    });

    if (imageUrlInput) {
      imageUrlInput.addEventListener('change', function () {
        var url = imageUrlInput.value.trim();
        if (!url) { hidePreview(); return; }
        if (photoInput.files && photoInput.files.length) return; // uploaded file wins
        showPreview(url);
        if (removePhoto) removePhoto.checked = false;
      });
    }

    if (removePhoto) {
      removePhoto.addEventListener('change', function () {
        if (removePhoto.checked) {
          photoInput.value = '';
          if (imageUrlInput) imageUrlInput.value = '';
          hidePreview();
        }
      });
    }
  }

  /* ---------- Product form: live icon preview ---------- */
  var iconSelect = document.getElementById('p-icon');
  var iconPreview = document.getElementById('iconPreview');
  if (iconSelect && iconPreview && window.ET_ICONS) {
    iconSelect.addEventListener('change', function () {
      iconPreview.innerHTML = window.ET_ICONS[iconSelect.value] || '';
    });
  }

  /* ---------- Inquiry rows: expand/collapse ---------- */
  document.querySelectorAll('.inq-row').forEach(function (row) {
    function toggleRow() {
      var detail = document.getElementById('inq-' + row.getAttribute('data-inq'));
      if (!detail) return;
      var show = detail.hidden;
      document.querySelectorAll('.inq-detail').forEach(function (d) { d.hidden = true; });
      document.querySelectorAll('.inq-row').forEach(function (r) { r.setAttribute('aria-expanded', 'false'); });
      detail.hidden = !show;
      row.setAttribute('aria-expanded', show ? 'true' : 'false');
    }
    row.addEventListener('click', toggleRow);
    row.addEventListener('keydown', function (ev) {
      if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); toggleRow(); }
    });
  });
})();
