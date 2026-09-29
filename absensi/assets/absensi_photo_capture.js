/**
 * Absensi: normalisasi foto ke JPEG (data URL) untuk POST photo_data.
 * Mengatasi: multipart file tidak terkirim di beberapa mobile, HEIC/MIME aneh.
 */
(function () {
  'use strict';

  /**
   * @param {HTMLInputElement} input
   * @param {{photoDataId: string, previewId: string, camLabelId?: string, camLabelTextId?: string, maxSide?: number, quality?: number}} opts
   */
  window.absensiPhotoFromFile = function (input, opts) {
    if (!input || !input.files || !input.files[0]) return;
    var f = input.files[0];
    var photoDataEl = document.getElementById(opts.photoDataId);
    var preview = document.getElementById(opts.previewId);
    var maxSide = opts.maxSide || 1920;
    var quality = typeof opts.quality === 'number' ? opts.quality : 0.82;

    function setReady(dataUrl) {
      if (photoDataEl) photoDataEl.value = dataUrl;
      if (preview) {
        preview.src = dataUrl;
        preview.style.display = 'block';
      }
      if (opts.camLabelId) {
        var lbl = document.getElementById(opts.camLabelId);
        if (lbl) lbl.classList.add('taken');
      }
      if (opts.camLabelTextId) {
        var t = document.getElementById(opts.camLabelTextId);
        if (t) t.textContent = 'Foto siap — tap untuk ambil ulang';
      }
      if (input && input.hasAttribute('required')) input.removeAttribute('required');
    }

    function fail(msg) {
      window.alert(msg || 'Foto tidak bisa diproses. Coba ambil ulang.');
    }

    var url = URL.createObjectURL(f);
    var img = new Image();
    img.onload = function () {
      URL.revokeObjectURL(url);
      try {
        var w = img.naturalWidth || img.width;
        var h = img.naturalHeight || img.height;
        if (!w || !h) throw new Error('invalid dims');
        var tw = w;
        var th = h;
        if (w > maxSide) {
          tw = maxSide;
          th = Math.round(h * (maxSide / w));
        }
        if (th > maxSide) {
          th = maxSide;
          tw = Math.round(w * (maxSide / h));
        }
        var c = document.createElement('canvas');
        c.width = tw;
        c.height = th;
        var ctx = c.getContext('2d');
        if (!ctx) throw new Error('no ctx');
        ctx.drawImage(img, 0, 0, tw, th);
        setReady(c.toDataURL('image/jpeg', quality));
      } catch (e) {
        fallbackReader();
      }
    };
    img.onerror = function () {
      URL.revokeObjectURL(url);
      fallbackReader();
    };

    function fallbackReader() {
      var reader = new FileReader();
      reader.onload = function (e) {
        var r = e.target && e.target.result;
        if (typeof r === 'string' && r.indexOf('data:image') === 0) {
          if (/data:image\/(jpeg|jpg|png|webp)/i.test(r)) {
            setReady(r);
          } else {
            fail(
              'Format foto tidak didukung di browser ini (mis. HEIC). ' +
                'Di iPhone: Settings → Camera → Formats → pilih "Most Compatible", lalu ambil foto lagi.'
            );
          }
        } else {
          fail('Gagal membaca foto.');
        }
      };
      reader.onerror = function () {
        fail('Gagal membaca file foto.');
      };
      reader.readAsDataURL(f);
    };

    img.src = url;
  };

  /**
   * @param {Event} ev
   * @param {string} photoDataId
   * @param {string} fileInputId
   */
  window.absensiEnsurePhotoBeforeSubmit = function (ev, photoDataId, fileInputId) {
    var pdEl = document.getElementById(photoDataId);
    var pd = (pdEl && pdEl.value) ? pdEl.value : '';
    var inp = document.getElementById(fileInputId);
    var fl = inp && inp.files;
    if ((!pd || pd.length < 80) && (!fl || !fl.length)) {
      if (ev && ev.preventDefault) ev.preventDefault();
      window.alert('Ambil foto dulu sebelum submit.');
      return false;
    }
    return true;
  };
})();
